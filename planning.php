<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireAdmin();

$pageTitle  = 'Planning';
$activePage = 'planning';

$blocs = getBlocsAvecStats($pdo);

require_once 'includes/header.php';
?>

<div class="page-header"><h1>Planning — Liste des blocs</h1></div>

<div class="table-wrapper">
<table class="tdb">
<thead>
<tr>
    <th>Exercice</th><th>Spéculation</th><th>Bloc</th><th>Lot</th>
    <th>Nb bâtiments</th><th>Superficie</th><th>Effectif Entrée</th>
    <th>Date Déb. Nett.</th><th>Date Entrée Eff.</th><th>Date Clôture</th>
    <th>État</th><th>Validé</th>
</tr>
</thead>
<tbody>
<?php foreach ($blocs as $b): ?>
    <tr>
        <td><?= $b['exercice'] ?></td>
        <td><?= htmlspecialchars($b['speculation']) ?></td>
        <td><?= htmlspecialchars($b['nom']) ?></td>
        <td><?= htmlspecialchars($b['lot_code']) ?></td>
        <td class="num"><?= (int)$b['nb_batiments'] ?></td>
        <td class="num"><?= fmtNum($b['superficie']) ?></td>
        <td class="num"><?= fmtNum($b['effectif_entree']) ?></td>
        <td><?= $b['date_debut_nettoyage'] ? date('d/m/Y', strtotime($b['date_debut_nettoyage'])) : '???' ?></td>
        <td><?= $b['date_entree_effectif'] ? date('d/m/Y', strtotime($b['date_entree_effectif'])) : '???' ?></td>
        <td><?= $b['date_cloture'] ? date('d/m/Y', strtotime($b['date_cloture'])) : '???' ?></td>
        <td><span class="badge badge-<?= $b['etat']==='EN COURS' ? 'en_attente' : 'validee' ?>"><?= htmlspecialchars($b['etat']) ?></span></td>
        <td><?= $b['valide'] ? 'Oui' : 'Non' ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php require_once 'includes/footer.php'; ?>