<?php
// Attend que $pageTitle et $activePage soient définis par la page appelante
$pageTitle  = $pageTitle  ?? 'GPAO Elevage';
$activePage = $activePage ?? '';
$user = utilisateurConnecte();
$isAdmin = $user && $user['role'] === 'admin';

// ------------------------------------------------------------------
// Deux nav-bars différentes selon le rôle (demande explicite) :
//  - ADMIN   : accès à toutes les pages (paramétrage, planning, reporting...)
//  - UTILISATEUR : uniquement les pages liées à SON bloc (saisie, RH, stats,
//    factures/stock de son bloc). Pas d'accès à Paramétrage/Planning/Fichier/Reporting.
// ------------------------------------------------------------------
$navAdmin = [
    ['fichier',       'fichier.php',       'Fichier'],
    ['parametre',     'parametre.php',     'Paramétrage'],
    ['planning',      'planning.php',      'Planning'],
    ['production',    'production.php',    'Production'],
    ['rh',            'RH.php',            'RH'],
    ['facture',       'facture.php',       'Factures'],
    ['stock',         'stock.php',         'Stock'],
    ['tdb',           'TDB.php',           'TDB'],
    ['tdb_maint',     'TDB_maint.php',     'TDB maint'],
    ['stats_simple',  'stat_simple.php',   'Stat. simple'],
    ['stats_detail',  'stat_detail.php',   'Stat. détaillée'],
    ['historique',    'historique.php',    'Historique'],
    ['edition',       'edition.php',       'Reporting'],
];

$navUtilisateur = [
    ['tdb',           'TDB.php',           'Mon TDB'],
    ['production',    'production.php',    'Production'],
    ['rh',            'RH.php',            'RH'],
    ['stats_simple',  'stat_simple.php',   'Stat. simple'],
    ['stats_detail',  'stat_detail.php',   'Stat. détaillée'],
    ['facture',       'facture.php',       'Factures'],
    ['stock',         'stock.php',         'Stock'],
    ['historique',    'historique.php',    'Historique'],
    ['edition',       'edition.php',       'Reporting'],
];

$navItems = $isAdmin ? $navAdmin : $navUtilisateur;

// ------------------------------------------------------------------
// Alertes automatiques :
// - La vérification "absence de saisie depuis X jours" est faite au
//   maximum une fois par jour et par session (pas à chaque page, pour
//   rester léger), directement ici puisque ce fichier est inclus par
//   toutes les pages protégées.
// - Le bandeau affiche les alertes déclenchées AUJOURD'HUI, pertinentes
//   pour l'utilisateur connecté (toutes pour l'admin, celles de son
//   bloc seulement pour un ouvrier).
// ------------------------------------------------------------------
$alertesDuJour = [];
if ($user) {
    if (($_SESSION['alertes_verifiees_le'] ?? '') !== date('Y-m-d')) {
        verifierAlertesAbsenceSaisie($pdo);
        $_SESSION['alertes_verifiees_le'] = date('Y-m-d');
    }
    $alertesDuJour = getAlertesDuJour($pdo, $isAdmin ? null : (int)($user['bloc_id'] ?? 0));
}

// Une fois "masquée" par l'utilisateur (bouton ×), l'alerte reste masquée sur
// TOUTES les pages tant que la session est active, jusqu'à la déconnexion
// (deconnecterUtilisateur() vide $_SESSION, donc ce flag disparaît alors).
$alerteMasquee = !empty($_SESSION['alerte_masquee']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> - CHAHIA</title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?: time() ?>">
</head>
<body>

<div class="topbar">
    <div class="logo-box">CHAHIA</div>
    <div class="nav-bar nav-bar-<?= $isAdmin ? 'admin' : 'user' ?>">
    <ul>
      <?php foreach ($navItems as [$key, $href, $label]): ?>
        <li><a href="<?= $href ?>" class="<?= $activePage === $key ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a></li>
      <?php endforeach; ?>
    </ul>
    </div>

    <?php if ($user): ?>
    <div class="user-box">
        <span class="user-name"><?= htmlspecialchars($user['nom_complet']) ?></span>
        <span class="user-role role-<?= $user['role'] ?>"><?= $isAdmin ? 'Admin' : 'Utilisateur' ?></span>
        <a href="logout.php" class="btn-logout">Déconnexion</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($alertesDuJour && !$alerteMasquee): ?>
<div class="alert-banner" id="alertBanner">
    <div class="alert-banner-head">
        <strong>⚠ <?= count($alertesDuJour) ?> alerte<?= count($alertesDuJour) > 1 ? 's' : '' ?> aujourd'hui</strong>
        <a href="historique.php#alertes">Voir l'historique des alertes →</a>
        <button type="button" class="alert-banner-close" onclick="masquerAlerte()" title="Masquer sur toutes les pages jusqu'à la déconnexion">×</button>
    </div>
    <ul>
        <?php foreach (array_slice($alertesDuJour, 0, 5) as $a): ?>
            <li><span class="badge-alerte badge-alerte-<?= $a['type_alerte'] ?>"><?= htmlspecialchars(libelleTypeAlerte($a['type_alerte'])) ?></span> <?= htmlspecialchars($a['message']) ?></li>
        <?php endforeach; ?>
        <?php if (count($alertesDuJour) > 5): ?><li class="hint">... et <?= count($alertesDuJour) - 5 ?> autre(s), voir l'historique.</li><?php endif; ?>
    </ul>
</div>
<?php endif; ?>

<script>
// Masque l'alerte immédiatement, puis prévient le serveur (session) pour que
// le bandeau reste masqué sur toutes les pages jusqu'à la déconnexion.
function masquerAlerte() {
    var banner = document.getElementById('alertBanner');
    if (banner) banner.style.display = 'none';
    fetch('masquer_alerte.php', { method: 'POST', credentials: 'same-origin' }).catch(function () {});
}
</script>

