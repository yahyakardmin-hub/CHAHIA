<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'Statistiques détaillées';
$activePage = 'stats_detail';
$user = utilisateurConnecte();

// Période analysée (par défaut 365 jours pour bien voir l'évolution mensuelle/annuelle)
$nbJours = isset($_GET['jours']) ? max(7, min(730, (int)$_GET['jours'])) : 365;
$dateDebut = date('Y-m-d', strtotime("-$nbJours days"));

// -----------------------------------------------------------------
// Filtre Bloc / Bâtiment (même logique que stat_simple.php) :
// - Admin : choisit n'importe quel bloc (ou "Tous"), puis un bâtiment
//   de ce bloc (ou "Tous les bâtiments").
// - Utilisateur (ouvrier) : son bloc est fixe ; il peut choisir un
//   bâtiment précis de son bloc, ou "Tous".
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
if ($batimentIdFiltre && !in_array($batimentIdFiltre, array_column($batimentsListe, 'id'))) {
    $batimentIdFiltre = null;
}

// -----------------------------------------------------------------
// PHP fournit uniquement les saisies brutes (+ hiérarchie Bloc) et le
// stock par bloc ; TOUS les calculs et graphiques sont faits en JS
// (assets/charts.js), y compris l'évolution par mois et par année.
// Un utilisateur non-admin ne voit que les données de SON bloc, et
// tout le monde peut en plus restreindre à un seul bâtiment.
// -----------------------------------------------------------------
$sql = "
    SELECT
        s.date_saisie, s.effectif_present, s.production_totale, s.taux_ponte_objectif,
        s.consommation_eau, s.mortalite, s.pc_pct, s.sale_pct, s.casse_vendue_pct, s.casse_non_vendue_pct,
        b.id AS batiment_id, b.nom AS batiment_nom,
        bl.id AS bloc_id, bl.nom AS bloc_nom
    FROM saisies s
    JOIN batiments b ON b.id = s.batiment_id
    JOIN blocs bl ON bl.id = b.bloc_id
    WHERE s.date_saisie >= ?
";
$params = [$dateDebut];
if ($batimentIdFiltre) {
    $sql .= " AND s.batiment_id = ? ";
    $params[] = $batimentIdFiltre;
} elseif ($blocEffectif) {
    $sql .= " AND bl.id = ? ";
    $params[] = $blocEffectif;
}
$sql .= " ORDER BY s.date_saisie ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$saisiesBrutes = $stmt->fetchAll();

// Le stock est un concept "par bloc" (les commandes sont enregistrées au
// niveau du bloc) : le filtre bâtiment ne s'y applique pas.
if ($blocEffectif) {
    $stockBlocs = [getStockBloc($pdo, $blocEffectif) + ['nom' => estAdmin() ? (current(array_filter($blocsListe, fn($b) => (int)$b['id'] === $blocEffectif))['nom'] ?? '') : $nomBlocUtilisateur]];
} else {
    $stockBlocs = estAdmin() ? getStockTousBlocs($pdo) : [];
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1>Statistiques détaillées<?= estAdmin() ? '' : ' — ' . htmlspecialchars($user['nom_complet']) ?></h1>
    <form method="get" class="date-filter">
        <label for="jours">Période :</label>
        <select name="jours" id="jours" onchange="this.form.submit()">
            <option value="30"  <?= $nbJours==30 ?'selected':'' ?>>30 jours</option>
            <option value="90"  <?= $nbJours==90 ?'selected':'' ?>>90 jours</option>
            <option value="180" <?= $nbJours==180?'selected':'' ?>>180 jours</option>
            <option value="365" <?= $nbJours==365?'selected':'' ?>>1 an</option>
            <option value="730" <?= $nbJours==730?'selected':'' ?>>2 ans</option>
        </select>

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
<?php if ($batimentIdFiltre): ?>
    <p class="hint">Filtré sur le bâtiment : <b><?= htmlspecialchars(current(array_filter($batimentsListe, fn($b) => (int)$b['id'] === $batimentIdFiltre))['nom'] ?? '') ?></b></p>
<?php elseif ($blocEffectif): ?>
    <p class="hint">Filtré sur le bloc : <b><?= htmlspecialchars(estAdmin() ? (current(array_filter($blocsListe, fn($b) => (int)$b['id'] === $blocEffectif))['nom'] ?? '') : $nomBlocUtilisateur) ?></b></p>
<?php endif; ?>

<div class="stats-cards" id="statsCards"><!-- rempli par charts.js --></div>

<h2 class="section-title">Évolution dans le temps</h2>
<div class="charts-grid">
    <div class="chart-box">
        <h3>Évolution du taux de ponte moyen par jour (%)</h3>
        <canvas id="chartTauxPonte"></canvas>
    </div>
    <div class="chart-box">
        <h3>Production par mois (changements mois par mois)</h3>
        <canvas id="chartProdMois"></canvas>
    </div>
    <div class="chart-box">
        <h3>Taux de ponte moyen par mois (%)</h3>
        <canvas id="chartTauxMois"></canvas>
    </div>
    <div class="chart-box">
        <h3>Production par année (changements année par année)</h3>
        <canvas id="chartProdAnnee"></canvas>
    </div>
</div>

<h2 class="section-title">Répartition &amp; comparaisons</h2>
<div class="charts-grid">
    <?php if (estAdmin() && !$blocEffectif): ?>
    <div class="chart-box">
        <h3>Production totale par bloc (période)</h3>
        <canvas id="chartProdBloc"></canvas>
    </div>
    <?php endif; ?>
    <div class="chart-box">
        <h3>Répartition du tri des oeufs (%)</h3>
        <canvas id="chartTri"></canvas>
    </div>
    <div class="chart-box">
        <h3>Mortalité cumulée par bloc</h3>
        <canvas id="chartMortalite"></canvas>
    </div>
    <div class="chart-box">
        <h3>Consommation d'eau (L) - évolution</h3>
        <canvas id="chartEau"></canvas>
    </div>
    <?php if (estAdmin() && !$blocEffectif): ?>
    <div class="chart-box">
        <h3>Stock d'oeufs disponible par bloc</h3>
        <canvas id="chartStock"></canvas>
    </div>
    <?php endif; ?>
</div>

<script src="assets/chart.umd.js"></script>
<script>
    // Sécurité : si la librairie de graphiques n'a pas pu se charger (fichier
    // assets/chart.umd.js manquant), on le signale clairement au lieu de laisser
    // les cases de graphiques silencieusement vides.
    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.chart-box canvas').forEach(function (c) {
            var box = c.closest('.chart-box');
            box.innerHTML += '<p style="color:#c62828;font-size:12.5px;">Erreur : la librairie Chart.js (assets/chart.umd.js) n\'a pas pu être chargée.</p>';
        });
    }
    // Données brutes injectées par PHP -> tous les calculs statistiques sont faits en JS (voir assets/charts.js)
    window.SAISIES_DATA = <?= json_encode($saisiesBrutes, JSON_UNESCAPED_UNICODE) ?>;
    window.STOCK_BLOCS   = <?= json_encode($stockBlocs, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/charts.js"></script>

<?php require_once 'includes/footer.php'; ?>