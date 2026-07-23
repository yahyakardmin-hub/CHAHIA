<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'TDB Journalier Pondeuse';
$activePage = 'tdb';
$user = utilisateurConnecte();

// Date sélectionnée : celle choisie dans le filtre, sinon la dernière date
// réellement saisie (et non la date système, qui peut n'avoir aucune saisie
// si les données de démo utilisent des dates différentes).
$dateJour = isset($_GET['date']) && $_GET['date'] !== ''
    ? $_GET['date']
    : getDerniereDateSaisie($pdo, estAdmin() ? null : (int)$user['bloc_id']);
$dateVeille = date('Y-m-d', strtotime($dateJour . ' -1 day'));

// -----------------------------------------------------------------
// 1) Récupération de la hiérarchie Bloc > Bâtiment.
//    Tableau de bord ADMINISTRATEUR : tous les blocs.
//    Tableau de bord UTILISATEUR    : uniquement SON bloc rattaché.
// -----------------------------------------------------------------
if (estAdmin()) {
    $sql = "
        SELECT
            bl.id AS bloc_id, bl.nom AS bloc_nom,
            b.id  AS batiment_id, b.nom AS batiment_nom,
            b.age_semaines, b.souche, b.effectif_depart
        FROM batiments b
        JOIN blocs bl ON bl.id = b.bloc_id
        ORDER BY bl.nom, b.id
    ";
    $batiments = $pdo->query($sql)->fetchAll();
} else {
    $sql = "
        SELECT
            bl.id AS bloc_id, bl.nom AS bloc_nom,
            b.id  AS batiment_id, b.nom AS batiment_nom,
            b.age_semaines, b.souche, b.effectif_depart
        FROM batiments b
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE bl.id = ?
        ORDER BY b.id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([(int)$user['bloc_id']]);
    $batiments = $stmt->fetchAll();
}

// -----------------------------------------------------------------
// 2) Récupération des saisies du jour et de la veille (indexées par batiment_id)
// -----------------------------------------------------------------
$saisiesJour   = getSaisies($pdo, $dateJour);
$saisiesVeille = getSaisies($pdo, $dateVeille);

// -----------------------------------------------------------------
// 3) Construction de la structure Bloc > lignes calculées
// -----------------------------------------------------------------
$structure = [];
foreach ($batiments as $b) {
    $sJour   = $saisiesJour[$b['batiment_id']]   ?? null;
    $sVeille = $saisiesVeille[$b['batiment_id']] ?? null;

    $ligneVide = [
        'effectif_present' => 0, 'production_totale' => 0, 'taux_ponte_objectif' => 0,
        'consommation_eau' => 0, 'mortalite' => 0, 'reforme_tri' => 0,
        'pc_pct' => 0, 'sale_pct' => 0, 'casse_vendue_pct' => 0, 'casse_non_vendue_pct' => 0,
        'temp_min' => null, 'temp_max' => null,
    ];
    $ligneCalculee = calculerLigne($sJour ?? $ligneVide, $sVeille);
    $ligneCalculee['batiment']       = $b;
    $ligneCalculee['a_une_saisie']   = $sJour !== null;

    $structure[$b['bloc_nom']][] = $ligneCalculee;
}

// Liste des dates disponibles pour le sélecteur
if (estAdmin()) {
    $datesDispo = $pdo->query("SELECT DISTINCT date_saisie FROM saisies ORDER BY date_saisie DESC")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.date_saisie FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        WHERE b.bloc_id = ? ORDER BY s.date_saisie DESC
    ");
    $stmt->execute([(int)$user['bloc_id']]);
    $datesDispo = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
if (!in_array($dateJour, $datesDispo, true)) { array_unshift($datesDispo, $dateJour); }

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1>TDB journalier Pondeuse <?= estAdmin() ? '(tous les blocs)' : '— ' . htmlspecialchars($user['nom_complet']) ?> : <?= date('d/m/Y', strtotime($dateJour)) ?></h1>
    <form method="get" class="date-filter">
        <label for="date">Date :</label>
        <select name="date" id="date" onchange="this.form.submit()">
            <?php foreach ($datesDispo as $d): ?>
                <option value="<?= $d ?>" <?= $d === $dateJour ? 'selected' : '' ?>>
                    <?= date('d/m/Y', strtotime($d)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <a href="saisie.php" class="btn-saisie">+ Nouvelle saisie</a>
    </form>
</div>

<?php if (!$structure): ?>
    <p class="hint">Aucun bâtiment à afficher<?= estAdmin() ? '' : ' — votre compte n\'est rattaché à aucun bloc.' ?></p>
<?php endif; ?>

<div class="table-wrapper">
<table class="tdb">
<thead>
<tr class="row-group-header">
    <th rowspan="2">Bloc</th>
    <th rowspan="2">Bâtiment</th>
    <th rowspan="2">Age (S)</th>
    <th rowspan="2">Souche</th>
    <th rowspan="2">Effectif<br>Départ</th>
    <th rowspan="2">Effectif<br>Présent</th>
    <th colspan="5">Taux de ponte</th>
    <th colspan="2">Consommation d'eau</th>
    <th colspan="3">Mouvements Cheptel</th>
    <th rowspan="2">Production<br>Totale J</th>
    <th colspan="5">Oeufs Triés</th>
    <th colspan="2">Température</th>
</tr>
<tr class="row-subheader">
    <th>Réalisé J</th><th>Réalisé J-1</th><th>Ecart J/J-1</th><th>Taux De Ponte Obj</th><th>Ecart R/O</th>
    <th>J</th><th>J-1</th>
    <th>Mortalité Jour</th><th>Mortalité Jour-1</th><th>Ecart</th>
    <th>%Tri</th><th>PC</th><th>Sale</th><th>Casse Vendu</th><th>Casse Non Vendu</th>
    <th>MIN</th><th>MAX</th>
</tr>
</thead>
<tbody>
<?php foreach ($structure as $blocNom => $lignesBloc): ?>
    <?php foreach ($lignesBloc as $i => $l):
        $b = $l['batiment'];
    ?>
    <tr class="row-batiment <?= $l['a_une_saisie'] ? '' : 'row-vide' ?>">
        <td><?= $i === 0 ? htmlspecialchars($blocNom) : '' ?></td>
        <td>
            <?= htmlspecialchars($b['batiment_nom']) ?>
            <a href="saisie.php?batiment_id=<?= $b['batiment_id'] ?>&date=<?= $dateJour ?>" class="btn-edit" title="Saisir/modifier">✎</a>
        </td>
        <td class="num"><?= $b['age_semaines'] ?></td>
        <td><?= htmlspecialchars($b['souche']) ?></td>
        <td class="num"><?= fmtNum($b['effectif_depart']) ?></td>
        <td class="num"><?= fmtNum($l['effectif_present']) ?></td>

        <td class="num"><?= fmtPct($l['taux_realise_j']) ?></td>
        <td class="num"><?= fmtPct($l['taux_realise_j1']) ?></td>
        <td class="num <?= classeEcart($l['ecart_j_j1']) ?>"><?= fmtPct($l['ecart_j_j1']) ?></td>
        <td class="num"><?= fmtPct($l['taux_objectif']) ?></td>
        <td class="num <?= classeEcart($l['ecart_ro']) ?>"><?= fmtPct($l['ecart_ro']) ?></td>

        <td class="num highlight"><?= fmtNum($l['conso_j']) ?></td>
        <td class="num"><?= fmtNum($l['conso_j1']) ?></td>

        <td class="num"><?= fmtNum($l['mortalite_j']) ?></td>
        <td class="num"><?= fmtNum($l['mortalite_j1']) ?></td>
        <td class="num <?= classeEcart($l['ecart_mortalite']) ?>"><?= fmtNum($l['ecart_mortalite']) ?></td>

        <td class="num"><?= fmtNum($l['production_totale']) ?></td>

        <td class="num"><?= fmtPct($l['pct_tri']) ?></td>
        <td class="num"><?= fmtPct($l['pc_pct']) ?></td>
        <td class="num"><?= fmtPct($l['sale_pct']) ?></td>
        <td class="num"><?= fmtPct($l['casse_vendue_pct']) ?></td>
        <td class="num"><?= fmtPct($l['casse_non_vendue_pct']) ?></td>

        <td class="num"><?= $l['temp_min'] !== null ? $l['temp_min'] : '-' ?></td>
        <td class="num"><?= $l['temp_max'] !== null ? $l['temp_max'] : '-' ?></td>
    </tr>
    <?php endforeach; ?>

    <?php $totalBloc = calculerTotal($lignesBloc); ?>
    <tr class="row-total-centre">
        <td colspan="2" class="label-total">Total Bloc <?= htmlspecialchars($blocNom) ?></td>
        <td></td><td></td>
        <td class="num"><?= fmtNum(array_sum(array_column(array_map(fn($l)=>$l['batiment'], $lignesBloc), 'effectif_depart'))) ?></td>
        <td class="num"><?= fmtNum($totalBloc['effectif_present']) ?></td>

        <td class="num"><?= fmtPct($totalBloc['taux_realise_j']) ?></td>
        <td class="num"><?= fmtPct($totalBloc['taux_realise_j1']) ?></td>
        <td class="num <?= classeEcart($totalBloc['ecart_j_j1']) ?>"><?= fmtPct($totalBloc['ecart_j_j1']) ?></td>
        <td class="num"><?= fmtPct($totalBloc['taux_objectif']) ?></td>
        <td class="num <?= classeEcart($totalBloc['ecart_ro']) ?>"><?= fmtPct($totalBloc['ecart_ro']) ?></td>

        <td class="num"><?= fmtNum($totalBloc['conso_j']) ?></td>
        <td class="num"><?= fmtNum($totalBloc['conso_j1']) ?></td>

        <td class="num"><?= fmtNum($totalBloc['mortalite_j']) ?></td>
        <td class="num"><?= fmtNum($totalBloc['mortalite_j1']) ?></td>
        <td class="num <?= classeEcart($totalBloc['ecart_mortalite']) ?>"><?= fmtNum($totalBloc['ecart_mortalite']) ?></td>

        <td class="num"><?= fmtNum($totalBloc['production_totale']) ?></td>

        <td class="num"><?= fmtPct($totalBloc['pct_tri']) ?></td>
        <td class="num"><?= fmtPct($totalBloc['pc_pct']) ?></td>
        <td class="num"><?= fmtPct($totalBloc['sale_pct']) ?></td>
        <td class="num"><?= fmtPct($totalBloc['casse_vendue_pct']) ?></td>
        <td class="num"><?= fmtPct($totalBloc['casse_non_vendue_pct']) ?></td>
        <td colspan="2"></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php require_once 'includes/footer.php'; ?>