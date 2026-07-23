<?php
/**
 * INCLUDES/AUTH.PHP - Authentification par session (pas d'API REST séparée :
 * tout se fait via PHP côté serveur, formulaires en POST direct).
 */

function utilisateurConnecte(): ?array
{
    return $_SESSION['user'] ?? null;
}

function estConnecte(): bool
{
    return isset($_SESSION['user']);
}

function estAdmin(): bool
{
    return estConnecte() && $_SESSION['user']['role'] === 'admin';
}

/** Redirige vers login.php si non connecté. A appeler en haut de chaque page protégée. */
function requireLogin(): void
{
    if (!estConnecte()) {
        header('Location: login.php');
        exit;
    }
}

/** Redirige si l'utilisateur n'est pas admin. */
function requireAdmin(): void
{
    requireLogin();
    if (!estAdmin()) {
        header('Location: TDB.php?erreur=acces_refuse');
        exit;
    }
}

function connecterUtilisateur(PDO $pdo, string $nomUtilisateur, string $motDePasse): bool
{
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE nom_utilisateur = ? AND actif = 1");
    $stmt->execute([$nomUtilisateur]);
    $u = $stmt->fetch();

    if ($u && password_verify($motDePasse, $u['mot_de_passe_hash'])) {
        $_SESSION['user'] = [
            'id'          => $u['id'],
            'nom_utilisateur' => $u['nom_utilisateur'],
            'nom_complet' => $u['nom_complet'],
            'telephone'   => $u['telephone'] ?? null,
            'fonction'    => $u['fonction'] ?? null,
            'role'        => $u['role'],
            'bloc_id'     => $u['bloc_id'] ?? null,
        ];
        return true;
    }
    return false;
}

function deconnecterUtilisateur(): void
{
    $_SESSION = [];
    session_destroy();
}