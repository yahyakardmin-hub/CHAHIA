<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'TDB Maintenance';
$activePage = 'tdb_maint';

$user = utilisateurConnecte();
$dateJour = $_GET['date'] ?? getDerniereDateSaisie($pdo, estAdmin() ? null : (int)$user['bloc_id']);

if (estAdmin()) {
    $stmt = $pdo->prepare("
        SELECT b.nom AS batiment_nom, bl.nom AS bloc_nom,
               s.temp_min, s.temp_max, s.humidite, s.consommation_electricite, s.consommation_eau
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE s.date_saisie = ?
        ORDER BY bl.nom, b.nom
    ");
    $stmt->execute([$dateJour]);
} else {
    $stmt = $pdo->prepare("
        SELECT b.nom AS batiment_nom, bl.nom AS bloc_nom,
               s.temp_min, s.temp_max, s.humidite, s.consommation_electricite, s.consommation_eau
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE s.date_saisie = ? AND bl.id = ?
        ORDER BY b.nom
    ");
    $stmt->execute([$dateJour, (int)$user['bloc_id']]);
}
$lignes = $stmt->fetchAll();

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1>TDB Maintenance — Température &amp; Énergie</h1>
    <form method="get" class="date-filter">
        <label>Date</label>
        <input type="date" name="date" value="<?= htmlspecialchars($dateJour) ?>" onchange="this.form.submit()">
    </form>
</div>

<div class="table-wrapper">
<table class="tdb">
<thead><tr><th>Bloc</th><th>Bâtiment</th><th>Temp Min</th><th>Temp Max</th><th>Humidité</th><th>Électricité (Kw)</th><th>Eau (L)</th></tr></thead>
<tbody>
<?php foreach ($lignes as $l): ?>
    <tr>
        <td><?= htmlspecialchars($l['bloc_nom']) ?></td>
        <td><?= htmlspecialchars($l['batiment_nom']) ?></td>
        <td class="num"><?= $l['temp_min'] ?? '-' ?></td>
        <td class="num"><?= $l['temp_max'] ?? '-' ?></td>
        <td class="num"><?= $l['humidite'] ?? '-' ?></td>
        <td class="num"><?= fmtNum($l['consommation_electricite']) ?></td>
        <td class="num"><?= fmtNum($l['consommation_eau']) ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$lignes): ?><tr><td colspan="7" style="text-align:center;color:#888;">Aucune saisie pour cette date.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php require_once 'includes/footer.php'; ?>