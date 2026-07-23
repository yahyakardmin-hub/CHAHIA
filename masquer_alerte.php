<?php
/**
 * MASQUER_ALERTE.PHP - Appelé en AJAX quand l'utilisateur clique sur le ×
 * du bandeau d'alerte. Enregistre un flag en session : le bandeau reste
 * masqué sur TOUTES les pages tant que la session est active, jusqu'à la
 * déconnexion (deconnecterUtilisateur() vide $_SESSION, donc le flag
 * disparaît alors et l'alerte pourra réapparaître à la prochaine connexion).
 */
require_once 'config.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

if (!estConnecte()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erreur' => 'non_connecte']);
    exit;
}

$_SESSION['alerte_masquee'] = true;
echo json_encode(['ok' => true]);
