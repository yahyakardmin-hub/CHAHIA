<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'Statistiques simples';
$activePage = 'stats_simple';
$user = utilisateurConnecte();

$dateJour = $_GET['date'] ?? getDerniereDateSaisie($pdo, estAdmin() ? null : (int)$user['bloc_id']);

// -----------------------------------------------------------------
// Filtre Bloc / Bâtiment.
// - Admin : peut choisir n'importe quel bloc (ou "Tous"), puis un
//   bâtiment de ce bloc (ou "Tous les bâtiments").
// - Utilisateur (ouvrier) : son bloc est fixe (le sien) ; il peut en
//   plus choisir un bâtiment précis de son bloc, ou "Tous".
// -----------------------------------------------------------------
$blocIdFiltre = estAdmin() && isset($_GET['bloc_id']) && $_GET['bloc_id'] !== '' ? (int)$_GET['bloc_id'] : null;
$blocEffectif = estAdmin() ? $blocIdFiltre : (int)$user['bloc_id'];
$batimentIdFiltre = isset($_GET['batiment_id']) && $_GET['batiment_id'] !== '' ? (int)$_GET['batiment_id'] : null;

$blocsListe = estAdmin() ? getBlocsAvecStats($pdo) : [];
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
}
// Un bâtiment filtré doit appartenir au bloc effectif, sinon on l'ignore.
if ($batimentIdFiltre && !in_array($batimentIdFiltre, array_column($batimentsListe, 'id'))) {
    $batimentIdFiltre = null;
}

/**
 * Construit dynamiquement la clause WHERE + les paramètres pour filtrer les
 * saisies selon le bloc/bâtiment effectifs ci-dessus.
 */
function clauseFiltreBlocBatiment(?int $blocEffectif, ?int $batimentIdFiltre): array
{
    $sql = " JOIN batiments b ON b.id = s.batiment_id ";
    $conditions = [];
    $params = [];
    if ($batimentIdFiltre) {
        $conditions[] = "s.batiment_id = ?";
        $params[] = $batimentIdFiltre;
    } elseif ($blocEffectif) {
        $conditions[] = "b.bloc_id = ?";
        $params[] = $blocEffectif;
    }
    return [$sql, $conditions, $params];
}

// -----------------------------------------------------------------
// Chiffres simples du jour (et cumul du mois en cours), filtrés par
// bloc/bâtiment ci-dessus.
// -----------------------------------------------------------------
[$joinSql, $conditions, $paramsBase] = clauseFiltreBlocBatiment($blocEffectif, $batimentIdFiltre);

$whereJour = array_merge(["s.date_saisie = ?"], $conditions);
$stmtJour = $pdo->prepare("SELECT s.* FROM saisies s" . $joinSql . " WHERE " . implode(' AND ', $whereJour));
$stmtJour->execute(array_merge([$dateJour], $paramsBase));
$saisiesJour = $stmtJour->fetchAll();

$moisCourant = substr($dateJour, 0, 7);
$whereMois = array_merge(["s.date_saisie LIKE ?"], $conditions);
$stmtMois = $pdo->prepare("SELECT s.* FROM saisies s" . $joinSql . " WHERE " . implode(' AND ', $whereMois));
$stmtMois->execute(array_merge([$moisCourant . '%'], $paramsBase));
$saisiesMois = $stmtMois->fetchAll();

function sommeColonne(array $lignes, string $col): float {
    return array_sum(array_map(fn($l) => (float)($l[$col] ?? 0), $lignes));
}

$productionJour  = sommeColonne($saisiesJour, 'production_totale');
$mortaliteJour   = sommeColonne($saisiesJour, 'mortalite');
$effectifJour    = sommeColonne($saisiesJour, 'effectif_present');
$tauxPonteJour   = $effectifJour > 0 ? ($productionJour / $effectifJour) * 100 : 0;

$productionMois  = sommeColonne($saisiesMois, 'production_totale');
$mortaliteMois   = sommeColonne($saisiesMois, 'mortalite');

// Le stock est toujours un concept "par bloc" (les commandes/factures sont
// enregistrées au niveau du bloc, pas du bâtiment) : le filtre bâtiment ne
// s'applique donc pas ici, seul le filtre bloc (ou "son bloc" pour un
// utilisateur) est pris en compte.
if ($blocEffectif) {
    $stockBlocs = [getStockBloc($pdo, $blocEffectif)];
} else {
    $stockBlocs = estAdmin() ? getStockTousBlocs($pdo) : [];
}
$stockTotal = array_sum(array_column($stockBlocs, 'stock_disponible'));

// -----------------------------------------------------------------
// COMPARATIF entre blocs (admin) ou entre bâtiments (admin ou
// utilisateur, restreint à son propre bloc) : une variable choisie,
// sur une période choisie (dernier jour / mois / année / dates fixes).
// -----------------------------------------------------------------
$compNiveau  = ($_GET['comp_niveau'] ?? 'bloc') === 'batiment' ? 'batiment' : 'bloc';
if (!estAdmin()) { $compNiveau = 'batiment'; } // un ouvrier ne voit qu'un seul bloc : comparer par bâtiment est le seul cas utile

$compVarsValides = ['production', 'mortalite', 'conso_eau', 'taux_ponte', 'effectif'];
$compVar = in_array($_GET['comp_var'] ?? '', $compVarsValides, true) ? $_GET['comp_var'] : 'production';

$compPeriode = in_array($_GET['comp_periode'] ?? '', ['jour', 'mois', 'annee', 'personnalise'], true) ? $_GET['comp_periode'] : 'mois';

switch ($compPeriode) {
    case 'jour':
        $compDebut = $compFin = $dateJour;
        break;
    case 'annee':
        $compDebut = date('Y-01-01', strtotime($dateJour));
        $compFin   = date('Y-12-31', strtotime($dateJour));
        break;
    case 'personnalise':
        $compDebut = $_GET['comp_debut'] ?? date('Y-m-01', strtotime($dateJour));
        $compFin   = $_GET['comp_fin'] ?? $dateJour;
        break;
    case 'mois':
    default:
        $compDebut = date('Y-m-01', strtotime($dateJour));
        $compFin   = date('Y-m-t', strtotime($dateJour));
        break;
}

// Si un utilisateur (ouvrier) est connecté, le comparatif reste
// cantonné à SON bloc. Un admin peut en plus restreindre à un bloc
// précis (réutilise le filtre bloc déjà choisi en haut de page) quand
// il compare par bâtiment.
$compBlocRestriction = !estAdmin() ? (int)$user['bloc_id'] : ($compNiveau === 'batiment' ? $blocIdFiltre : null);

$champGroupe = $compNiveau === 'bloc' ? 'bl.id' : 'b.id';
$nomGroupe   = $compNiveau === 'bloc' ? 'bl.nom' : 'b.nom';

$sqlComp = "
    SELECT $champGroupe AS groupe_id, $nomGroupe AS groupe_nom,
           SUM(s.production_totale) AS somme_production,
           SUM(s.mortalite)         AS somme_mortalite,
           SUM(s.consommation_eau)  AS somme_conso,
           SUM(s.effectif_present)  AS somme_effectif,
           COUNT(*)                 AS nb_saisies
    FROM saisies s
    JOIN batiments b ON b.id = s.batiment_id
    JOIN blocs bl ON bl.id = b.bloc_id
    WHERE s.date_saisie BETWEEN :debut AND :fin
";
$paramsComp = ['debut' => $compDebut, 'fin' => $compFin];
if ($compBlocRestriction) {
    $sqlComp .= " AND bl.id = :bloc";
    $paramsComp['bloc'] = $compBlocRestriction;
}
$sqlComp .= " GROUP BY $champGroupe, $nomGroupe ORDER BY $nomGroupe";

$stmtComp = $pdo->prepare($sqlComp);
$stmtComp->execute($paramsComp);
$compLignes = $stmtComp->fetchAll();

$compLabels = [];
$compValeurs = [];
foreach ($compLignes as $l) {
    $compLabels[] = $l['groupe_nom'];
    switch ($compVar) {
        case 'mortalite':  $compValeurs[] = (float)$l['somme_mortalite']; break;
        case 'conso_eau':  $compValeurs[] = (float)$l['somme_conso']; break;
        case 'effectif':   $compValeurs[] = $l['nb_saisies'] > 0 ? round((float)$l['somme_effectif'] / (float)$l['nb_saisies']) : 0; break;
        case 'taux_ponte':
            $compValeurs[] = (float)$l['somme_effectif'] > 0 ? round(((float)$l['somme_production'] / (float)$l['somme_effectif']) * 100, 2) : 0;
            break;
        case 'production':
        default:
            $compValeurs[] = (float)$l['somme_production'];
            break;
    }
}
$compVarLabels = [
    'production'  => 'Production totale (oeufs)',
    'mortalite'   => 'Mortalité (sujets)',
    'conso_eau'   => 'Consommation eau (L)',
    'taux_ponte'  => 'Taux de ponte réalisé (%)',
    'effectif'    => 'Effectif présent (moyenne)',
];

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1>Statistiques simples<?= estAdmin() ? '' : ' — ' . htmlspecialchars($user['nom_complet']) ?></h1>
    <form method="get" class="date-filter">
        <label>Date</label>
        <input type="date" name="date" value="<?= htmlspecialchars($dateJour) ?>" onchange="this.form.submit()">

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
        <select name="batiment_id" onchange="this.form.submit()" <?= $blocEffectif ? '' : 'disabled' ?>>
            <option value="">Tous les bâtiments<?= estAdmin() && !$blocEffectif ? ' (choisir un bloc)' : '' ?></option>
            <?php foreach ($batimentsListe as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $batimentIdFiltre === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<h2 class="section-title">
    Aujourd'hui (<?= date('d/m/Y', strtotime($dateJour)) ?>)
    <?php if ($batimentIdFiltre): ?>
        <span class="hint">— Bâtiment : <?= htmlspecialchars(current(array_filter($batimentsListe, fn($b) => (int)$b['id'] === $batimentIdFiltre))['nom'] ?? '') ?></span>
    <?php elseif ($blocEffectif): ?>
        <span class="hint">— Bloc : <?= htmlspecialchars(estAdmin() ? (current(array_filter($blocsListe, fn($b) => (int)$b['id'] === $blocEffectif))['nom'] ?? '') : $nomBlocUtilisateur) ?></span>
    <?php elseif (estAdmin()): ?>
        <span class="hint">— Tous les blocs</span>
    <?php endif; ?>
</h2>
<div class="stats-cards">
    <div class="stat-card">
        <div class="stat-label">Production d'oeufs</div>
        <div class="stat-value"><?= fmtNum($productionJour) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Taux de ponte moyen</div>
        <div class="stat-value"><?= fmtPct($tauxPonteJour) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Mortalité (poulets)</div>
        <div class="stat-value"><?= fmtNum($mortaliteJour) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Effectif présent</div>
        <div class="stat-value"><?= fmtNum($effectifJour) ?></div>
    </div>
</div>

<h2 class="section-title">Ce mois-ci</h2>
<div class="stats-cards">
    <div class="stat-card">
        <div class="stat-label">Production cumulée du mois</div>
        <div class="stat-value"><?= fmtNum($productionMois) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Mortalité cumulée du mois</div>
        <div class="stat-value"><?= fmtNum($mortaliteMois) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Stock d'oeufs disponible</div>
        <div class="stat-value"><?= fmtNum($stockTotal) ?></div>
    </div>
</div>

<h2 class="section-title">Comparatif <?= $compNiveau === 'bloc' ? 'entre blocs' : 'entre bâtiments' ?></h2>
<form method="get" class="date-filter" id="formComparatif">
    <?php foreach (['date' => $dateJour, 'bloc_id' => $blocIdFiltre, 'batiment_id' => $batimentIdFiltre] as $k => $v): ?>
        <?php if ($v !== null && $v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>"><?php endif; ?>
    <?php endforeach; ?>

    <?php if (estAdmin()): ?>
    <label>Comparer par</label>
    <select name="comp_niveau" onchange="this.form.submit()">
        <option value="bloc" <?= $compNiveau === 'bloc' ? 'selected' : '' ?>>Bloc</option>
        <option value="batiment" <?= $compNiveau === 'batiment' ? 'selected' : '' ?>>Bâtiment</option>
    </select>
    <?php endif; ?>

    <label>Variable</label>
    <select name="comp_var" onchange="this.form.submit()">
        <?php foreach ($compVarLabels as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= $compVar === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
    </select>

    <label>Période</label>
    <select name="comp_periode" id="compPeriodeSelect" onchange="this.form.submit()">
        <option value="jour" <?= $compPeriode === 'jour' ? 'selected' : '' ?>>Dernier jour saisi</option>
        <option value="mois" <?= $compPeriode === 'mois' ? 'selected' : '' ?>>Mois en cours</option>
        <option value="annee" <?= $compPeriode === 'annee' ? 'selected' : '' ?>>Année en cours</option>
        <option value="personnalise" <?= $compPeriode === 'personnalise' ? 'selected' : '' ?>>Période personnalisée</option>
    </select>

    <span id="compDatesPersonnalisees" style="display:<?= $compPeriode === 'personnalise' ? 'inline-flex' : 'none' ?>;gap:8px;align-items:center;">
        <label>Du</label>
        <input type="date" name="comp_debut" value="<?= htmlspecialchars($compDebut) ?>" onchange="this.form.submit()">
        <label>Au</label>
        <input type="date" name="comp_fin" value="<?= htmlspecialchars($compFin) ?>" onchange="this.form.submit()">
    </span>
</form>
<p class="hint">Période analysée : du <?= date('d/m/Y', strtotime($compDebut)) ?> au <?= date('d/m/Y', strtotime($compFin)) ?>.</p>
<script>
document.getElementById('compPeriodeSelect').addEventListener('change', function () {
    document.getElementById('compDatesPersonnalisees').style.display = this.value === 'personnalise' ? 'inline-flex' : 'none';
});
</script>

<?php if ($compLabels): ?>
<div class="chart-box" style="max-width:900px;margin:0 16px 20px;">
    <canvas id="chartComparatif"></canvas>
</div>
<script src="assets/chart.umd.js"></script>
<script>
if (typeof Chart !== 'undefined') {
    new Chart(document.getElementById('chartComparatif'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($compLabels, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                label: <?= json_encode($compVarLabels[$compVar]) ?>,
                data: <?= json_encode($compValeurs) ?>,
                backgroundColor: '#558b2f',
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });
}
</script>
<?php else: ?>
<p class="hint">Aucune saisie sur cette période pour construire le comparatif.</p>
<?php endif; ?>

<p class="hint">Pour l'évolution détaillée par mois et par année, avec graphiques, voir la page "Stats détaillées".</p>
<a href="stat_detail.php?<?= http_build_query(array_filter(['bloc_id' => $blocIdFiltre, 'batiment_id' => $batimentIdFiltre])) ?>" class="btn-saisie">Voir les statistiques détaillées →</a>

<?php require_once 'includes/footer.php'; ?>