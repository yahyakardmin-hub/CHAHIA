<?php
require_once 'config.php';
require_once 'includes/auth.php';

if (estConnecte()) {
    header('Location: stat_detail.php');
    exit;
}

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomUtilisateur = trim($_POST['nom_utilisateur'] ?? '');
    $motDePasse     = $_POST['mot_de_passe'] ?? '';

    if ($nomUtilisateur === '' || $motDePasse === '') {
        $erreur = "Merci de saisir votre identifiant et votre mot de passe.";
    } elseif (connecterUtilisateur($pdo, $nomUtilisateur, $motDePasse)) {
        header('Location: stat_detail.php');
        exit;
    } else {
        $erreur = "Identifiant ou mot de passe incorrect.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion - CHAHIA</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">

<div class="login-box">
    <div class="logo-box" style="display:inline-block;margin-bottom:14px;">CHAHIA</div>
    <h1>GPAO Elevage</h1>
    <div class="login-subtitle">Tableau de bord journalier Pondeuse</div>

    <?php if ($erreur): ?><div class="login-error"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>
    <?php if (isset($_GET['erreur']) && $_GET['erreur'] === 'acces_refuse'): ?>
        <div class="login-error">Accès réservé à l'administrateur.</div>
    <?php endif; ?>

    <form method="post" class="login-form">
        <div class="form-group">
            <label for="nom_utilisateur">Identifiant</label>
            <input type="text" id="nom_utilisateur" name="nom_utilisateur" autofocus required>
        </div>
        <div class="form-group">
            <label for="mot_de_passe">Mot de passe</label>
            <input type="password" id="mot_de_passe" name="mot_de_passe" required>
        </div>
        <button type="submit" class="btn-submit" style="width:100%;">Se connecter</button>
    </form>

        
      
</div>

</body>
</html>
