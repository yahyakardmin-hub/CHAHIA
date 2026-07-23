<?php
/**
 * MAILER.PHP - Envoi d'emails réels via SMTP (PHPMailer)
 * --------------------------------------------------------
 * Remplace le mail() natif de PHP (qui ne fonctionne pas sur WAMP sans
 * configuration lourde) par un envoi SMTP classique, en utilisant les
 * identifiants définis dans config.php (SMTP_HOST, SMTP_USER, ...).
 *
 * PHPMailer est fourni directement dans lib/PHPMailer/ (pas besoin de
 * Composer) : les 3 fichiers nécessaires (PHPMailer.php, SMTP.php,
 * Exception.php) sont inclus ci-dessous.
 */

require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envoie un email en texte brut à un ou plusieurs destinataires via SMTP.
 *
 * @param string|array $destinataires Une adresse ou un tableau d'adresses.
 * @param string       $sujet
 * @param string       $message       Corps du message (texte brut).
 * @return bool                       true si l'envoi a réussi pour AU MOINS un destinataire.
 */
function envoyerMailSMTP($destinataires, string $sujet, string $message): bool
{
    $destinataires = is_array($destinataires) ? $destinataires : [$destinataires];
    $destinataires = array_values(array_unique(array_filter($destinataires)));
    if (!$destinataires) return false;

    // Configuration non renseignée -> on évite un envoi qui échouera de toute façon
    if (!defined('SMTP_USER') || SMTP_USER === '' || SMTP_USER === 'votre.adresse@gmail.com') {
        error_log('[ELhana] Alerte email non envoyée : SMTP_USER non configuré dans config.php');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
        foreach ($destinataires as $to) {
            $mail->addAddress($to);
        }

        $mail->isHTML(false);
        $mail->Subject = $sujet;
        $mail->Body    = $message;

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('[ELhana] Échec envoi email alerte : ' . $mail->ErrorInfo);
        return false;
    }
}
