<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireAdmin();

$pageTitle  = 'Fichier';
$activePage = 'fichier';

$batiments = $pdo->query("
    SELECT bl.nom AS bloc_nom, bl.lot_code,
           b.nom AS batiment_nom, b.age_semaines, b.souche, b.effectif_depart
    FROM batiments b
    JOIN blocs bl ON bl.id = b.bloc_id
    ORDER BY bl.nom, b.nom
")->fetchAll();

require_once 'includes/header.php';
?>

<div class="page-header"><h1>Fichier — Données de base</h1></div>
<p class="hint">Hiérarchie simplifiée : un <b>Bloc</b> contient directement un ou plusieurs <b>Bâtiments</b> (plus de région/société/centre).</p>

<div class="table-wrapper">
<table class="tdb">
<thead><tr><th>Bloc</th><th>Lot</th><th>Bâtiment</th><th>Age (S)</th><th>Souche</th><th>Effectif Départ</th></tr></thead>
<tbody>
<?php foreach ($batiments as $b): ?>
    <tr>
        <td><?= htmlspecialchars($b['bloc_nom']) ?></td>
        <td><?= htmlspecialchars($b['lot_code']) ?></td>
        <td><?= htmlspecialchars($b['batiment_nom']) ?></td>
        <td class="num"><?= $b['age_semaines'] ?></td>
        <td><?= htmlspecialchars($b['souche']) ?></td>
        <td class="num"><?= fmtNum($b['effectif_depart']) ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$batiments): ?><tr><td colspan="6" style="text-align:center;color:#888;">Aucun bâtiment enregistré.</td></tr><?php endif; ?>
</tbody>
</table>
</div>

<?php require_once 'includes/footer.php'; ?>
