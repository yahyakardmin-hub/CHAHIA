<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'Saisie journalière';
$activePage = 'tdb';

$message = '';
$erreur  = '';

// -----------------------------------------------------------------
// Traitement du formulaire (POST direct, sans API séparée)
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batimentId = (int)($_POST['batiment_id'] ?? 0);
    $dateSaisie = $_POST['date_saisie'] ?? '';

    $requis = ['batiment_id', 'date_saisie', 'effectif_present', 'taux_ponte_objectif', 'production_totale', 'consommation_eau', 'mortalite'];
    $ok = true;
    foreach ($requis as $champ) {
        if (!isset($_POST[$champ]) || $_POST[$champ] === '') { $ok = false; }
    }

    if (!$ok) {
        $erreur = "Merci de remplir tous les champs obligatoires (*).";
    } else {
        try {
            $sql = "
                INSERT INTO saisies (
                    batiment_id, date_saisie, effectif_present, taux_ponte_objectif,
                    production_totale, consommation_eau, consommation_aliment, consommation_electricite,
                    mortalite, reforme_tri, pc_pct, sale_pct, casse_vendue_pct, casse_non_vendue_pct,
                    temp_min, temp_max, humidite, nombre_ouvriers, poids_moyen_oeuf, consommation_alveole, remarque
                ) VALUES (
                    :batiment_id, :date_saisie, :effectif_present, :taux_ponte_objectif,
                    :production_totale, :consommation_eau, :consommation_aliment, :consommation_electricite,
                    :mortalite, :reforme_tri, :pc_pct, :sale_pct, :casse_vendue_pct, :casse_non_vendue_pct,
                    :temp_min, :temp_max, :humidite, :nombre_ouvriers, :poids_moyen_oeuf, :consommation_alveole, :remarque
                )
                ON DUPLICATE KEY UPDATE
                    effectif_present = VALUES(effectif_present),
                    taux_ponte_objectif = VALUES(taux_ponte_objectif),
                    production_totale = VALUES(production_totale),
                    consommation_eau = VALUES(consommation_eau),
                    consommation_aliment = VALUES(consommation_aliment),
                    consommation_electricite = VALUES(consommation_electricite),
                    mortalite = VALUES(mortalite),
                    reforme_tri = VALUES(reforme_tri),
                    pc_pct = VALUES(pc_pct),
                    sale_pct = VALUES(sale_pct),
                    casse_vendue_pct = VALUES(casse_vendue_pct),
                    casse_non_vendue_pct = VALUES(casse_non_vendue_pct),
                    temp_min = VALUES(temp_min),
                    temp_max = VALUES(temp_max),
                    humidite = VALUES(humidite),
                    nombre_ouvriers = VALUES(nombre_ouvriers),
                    poids_moyen_oeuf = VALUES(poids_moyen_oeuf),
                    consommation_alveole = VALUES(consommation_alveole),
                    remarque = VALUES(remarque)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'batiment_id' => $batimentId,
                'date_saisie' => $dateSaisie,
                'effectif_present' => $_POST['effectif_present'],
                'taux_ponte_objectif' => $_POST['taux_ponte_objectif'],
                'production_totale' => $_POST['production_totale'],
                'consommation_eau' => $_POST['consommation_eau'],
                'consommation_aliment' => $_POST['consommation_aliment'] ?? 0,
                'consommation_electricite' => $_POST['consommation_electricite'] ?? 0,
                'mortalite' => $_POST['mortalite'],
                'reforme_tri' => $_POST['reforme_tri'] ?? 0,
                'pc_pct' => $_POST['pc_pct'] ?? 0,
                'sale_pct' => $_POST['sale_pct'] ?? 0,
                'casse_vendue_pct' => $_POST['casse_vendue_pct'] ?? 0,
                'casse_non_vendue_pct' => $_POST['casse_non_vendue_pct'] ?? 0,
                'temp_min' => $_POST['temp_min'] !== '' ? $_POST['temp_min'] : null,
                'temp_max' => $_POST['temp_max'] !== '' ? $_POST['temp_max'] : null,
                'humidite' => $_POST['humidite'] !== '' ? $_POST['humidite'] : null,
                'nombre_ouvriers' => $_POST['nombre_ouvriers'] ?? 0,
                'poids_moyen_oeuf' => $_POST['poids_moyen_oeuf'] !== '' ? $_POST['poids_moyen_oeuf'] : null,
                'consommation_alveole' => $_POST['consommation_alveole'] ?? 0,
                'remarque' => $_POST['remarque'] ?? null,
            ]);

            $saisieId = $pdo->lastInsertId();
            if (!$saisieId) {
                // Cas UPDATE : on retrouve l'id
                $st = $pdo->prepare("SELECT id FROM saisies WHERE batiment_id=? AND date_saisie=?");
                $st->execute([$batimentId, $dateSaisie]);
                $saisieId = $st->fetchColumn();
            }

            // Détail du tri (rubriques optionnelles OBV/ODJ/OJOV/OS...)
            if (!empty($_POST['detail_code']) && is_array($_POST['detail_code'])) {
                $stmtDet = $pdo->prepare("
                    INSERT INTO saisie_valeurs (saisie_id, code, valeur) VALUES (:sid, :code, :val)
                    ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)
                ");
                foreach ($_POST['detail_code'] as $i => $code) {
                    $val = $_POST['detail_valeur'][$i] ?? '';
                    if ($val === '' || $code === '') continue;
                    $stmtDet->execute(['sid' => $saisieId, 'code' => $code, 'val' => $val]);
                }
            }

            // Vérifie les seuils d'alerte (mortalité, chute du taux de ponte)
            // définis par l'admin, et notifie/historise si dépassés.
            verifierAlertesSaisie($pdo, $batimentId, $dateSaisie);

            header('Location: TDB.php?date=' . urlencode($dateSaisie) . '&saisie=ok');
            exit;
        } catch (PDOException $e) {
            $erreur = "Erreur lors de l'enregistrement : " . $e->getMessage();
        }
    }
}

// -----------------------------------------------------------------
// Données pour le formulaire : hiérarchie Bloc > Bâtiment + saisies
// existantes injectées en JSON -> plus besoin d'API, tout est filtré
// côté JS. Un utilisateur non-admin ne voit que SON bloc.
// -----------------------------------------------------------------
$user = utilisateurConnecte();
if (estAdmin()) {
    $hierarchie = $pdo->query("
        SELECT bl.id AS bloc_id, bl.nom AS bloc_nom,
               b.id AS batiment_id, b.nom AS batiment_nom, b.effectif_depart
        FROM blocs bl
        JOIN batiments b ON b.bloc_id = bl.id
        ORDER BY bl.nom, b.nom
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT bl.id AS bloc_id, bl.nom AS bloc_nom,
               b.id AS batiment_id, b.nom AS batiment_nom, b.effectif_depart
        FROM blocs bl
        JOIN batiments b ON b.bloc_id = bl.id
        WHERE bl.id = ?
        ORDER BY b.nom
    ");
    $stmt->execute([(int)$user['bloc_id']]);
    $hierarchie = $stmt->fetchAll();
}

$toutesSaisies = $pdo->query("SELECT * FROM saisies")->fetchAll();
// indexées par "batiment_id|date" pour un accès direct en JS
$saisiesIndex = [];
foreach ($toutesSaisies as $s) {
    $saisiesIndex[$s['batiment_id'] . '|' . $s['date_saisie']] = $s;
}

$rubriquesTri = $pdo->query("SELECT code, libelle_fr, libelle_ar FROM parametres_suivi ORDER BY code")->fetchAll();

$batimentIdPreselect = $_GET['batiment_id'] ?? '';
$datePreselect       = $_GET['date'] ?? date('Y-m-d');

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1>Saisie journalière - Tableau de bord Pondeuse</h1>
    <a href="TDB.php" class="btn-saisie">← Retour au tableau de bord</a>
</div>

<div class="form-container">
<?php if ($erreur): ?><div id="message" class="error"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<form method="post" id="formSaisie">
    <input type="hidden" id="batiment_id_preselect" value="<?= htmlspecialchars($batimentIdPreselect) ?>">
    <div class="form-grid">

        <div class="form-group">
            <label for="bloc_id">Bloc</label>
            <select id="bloc_id" required <?= estAdmin() ? '' : (count($hierarchie) ? '' : 'disabled') ?>>
                <option value="">-- Choisir --</option>
                <?php foreach (array_unique(array_column($hierarchie,'bloc_id')) as $bid):
                    $nom = current(array_filter($hierarchie, fn($h) => $h['bloc_id']==$bid))['bloc_nom']; ?>
                    <option value="<?= $bid ?>"><?= htmlspecialchars($nom) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="batiment_id">Bâtiment</label>
            <select id="batiment_id" name="batiment_id" required disabled>
                <option value="">-- Choisir le bloc d'abord --</option>
            </select>
        </div>

        <div class="form-group">
            <label for="date_saisie">Date de saisie</label>
            <input type="date" id="date_saisie" name="date_saisie" value="<?= htmlspecialchars($datePreselect) ?>" required>
        </div>

        <div class="form-group">
            <label for="effectif_depart_aff">Effectif Départ (info)</label>
            <input type="text" id="effectif_depart_aff" readonly>
        </div>

        <div class="form-group">
            <label for="effectif_present">Effectif Présent *</label>
            <input type="number" id="effectif_present" name="effectif_present" min="0" required>
        </div>

        <div class="form-group">
            <label for="taux_ponte_objectif">Taux De Ponte Objectif (%) *</label>
            <input type="number" id="taux_ponte_objectif" name="taux_ponte_objectif" step="0.01" min="0" max="100" required>
        </div>

        <div class="form-group">
            <label for="production_totale">Production Totale J (nb d'œufs) *</label>
            <input type="number" id="production_totale" name="production_totale" min="0" required>
        </div>

        <div class="form-group">
            <label for="consommation_eau">Consommation d'eau J (L) *</label>
            <input type="number" id="consommation_eau" name="consommation_eau" step="0.01" min="0" required>
        </div>

        <div class="form-group">
            <label for="consommation_aliment">Consommation aliment (Kg)</label>
            <input type="number" id="consommation_aliment" name="consommation_aliment" step="0.01" min="0" value="0">
        </div>

        <div class="form-group">
            <label for="consommation_electricite">Consommation électricité (Kw)</label>
            <input type="number" id="consommation_electricite" name="consommation_electricite" step="0.01" min="0" value="0">
        </div>

        <div class="form-group">
            <label for="mortalite">Mortalité Jour *</label>
            <input type="number" id="mortalite" name="mortalite" min="0" required>
        </div>

        <div class="form-group">
            <label for="reforme_tri">Réforme + Tri</label>
            <input type="number" id="reforme_tri" name="reforme_tri" min="0" value="0">
        </div>

        <div class="form-group">
            <label for="nombre_ouvriers">Nombre d'ouvriers</label>
            <input type="number" id="nombre_ouvriers" name="nombre_ouvriers" min="0" value="0">
        </div>

        <div class="form-group">
            <label for="poids_moyen_oeuf">Poids moyen oeuf (g)</label>
            <input type="number" id="poids_moyen_oeuf" name="poids_moyen_oeuf" step="0.01" min="0">
        </div>

        <div class="form-group">
            <label for="consommation_alveole">Consommation alvéole (U)</label>
            <input type="number" id="consommation_alveole" name="consommation_alveole" min="0" value="0">
        </div>

        <div class="form-group">
            <label for="pc_pct">PC (%)</label>
            <input type="number" id="pc_pct" name="pc_pct" step="0.01" min="0" max="100" value="0">
        </div>

        <div class="form-group">
            <label for="sale_pct">Sale (%)</label>
            <input type="number" id="sale_pct" name="sale_pct" step="0.01" min="0" max="100" value="0">
        </div>

        <div class="form-group">
            <label for="casse_vendue_pct">Casse Vendu (%)</label>
            <input type="number" id="casse_vendue_pct" name="casse_vendue_pct" step="0.01" min="0" max="100" value="0">
        </div>

        <div class="form-group">
            <label for="casse_non_vendue_pct">Casse Non Vendu (%)</label>
            <input type="number" id="casse_non_vendue_pct" name="casse_non_vendue_pct" step="0.01" min="0" max="100" value="0">
        </div>

        <div class="form-group">
            <label for="temp_min">Température MIN (°C)</label>
            <input type="number" id="temp_min" name="temp_min" step="0.1">
        </div>

        <div class="form-group">
            <label for="temp_max">Température MAX (°C)</label>
            <input type="number" id="temp_max" name="temp_max" step="0.1">
        </div>

        <div class="form-group">
            <label for="humidite">Humidité (%)</label>
            <input type="number" id="humidite" name="humidite" step="0.1">
        </div>

        <div class="form-group full">
            <label for="remarque">Remarque</label>
            <input type="text" id="remarque" name="remarque" placeholder="ex: Traitement de bâtiment, sur collecte J-1...">
        </div>
    </div>

    <details class="detail-tri">
        <summary>Détail du tri des oeufs (facultatif — rubriques OBV/ODJ/OJOV/OS...)</summary>
        <div class="form-grid detail-tri-grid">
            <?php foreach ($rubriquesTri as $r): ?>
                <div class="form-group">
                    <label title="<?= htmlspecialchars($r['libelle_ar'] ?? '') ?>">
                        <?= htmlspecialchars($r['code']) ?> — <?= htmlspecialchars($r['libelle_fr']) ?>
                    </label>
                    <input type="hidden" name="detail_code[]" value="<?= htmlspecialchars($r['code']) ?>">
                    <input type="number" name="detail_valeur[]" min="0" class="detail-valeur" data-code="<?= htmlspecialchars($r['code']) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </details>

    <div class="apercu-box" id="apercu">
        <div>Taux de ponte réalisé J : <span id="apercu_taux">0,00 %</span></div>
        <div>Écart J/J-1 : <span id="apercu_ecart_jj1">0,00 %</span></div>
        <div>Écart Réalisé/Objectif : <span id="apercu_ecart_ro">0,00 %</span></div>
        <div>% Tri global : <span id="apercu_pct_tri">0,00 %</span></div>
        <div>Écart mortalité (J - J-1) : <span id="apercu_ecart_mortalite">0</span></div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn-submit">Enregistrer la saisie</button>
        <a href="TDB.php" class="btn-annuler">Annuler</a>
    </div>
</form>
</div>

<script>
    // Hiérarchie + saisies injectées en JSON par PHP (remplace les appels API)
    window.HIERARCHIE_DATA = <?= json_encode($hierarchie, JSON_UNESCAPED_UNICODE) ?>;
    window.SAISIES_INDEX   = <?= json_encode($saisiesIndex, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/script.js"></script>

<?php require_once 'includes/footer.php'; ?>