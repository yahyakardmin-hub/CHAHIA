<?php
/**
 * CONFIG.PHP - Connexion à la base de données + session
 * A adapter selon ton serveur (WAMP/XAMPP : utilisateur root / pas de mot de passe par défaut)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'chahia');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Erreur de connexion à la base de données : ' . $e->getMessage());
}

/**
 * CONFIGURATION SMTP (envoi réel des emails d'alerte)
 * -----------------------------------------------------
 * Le serveur mail() natif de PHP ne fonctionne pas sur WAMP sans
 * configuration supplémentaire : les alertes email utilisent donc
 * PHPMailer avec un vrai serveur SMTP (Gmail, Outlook, OVH, etc.).
 *
 * Pour Gmail :
 *   1) Active la validation en 2 étapes sur le compte Gmail expéditeur.
 *   2) Crée un "mot de passe d'application" : myaccount.google.com/apppasswords
 *   3) Renseigne cette adresse Gmail dans SMTP_USER et le mot de passe
 *      d'application (16 caractères, sans espaces) dans SMTP_PASS.
 *      NE PAS utiliser le mot de passe normal du compte Gmail : Google
 *      le refusera.
 *
 * Pour Outlook/Office365 : SMTP_HOST = 'smtp.office365.com', port 587, STARTTLS.
 * Pour un hébergeur (OVH, etc.) : voir la documentation de l'hébergeur.
 */
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);              // 587 = STARTTLS (recommandé), 465 = SSL
define('SMTP_SECURE', 'tls');          // 'tls' pour le port 587, 'ssl' pour le port 465
define('SMTP_USER', 'votre.adresse@gmail.com');   // adresse expéditrice
define('SMTP_PASS', 'xxxxxxxxxxxxxxxx');          // mot de passe d'application (PAS le mot de passe du compte)
define('SMTP_FROM_NAME', 'ELhana - CHAHIA');