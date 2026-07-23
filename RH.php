<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'RH';
$activePage = 'rh';
$user = utilisateurConnecte();
$message = '';
$erreur  = '';

// -----------------------------------------------------------------
// RH = gestion des ouvriers-utilisateurs. L'utilisateur EST l'ouvrier :
// chaque ouvrier (compte non-admin) voit/contrôle UN SEUL bloc, via
// son propre champ bloc_id. Il n'y a plus de table "ouvriers" séparée.
//
// Ici, l'admin peut retirer un ouvrier de son bloc (le bloc redevient
// sans responsable) ; pour créer un nouvel ouvrier ou lui (ré)affecter
// un bloc, on passe par Paramétrage → onglet Utilisateurs.
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retirer_responsable' && estAdmin()) {
    $utilisateurId = (int)($_POST['utilisateur_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE utilisateurs SET bloc_id = NULL WHERE id = ? AND role = 'utilisateur'");
    $stmt->execute([$utilisateurId]);
    $message = "L'ouvrier a été retiré du bloc. Le bloc n'a plus de responsable.";
}

// -----------------------------------------------------------------
// Données d'affichage
// -----------------------------------------------------------------
if (estAdmin()) {
    $blocsAvecResponsable = getBlocsAvecResponsable($pdo);
} else {
    $monBloc = null;
    if ($user['bloc_id']) {
        $stmt = $pdo->prepare("SELECT * FROM blocs WHERE id = ?");
        $stmt->execute([(int)$user['bloc_id']]);
        $monBloc = $stmt->fetch();
    }
    $mesBatiments = [];
    if ($monBloc) {
        $stmt = $pdo->prepare("SELECT * FROM batiments WHERE bloc_id = ? ORDER BY nom");
        $stmt->execute([(int)$monBloc['id']]);
        $mesBatiments = $stmt->fetchAll();
    }
}

// Effectif journalier saisi (nombre_ouvriers par bâtiment), info/historique
$dateJour = $_GET['date'] ?? getDerniereDateSaisie($pdo, estAdmin() ? null : (int)$user['bloc_id']);
if (estAdmin()) {
    $stmt = $pdo->prepare("
        SELECT s.nombre_ouvriers, b.nom AS batiment_nom, bl.nom AS bloc_nom
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE s.date_saisie = ?
        ORDER BY bl.nom, b.nom
    ");
    $stmt->execute([$dateJour]);
} else {
    $stmt = $pdo->prepare("
        SELECT s.nombre_ouvriers, b.nom AS batiment_nom, bl.nom AS bloc_nom
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE s.date_saisie = ? AND bl.id = ?
        ORDER BY b.nom
    ");
    $stmt->execute([$dateJour, (int)$user['bloc_id']]);
}
$lignesEffectifJour = $stmt->fetchAll();
$totalOuvriersJour = array_sum(array_column($lignesEffectifJour, 'nombre_ouvriers'));

require_once 'includes/header.php';
?>

<div class="page-header"><h1>RH<?= estAdmin() ? ' — Ouvriers responsables de bloc' : ' — Mon bloc' ?></h1></div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert alert-error"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="sub-nav">
    <ul>
        <li><a href="#tab-responsables" data-tab="tab-responsables"><?= estAdmin() ? 'Blocs & responsables' : 'Mon bloc' ?></a></li>
        <li><a href="#tab-effectif" data-tab="tab-effectif">Effectif journalier saisi</a></li>
    </ul>
</div>

<!-- ============================= BLOCS & RESPONSABLES ============================= -->
<div id="tab-responsables" class="tab-panel">

<?php if (estAdmin()): ?>
<p class="hint">
    Chaque ouvrier est un compte utilisateur rattaché à <b>un seul bloc</b> qu'il peut voir et contrôler.
    Pour créer un nouvel ouvrier ou lui affecter/changer son bloc, utilisez
    <a href="parametre.php">Paramétrage → onglet Utilisateurs</a>. Ici, vous pouvez retirer un ouvrier
    de son bloc (le bloc devient alors sans responsable).
</p>

<div class="table-wrapper">
<table class="tdb">
<thead><tr><th>Bloc</th><th>Ouvrier responsable</th><th>Identifiant</th><th>Téléphone</th><th>Fonction</th><th>Statut</th><th>Action</th></tr></thead>
<tbody>
<?php foreach ($blocsAvecResponsable as $b): ?>
    <tr>
        <td><?= htmlspecialchars($b['nom']) ?> <span class="hint">(<?= htmlspecialchars($b['lot_code']) ?>)</span></td>
        <td><?= $b['utilisateur_id'] ? htmlspecialchars($b['responsable_nom']) : '<span class="hint-error">Aucun responsable</span>' ?></td>
        <td><?= $b['utilisateur_id'] ? htmlspecialchars($b['responsable_login']) : '—' ?></td>
        <td><?= htmlspecialchars($b['responsable_tel'] ?? '—') ?></td>
        <td><?= htmlspecialchars($b['responsable_fonction'] ?? '—') ?></td>
        <td><?php if ($b['utilisateur_id']): ?>
                <?= $b['responsable_actif'] ? '<span class="badge badge-validee">Actif</span>' : '<span class="badge badge-annulee">Inactif</span>' ?>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td>
            <?php if ($b['utilisateur_id']): ?>
            <form method="post" class="form-inline-compact" onsubmit="return confirm('Retirer cet ouvrier du bloc ?');">
                <input type="hidden" name="action" value="retirer_responsable">
                <input type="hidden" name="utilisateur_id" value="<?= $b['utilisateur_id'] ?>">
                <button type="submit" class="btn-edit">Retirer</button>
            </form>
            <?php else: ?>
                <a href="parametre.php" class="btn-edit">Affecter un ouvrier</a>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php else: ?>

<?php if (!$monBloc): ?>
    <div class="alert alert-error">Votre compte n'est pour l'instant rattaché à aucun bloc. Contactez l'administrateur.</div>
<?php else: ?>
    <div class="cards-row">
        <div class="card">
            <h3>Mon profil</h3>
            <p><b>Nom :</b> <?= htmlspecialchars($user['nom_complet']) ?></p>
            <p><b>Fonction :</b> <?= htmlspecialchars($user['fonction'] ?? 'Ouvrier responsable de bloc') ?></p>
            <p><b>Téléphone :</b> <?= htmlspecialchars($user['telephone'] ?? '—') ?></p>
        </div>
        <div class="card">
            <h3>Mon bloc : <?= htmlspecialchars($monBloc['nom']) ?></h3>
            <p><b>Lot :</b> <?= htmlspecialchars($monBloc['lot_code']) ?></p>
            <p><b>État :</b> <?= htmlspecialchars($monBloc['etat']) ?></p>
            <p><b>Nombre de bâtiments :</b> <?= count($mesBatiments) ?></p>
        </div>
    </div>

    <h2 class="section-title">Bâtiments de mon bloc</h2>
    <div class="table-wrapper">
    <table class="tdb">
    <thead><tr><th>Bâtiment</th><th>Souche</th><th>Age (S)</th><th>Effectif départ</th></tr></thead>
    <tbody>
    <?php foreach ($mesBatiments as $bat): ?>
        <tr>
            <td><?= htmlspecialchars($bat['nom']) ?></td>
            <td><?= htmlspecialchars($bat['souche']) ?></td>
            <td class="num"><?= $bat['age_semaines'] ?></td>
            <td class="num"><?= fmtNum($bat['effectif_depart']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
<?php endif; ?>

<?php endif; ?>
</div>

<!-- ============================= EFFECTIF JOURNALIER ============================= -->
<div id="tab-effectif" class="tab-panel">

<div class="page-header">
    <h2 style="margin:0;">Effectif ouvriers saisi le jour <span class="hint">(champ "Nombre d'ouvriers" de la saisie journalière)</span></h2>
    <form method="get" class="date-filter">
        <label>Date</label>
        <input type="date" name="date" value="<?= htmlspecialchars($dateJour) ?>" onchange="this.form.submit()">
    </form>
</div>

<div class="stats-cards">
    <div class="stat-card">
        <div class="stat-label">Bâtiments suivis</div>
        <div class="stat-value"><?= count($lignesEffectifJour) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total ouvriers (saisie journalière)</div>
        <div class="stat-value"><?= fmtNum($totalOuvriersJour) ?></div>
    </div>
</div>

<div class="table-wrapper">
<table class="tdb">
<thead><tr><th>Bloc</th><th>Bâtiment</th><th>Nombre d'ouvriers</th></tr></thead>
<tbody>
<?php foreach ($lignesEffectifJour as $l): ?>
    <tr>
        <td><?= htmlspecialchars($l['bloc_nom']) ?></td>
        <td><?= htmlspecialchars($l['batiment_nom']) ?></td>
        <td class="num"><?= fmtNum($l['nombre_ouvriers']) ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$lignesEffectifJour): ?><tr><td colspan="3" style="text-align:center;color:#888;">Aucune saisie pour cette date.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</div>

<script src="assets/script.js"></script>

<?php require_once 'includes/footer.php'; ?>
