<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'Stock';
$activePage = 'stock';
$user = utilisateurConnecte();

$stockBlocs = getStockTousBlocs($pdo);
if (!estAdmin()) {
    $stockBlocs = array_values(array_filter($stockBlocs, fn($b) => (int)$b['id'] === (int)$user['bloc_id']));
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1>Stock d'oeufs par bloc</h1>
</div>

<p class="hint">Stock disponible = production cumulée de tous les bâtiments du bloc − quantités déjà commandées (factures en attente ou validées).</p>

<div class="stats-cards">
    <div class="stat-card">
        <div class="stat-label">Blocs suivis</div>
        <div class="stat-value"><?= count($stockBlocs) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Stock total disponible</div>
        <div class="stat-value"><?= fmtNum(array_sum(array_column($stockBlocs, 'stock_disponible'))) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total déjà commandé</div>
        <div class="stat-value"><?= fmtNum(array_sum(array_column($stockBlocs, 'quantite_facturee'))) ?></div>
    </div>
</div>

<div class="table-wrapper">
<table class="tdb">
<thead>
<tr>
    <th>Bloc</th><th>Lot</th><th>État</th>
    <th>Production cumulée</th><th>Quantité facturée</th><th>Stock disponible</th>
</tr>
</thead>
<tbody>
<?php foreach ($stockBlocs as $b): ?>
    <tr>
        <td><?= htmlspecialchars($b['nom']) ?></td>
        <td><?= htmlspecialchars($b['lot_code']) ?></td>
        <td><span class="badge badge-<?= $b['etat']==='EN COURS'?'en_attente':'validee' ?>"><?= htmlspecialchars($b['etat']) ?></span></td>
        <td class="num"><?= fmtNum($b['production_totale']) ?></td>
        <td class="num"><?= fmtNum($b['quantite_facturee']) ?></td>
        <td class="num highlight"><?= fmtNum($b['stock_disponible']) ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php require_once 'includes/footer.php'; ?>