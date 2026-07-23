<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'Production';
$activePage = 'production';
$user = utilisateurConnecte();

$dateRef    = getDerniereDateSaisie($pdo, estAdmin() ? null : (int)$user['bloc_id']);
$dateDebut  = $_GET['debut'] ?? date('Y-m-d', strtotime($dateRef . ' -7 days'));
$dateFin    = $_GET['fin'] ?? $dateRef;

if (estAdmin()) {
    $stmt = $pdo->prepare("
        SELECT s.date_saisie, s.production_totale, s.effectif_present, s.mortalite,
               b.nom AS batiment_nom, bl.nom AS bloc_nom
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE s.date_saisie BETWEEN ? AND ?
        ORDER BY s.date_saisie DESC, bl.nom, b.nom
    ");
    $stmt->execute([$dateDebut, $dateFin]);
} else {
    $stmt = $pdo->prepare("
        SELECT s.date_saisie, s.production_totale, s.effectif_present, s.mortalite,
               b.nom AS batiment_nom, bl.nom AS bloc_nom
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE s.date_saisie BETWEEN ? AND ? AND bl.id = ?
        ORDER BY s.date_saisie DESC, b.nom
    ");
    $stmt->execute([$dateDebut, $dateFin, (int)$user['bloc_id']]);
}
$lignes = $stmt->fetchAll();

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1>Production</h1>
    <form method="get" class="date-filter">
        <label>Du</label>
        <input type="date" name="debut" value="<?= htmlspecialchars($dateDebut) ?>">
        <label>au</label>
        <input type="date" name="fin" value="<?= htmlspecialchars($dateFin) ?>">
        <button type="submit" class="btn-saisie">Filtrer</button>
    </form>
</div>

<div class="stats-cards" id="statsProdCards"></div>

<div class="table-wrapper">
<table class="tdb" id="tableProduction">
<thead>
<tr><th>Date</th><th>Bloc</th><th>Bâtiment</th><th>Production</th><th>Effectif présent</th><th>Mortalité</th></tr>
</thead>
<tbody>
<?php foreach ($lignes as $l): ?>
    <tr data-production="<?= (int)$l['production_totale'] ?>" data-mortalite="<?= (int)$l['mortalite'] ?>">
        <td><?= date('d/m/Y', strtotime($l['date_saisie'])) ?></td>
        <td><?= htmlspecialchars($l['bloc_nom']) ?></td>
        <td><?= htmlspecialchars($l['batiment_nom']) ?></td>
        <td class="num highlight"><?= fmtNum($l['production_totale']) ?></td>
        <td class="num"><?= fmtNum($l['effectif_present']) ?></td>
        <td class="num"><?= fmtNum($l['mortalite']) ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$lignes): ?><tr><td colspan="6" style="text-align:center;color:#888;">Aucune donnée sur cette période.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<script>
// Calcul en JS des totaux de la période affichée (pas de recalcul serveur)
(function() {
    const lignes = document.querySelectorAll('#tableProduction tbody tr[data-production]');
    let totalProduction = 0, totalMortalite = 0;
    lignes.forEach(tr => {
        totalProduction += parseInt(tr.dataset.production) || 0;
        totalMortalite  += parseInt(tr.dataset.mortalite) || 0;
    });
    document.getElementById('statsProdCards').innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Lignes affichées</div>
            <div class="stat-value">${lignes.length}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Production totale (période)</div>
            <div class="stat-value">${totalProduction.toLocaleString('fr-FR')}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Mortalité totale (période)</div>
            <div class="stat-value">${totalMortalite.toLocaleString('fr-FR')}</div>
        </div>
    `;
})();
</script>

<?php require_once 'includes/footer.php'; ?>