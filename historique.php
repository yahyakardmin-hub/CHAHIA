<?php
/**
 * HISTORIQUE.PHP
 * Historique en LECTURE SEULE des saisies journalières et des factures.
 * - Admin        : voit l'historique de TOUS les blocs.
 * - Utilisateur  : voit UNIQUEMENT l'historique de SON bloc.
 * Volontairement, cette page ne propose AUCUNE action de suppression ni
 * pour l'admin ni pour l'utilisateur : l'historique est conservé tel quel.
 */
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'Historique';
$activePage = 'historique';
$user = utilisateurConnecte();

const PAR_PAGE = 25;

// -----------------------------------------------------------------
// Filtres communs : Bloc (admin uniquement) / Bâtiment / période
// -----------------------------------------------------------------
$blocIdFiltre = estAdmin() && isset($_GET['bloc_id']) && $_GET['bloc_id'] !== '' ? (int)$_GET['bloc_id'] : null;
$blocEffectif = estAdmin() ? $blocIdFiltre : (int)$user['bloc_id'];

$batimentIdFiltre = isset($_GET['batiment_id']) && $_GET['batiment_id'] !== '' ? (int)$_GET['batiment_id'] : null;

$dateDebut = $_GET['date_debut'] ?? '';
$dateFin   = $_GET['date_fin'] ?? '';

$blocsListe = estAdmin() ? $pdo->query("SELECT id, nom FROM blocs ORDER BY nom")->fetchAll() : [];

$nomBlocUtilisateur = '';
if (!estAdmin() && $blocEffectif) {
    $stmt = $pdo->prepare("SELECT nom FROM blocs WHERE id = ?");
    $stmt->execute([$blocEffectif]);
    $nomBlocUtilisateur = $stmt->fetchColumn() ?: '';
}

$batimentsListe = [];
if ($blocEffectif) {
    $stmtBat = $pdo->prepare("SELECT id, nom FROM batiments WHERE bloc_id = ? ORDER BY nom");
    $stmtBat->execute([$blocEffectif]);
    $batimentsListe = $stmtBat->fetchAll();
} elseif (estAdmin()) {
    $batimentsListe = $pdo->query("SELECT id, nom, bloc_id FROM batiments ORDER BY nom")->fetchAll();
}
// Un bâtiment filtré doit appartenir au bloc effectif (utilisateur), sinon on l'ignore.
if (!estAdmin() && $batimentIdFiltre && !in_array($batimentIdFiltre, array_column($batimentsListe, 'id'))) {
    $batimentIdFiltre = null;
}

// -----------------------------------------------------------------
// 1) HISTORIQUE DES SAISIES
// -----------------------------------------------------------------
$condS = [];
$paramsS = [];
if ($batimentIdFiltre) {
    $condS[] = "s.batiment_id = ?";
    $paramsS[] = $batimentIdFiltre;
} elseif ($blocEffectif) {
    $condS[] = "b.bloc_id = ?";
    $paramsS[] = $blocEffectif;
}
if ($dateDebut !== '') { $condS[] = "s.date_saisie >= ?"; $paramsS[] = $dateDebut; }
if ($dateFin   !== '') { $condS[] = "s.date_saisie <= ?"; $paramsS[] = $dateFin; }
$whereS = $condS ? ('WHERE ' . implode(' AND ', $condS)) : '';

$stmtCountS = $pdo->prepare("
    SELECT COUNT(*) FROM saisies s JOIN batiments b ON b.id = s.batiment_id JOIN blocs bl ON bl.id = b.bloc_id $whereS
");
$stmtCountS->execute($paramsS);
$totalSaisies = (int)$stmtCountS->fetchColumn();
$totalPagesS  = max(1, (int)ceil($totalSaisies / PAR_PAGE));
$pageS = max(1, min($totalPagesS, (int)($_GET['page_s'] ?? 1)));
$offsetS = ($pageS - 1) * PAR_PAGE;

$stmtS = $pdo->prepare("
    SELECT s.*, b.nom AS batiment_nom, bl.id AS bloc_id, bl.nom AS bloc_nom
    FROM saisies s
    JOIN batiments b ON b.id = s.batiment_id
    JOIN blocs bl ON bl.id = b.bloc_id
    $whereS
    ORDER BY s.date_saisie DESC, bl.nom, b.nom
    LIMIT " . PAR_PAGE . " OFFSET $offsetS
");
$stmtS->execute($paramsS);
$saisiesListe = $stmtS->fetchAll();

// -----------------------------------------------------------------
// 1bis) HISTORIQUE DES REMARQUES (réservé à l'administrateur)
//    Mêmes filtres (bloc / bâtiment / période) que l'historique des
//    saisies ci-dessus, mais uniquement les saisies dont le champ
//    "remarque" est renseigné.
// -----------------------------------------------------------------
$remarquesListe = [];
$totalRemarques = 0;
$totalPagesR = 1;
$pageR = 1;
if (estAdmin()) {
    $condR = ["s.remarque IS NOT NULL", "s.remarque <> ''"];
    $paramsR = [];
    if ($batimentIdFiltre) {
        $condR[] = "s.batiment_id = ?";
        $paramsR[] = $batimentIdFiltre;
    } elseif ($blocEffectif) {
        $condR[] = "b.bloc_id = ?";
        $paramsR[] = $blocEffectif;
    }
    if ($dateDebut !== '') { $condR[] = "s.date_saisie >= ?"; $paramsR[] = $dateDebut; }
    if ($dateFin   !== '') { $condR[] = "s.date_saisie <= ?"; $paramsR[] = $dateFin; }
    $whereR = 'WHERE ' . implode(' AND ', $condR);

    $stmtCountR = $pdo->prepare("
        SELECT COUNT(*) FROM saisies s JOIN batiments b ON b.id = s.batiment_id JOIN blocs bl ON bl.id = b.bloc_id $whereR
    ");
    $stmtCountR->execute($paramsR);
    $totalRemarques = (int)$stmtCountR->fetchColumn();
    $totalPagesR = max(1, (int)ceil($totalRemarques / PAR_PAGE));
    $pageR = max(1, min($totalPagesR, (int)($_GET['page_r'] ?? 1)));
    $offsetR = ($pageR - 1) * PAR_PAGE;

    $stmtR = $pdo->prepare("
        SELECT s.date_saisie, s.remarque, b.nom AS batiment_nom, bl.nom AS bloc_nom
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        $whereR
        ORDER BY s.date_saisie DESC, bl.nom, b.nom
        LIMIT " . PAR_PAGE . " OFFSET $offsetR
    ");
    $stmtR->execute($paramsR);
    $remarquesListe = $stmtR->fetchAll();
}

// -----------------------------------------------------------------
// 2) HISTORIQUE DES FACTURES
// -----------------------------------------------------------------
$condF = [];
$paramsF = [];
if (!estAdmin()) {
    $condF[] = "f.utilisateur_id = ?";
    $paramsF[] = $user['id'];
} elseif ($blocEffectif) {
    $condF[] = "f.bloc_id = ?";
    $paramsF[] = $blocEffectif;
}
if ($dateDebut !== '') { $condF[] = "f.date_facture >= ?"; $paramsF[] = $dateDebut; }
if ($dateFin   !== '') { $condF[] = "f.date_facture <= ?"; $paramsF[] = $dateFin; }
$whereF = $condF ? ('WHERE ' . implode(' AND ', $condF)) : '';

$stmtCountF = $pdo->prepare("SELECT COUNT(*) FROM factures f $whereF");
$stmtCountF->execute($paramsF);
$totalFactures = (int)$stmtCountF->fetchColumn();
$totalPagesF   = max(1, (int)ceil($totalFactures / PAR_PAGE));
$pageF = max(1, min($totalPagesF, (int)($_GET['page_f'] ?? 1)));
$offsetF = ($pageF - 1) * PAR_PAGE;

$stmtF = $pdo->prepare("
    SELECT f.*, u.nom_complet, bl.nom AS bloc_nom
    FROM factures f
    JOIN utilisateurs u ON u.id = f.utilisateur_id
    JOIN blocs bl ON bl.id = f.bloc_id
    $whereF
    ORDER BY f.date_facture DESC, f.id DESC
    LIMIT " . PAR_PAGE . " OFFSET $offsetF
");
$stmtF->execute($paramsF);
$facturesListe = $stmtF->fetchAll();

// -----------------------------------------------------------------
// 3) HISTORIQUE DES ALERTES (jamais de suppression possible)
// -----------------------------------------------------------------
$condA = [];
$paramsA = [];
if ($batimentIdFiltre) {
    $condA[] = "a.batiment_id = ?";
    $paramsA[] = $batimentIdFiltre;
} elseif ($blocEffectif) {
    $condA[] = "a.bloc_id = ?";
    $paramsA[] = $blocEffectif;
}
if ($dateDebut !== '') { $condA[] = "a.date_alerte >= ?"; $paramsA[] = $dateDebut; }
if ($dateFin   !== '') { $condA[] = "a.date_alerte <= ?"; $paramsA[] = $dateFin; }
$typeAlerteFiltre = $_GET['type_alerte'] ?? '';
if ($typeAlerteFiltre !== '') { $condA[] = "a.type_alerte = ?"; $paramsA[] = $typeAlerteFiltre; }
$whereA = $condA ? ('WHERE ' . implode(' AND ', $condA)) : '';

$stmtCountA = $pdo->prepare("SELECT COUNT(*) FROM alertes a $whereA");
$stmtCountA->execute($paramsA);
$totalAlertes = (int)$stmtCountA->fetchColumn();
$totalPagesA  = max(1, (int)ceil($totalAlertes / PAR_PAGE));
$pageA = max(1, min($totalPagesA, (int)($_GET['page_a'] ?? 1)));
$offsetA = ($pageA - 1) * PAR_PAGE;

$stmtA = $pdo->prepare("
    SELECT a.*, bl.nom AS bloc_nom, b.nom AS batiment_nom
    FROM alertes a
    JOIN blocs bl ON bl.id = a.bloc_id
    LEFT JOIN batiments b ON b.id = a.batiment_id
    $whereA
    ORDER BY a.date_creation DESC
    LIMIT " . PAR_PAGE . " OFFSET $offsetA
");
$stmtA->execute($paramsA);
$alertesListe = $stmtA->fetchAll();

/** Reconstruit l'URL courante avec un paramètre GET modifié (pour la pagination / filtres) */
function urlHistorique(array $override): string
{
    $params = array_merge($_GET, $override);
    foreach ($params as $k => $v) { if ($v === '' || $v === null) unset($params[$k]); }
    return 'historique.php?' . http_build_query($params);
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1>Historique<?= estAdmin() ? '' : ' — ' . htmlspecialchars($nomBlocUtilisateur) ?></h1>
</div>

<p class="hint">Historique en lecture seule : aucune saisie, facture ou alerte ne peut être supprimée depuis cette page, ni par un administrateur ni par un utilisateur.</p>

<form method="get" class="date-filter">
    <?php if (estAdmin()): ?>
    <label>Bloc</label>
    <select name="bloc_id" onchange="this.form.submit()">
        <option value="">Tous les blocs</option>
        <?php foreach ($blocsListe as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $blocIdFiltre === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['nom']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <label>Bâtiment</label>
    <select name="batiment_id" onchange="this.form.submit()" <?= $batimentsListe ? '' : 'disabled' ?>>
        <option value="">Tous les bâtiments</option>
        <?php foreach ($batimentsListe as $bt): ?>
            <option value="<?= $bt['id'] ?>" <?= $batimentIdFiltre === (int)$bt['id'] ? 'selected' : '' ?>><?= htmlspecialchars($bt['nom']) ?></option>
        <?php endforeach; ?>
    </select>

    <label>Du</label>
    <input type="date" name="date_debut" value="<?= htmlspecialchars($dateDebut) ?>" onchange="this.form.submit()">

    <label>Au</label>
    <input type="date" name="date_fin" value="<?= htmlspecialchars($dateFin) ?>" onchange="this.form.submit()">

    <label>Type d'alerte</label>
    <select name="type_alerte" onchange="this.form.submit()">
        <option value="">Tous</option>
        <option value="mortalite" <?= $typeAlerteFiltre === 'mortalite' ? 'selected' : '' ?>>Mortalité élevée</option>
        <option value="ponte" <?= $typeAlerteFiltre === 'ponte' ? 'selected' : '' ?>>Chute du taux de ponte</option>
        <option value="absence_saisie" <?= $typeAlerteFiltre === 'absence_saisie' ? 'selected' : '' ?>>Absence de saisie</option>
    </select>

    <?php if ($dateDebut !== '' || $dateFin !== '' || $batimentIdFiltre || $blocIdFiltre || $typeAlerteFiltre !== ''): ?>
        <a href="historique.php" class="btn-saisie">Réinitialiser</a>
    <?php endif; ?>
</form>

<h2 class="section-title">Historique des saisies (<?= fmtNum($totalSaisies) ?>)</h2>
<div class="table-wrapper">
<table class="tdb">
<thead>
<tr>
    <th>Date</th><th>Bloc</th><th>Bâtiment</th>
    <th>Effectif présent</th><th>Production</th><th>Mortalité</th>
    <th>Conso. eau (L)</th><th>Taux ponte obj. (%)</th><th>Remarque</th>
</tr>
</thead>
<tbody>
<?php foreach ($saisiesListe as $s): ?>
    <tr>
        <td><?= date('d/m/Y', strtotime($s['date_saisie'])) ?></td>
        <td><?= htmlspecialchars($s['bloc_nom']) ?></td>
        <td><?= htmlspecialchars($s['batiment_nom']) ?></td>
        <td class="num"><?= fmtNum($s['effectif_present']) ?></td>
        <td class="num"><?= fmtNum($s['production_totale']) ?></td>
        <td class="num"><?= fmtNum($s['mortalite']) ?></td>
        <td class="num"><?= fmtNum($s['consommation_eau']) ?></td>
        <td class="num"><?= fmtPct($s['taux_ponte_objectif']) ?></td>
        <td><?= htmlspecialchars($s['remarque'] ?? '') ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$saisiesListe): ?>
    <tr><td colspan="9" style="text-align:center;color:#888;">Aucune saisie pour ces critères.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($totalPagesS > 1): ?>
<div class="pagination">
    <?php if ($pageS > 1): ?><a class="btn-saisie" href="<?= urlHistorique(['page_s' => $pageS - 1]) ?>">← Précédent</a><?php endif; ?>
    <span class="hint">Page <?= $pageS ?> / <?= $totalPagesS ?></span>
    <?php if ($pageS < $totalPagesS): ?><a class="btn-saisie" href="<?= urlHistorique(['page_s' => $pageS + 1]) ?>">Suivant →</a><?php endif; ?>
</div>
<?php endif; ?>

<?php if (estAdmin()): ?>
<h2 class="section-title">Historique des remarques (<?= fmtNum($totalRemarques) ?>)</h2>
<div class="table-wrapper">
<table class="tdb">
<thead>
<tr>
    <th>Date</th><th>Bloc</th><th>Bâtiment</th><th>Remarque</th>
</tr>
</thead>
<tbody>
<?php foreach ($remarquesListe as $r): ?>
    <tr>
        <td><?= date('d/m/Y', strtotime($r['date_saisie'])) ?></td>
        <td><?= htmlspecialchars($r['bloc_nom']) ?></td>
        <td><?= htmlspecialchars($r['batiment_nom']) ?></td>
        <td><?= htmlspecialchars($r['remarque']) ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$remarquesListe): ?>
    <tr><td colspan="4" style="text-align:center;color:#888;">Aucune remarque pour ces critères.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($totalPagesR > 1): ?>
<div class="pagination">
    <?php if ($pageR > 1): ?><a class="btn-saisie" href="<?= urlHistorique(['page_r' => $pageR - 1]) ?>">← Précédent</a><?php endif; ?>
    <span class="hint">Page <?= $pageR ?> / <?= $totalPagesR ?></span>
    <?php if ($pageR < $totalPagesR): ?><a class="btn-saisie" href="<?= urlHistorique(['page_r' => $pageR + 1]) ?>">Suivant →</a><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<h2 class="section-title">Historique des factures (<?= fmtNum($totalFactures) ?>)</h2>
<div class="table-wrapper">
<table class="tdb">
<thead>
<tr>
    <th>N° Facture</th><th>Date</th>
    <?php if (estAdmin()): ?><th>Utilisateur</th><?php endif; ?>
    <th>Bloc</th><th>Quantité</th><th>Prix unitaire</th><th>Montant</th><th>Statut</th>
</tr>
</thead>
<tbody>
<?php foreach ($facturesListe as $f): ?>
    <tr>
        <td><?= htmlspecialchars($f['numero_facture']) ?></td>
        <td><?= date('d/m/Y', strtotime($f['date_facture'])) ?></td>
        <?php if (estAdmin()): ?><td><?= htmlspecialchars($f['nom_complet']) ?></td><?php endif; ?>
        <td><?= htmlspecialchars($f['bloc_nom']) ?></td>
        <td class="num"><?= fmtNum($f['quantite']) ?></td>
        <td class="num"><?= number_format($f['prix_unitaire'], 3, ',', ' ') ?> DT</td>
        <td class="num highlight"><?= fmtMontant($f['montant_total']) ?></td>
        <td><span class="badge badge-<?= $f['statut'] ?>"><?= ucfirst(str_replace('_', ' ', $f['statut'])) ?></span></td>
    </tr>
<?php endforeach; ?>
<?php if (!$facturesListe): ?>
    <tr><td colspan="<?= estAdmin() ? 8 : 7 ?>" style="text-align:center;color:#888;">Aucune facture pour ces critères.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($totalPagesF > 1): ?>
<div class="pagination">
    <?php if ($pageF > 1): ?><a class="btn-saisie" href="<?= urlHistorique(['page_f' => $pageF - 1]) ?>">← Précédent</a><?php endif; ?>
    <span class="hint">Page <?= $pageF ?> / <?= $totalPagesF ?></span>
    <?php if ($pageF < $totalPagesF): ?><a class="btn-saisie" href="<?= urlHistorique(['page_f' => $pageF + 1]) ?>">Suivant →</a><?php endif; ?>
</div>
<?php endif; ?>

<h2 class="section-title" id="alertes">Historique des alertes (<?= fmtNum($totalAlertes) ?>)</h2>
<div class="table-wrapper">
<table class="tdb">
<thead>
<tr>
    <th>Date</th><th>Type</th><th>Bloc</th><th>Bâtiment</th><th>Message</th>
</tr>
</thead>
<tbody>
<?php foreach ($alertesListe as $a): ?>
    <tr>
        <td><?= date('d/m/Y H:i', strtotime($a['date_creation'])) ?></td>
        <td><span class="badge-alerte badge-alerte-<?= $a['type_alerte'] ?>"><?= htmlspecialchars(libelleTypeAlerte($a['type_alerte'])) ?></span></td>
        <td><?= htmlspecialchars($a['bloc_nom']) ?></td>
        <td><?= htmlspecialchars($a['batiment_nom'] ?? '—') ?></td>
        <td><?= htmlspecialchars($a['message']) ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$alertesListe): ?>
    <tr><td colspan="5" style="text-align:center;color:#888;">Aucune alerte pour ces critères.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($totalPagesA > 1): ?>
<div class="pagination">
    <?php if ($pageA > 1): ?><a class="btn-saisie" href="<?= urlHistorique(['page_a' => $pageA - 1]) ?>">← Précédent</a><?php endif; ?>
    <span class="hint">Page <?= $pageA ?> / <?= $totalPagesA ?></span>
    <?php if ($pageA < $totalPagesA): ?><a class="btn-saisie" href="<?= urlHistorique(['page_a' => $pageA + 1]) ?>">Suivant →</a><?php endif; ?>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
