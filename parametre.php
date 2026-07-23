<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireAdmin();

$pageTitle  = 'Paramétrage';
$activePage = 'parametre';
$message = '';
$erreur  = '';

// ===================================================================
// UTILISATEURS
// ===================================================================

// ---------- Création d'un utilisateur (= un ouvrier) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer_utilisateur') {
    $nomUtilisateur = trim($_POST['nom_utilisateur'] ?? '');
    $nomComplet     = trim($_POST['nom_complet'] ?? '');
    $telephone      = trim($_POST['telephone'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $fonction       = trim($_POST['fonction'] ?? '') ?: 'Ouvrier responsable de bloc';
    $motDePasse     = $_POST['mot_de_passe'] ?? '';
    $role           = $_POST['role'] ?? 'utilisateur';
    $blocId         = ($_POST['bloc_id'] ?? '') !== '' ? (int)$_POST['bloc_id'] : null;

    if ($nomUtilisateur === '' || $nomComplet === '' || $motDePasse === '') {
        $erreur = "Tous les champs sont obligatoires pour créer un utilisateur.";
    } else {
        // Vérifie AVANT l'insertion que l'identifiant n'existe pas déjà
        // (message clair) et que le bloc choisi existe bien (évite un
        // échec silencieux par violation de clé étrangère).
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE nom_utilisateur = ?");
        $stmt->execute([$nomUtilisateur]);
        $existeDeja = (bool)$stmt->fetchColumn();

        $responsableActuel = null;
        if ($role === 'utilisateur' && $blocId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM blocs WHERE id = ?");
            $stmt->execute([$blocId]);
            if (!$stmt->fetchColumn()) {
                $blocId = null; // bloc introuvable/périmé -> on ignore plutôt que d'échouer
            } else {
                // Un bloc ne peut avoir qu'UN SEUL ouvrier responsable à la fois.
                $stmt = $pdo->prepare("SELECT nom_complet FROM utilisateurs WHERE bloc_id = ? AND role = 'utilisateur'");
                $stmt->execute([$blocId]);
                $responsableActuel = $stmt->fetchColumn();
            }
        }

        if ($existeDeja) {
            $erreur = "L'identifiant \"$nomUtilisateur\" est déjà utilisé par un autre compte.";
        } elseif ($responsableActuel) {
            $erreur = "Ce bloc a déjà un ouvrier responsable (\"$responsableActuel\"). Retirez-le d'abord (page RH) ou choisissez un autre bloc.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO utilisateurs (nom_utilisateur, mot_de_passe_hash, nom_complet, telephone, email, fonction, role, bloc_id, actif)
                    VALUES (:u, :p, :n, :t, :e, :f, :r, :b, 1)
                ");
                $stmt->execute([
                    'u' => $nomUtilisateur,
                    'p' => password_hash($motDePasse, PASSWORD_DEFAULT),
                    'n' => $nomComplet,
                    't' => $telephone ?: null,
                    'e' => $email ?: null,
                    'f' => $role === 'admin' ? null : $fonction,
                    'r' => $role,
                    'b' => $role === 'admin' ? null : $blocId,
                ]);
                $message = "Utilisateur (ouvrier) \"$nomUtilisateur\" créé avec succès. Il peut se connecter dès maintenant avec le mot de passe indiqué.";
            } catch (PDOException $e) {
                // On affiche le vrai message SQL (page admin uniquement) plutôt qu'un
                // message générique trompeur, pour identifier immédiatement la cause réelle.
                $erreur = "Erreur lors de la création de l'utilisateur : " . $e->getMessage();
            }
        }
    }
}

// ---------- Modification d'un utilisateur (nom, mot de passe, bloc, contact — application immédiate) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'modifier_utilisateur') {
    $utilisateurId = (int)($_POST['utilisateur_id'] ?? 0);
    $nomComplet    = trim($_POST['nom_complet'] ?? '');
    $telephone     = trim($_POST['telephone'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $fonction      = trim($_POST['fonction'] ?? '') ?: 'Ouvrier responsable de bloc';
    $nouveauMdp    = trim($_POST['nouveau_mot_de_passe'] ?? '');
    $blocId        = ($_POST['bloc_id'] ?? '') !== '' ? (int)$_POST['bloc_id'] : null;

    if ($utilisateurId <= 0 || $nomComplet === '') {
        $erreur = "Nom complet obligatoire pour la modification.";
    } else {
        $stmt = $pdo->prepare("SELECT role FROM utilisateurs WHERE id = ?");
        $stmt->execute([$utilisateurId]);
        $roleActuel = $stmt->fetchColumn();

        $responsableActuel = null;
        if ($roleActuel !== 'admin' && $blocId !== null) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM blocs WHERE id = ?");
            $stmt->execute([$blocId]);
            if (!$stmt->fetchColumn()) {
                $blocId = null; // bloc introuvable/périmé -> on l'ignore
            } else {
                // Un bloc ne peut avoir qu'UN SEUL ouvrier responsable (on exclut l'utilisateur en cours d'édition).
                $stmt = $pdo->prepare("SELECT nom_complet FROM utilisateurs WHERE bloc_id = ? AND role = 'utilisateur' AND id != ?");
                $stmt->execute([$blocId, $utilisateurId]);
                $responsableActuel = $stmt->fetchColumn();
            }
        }

        if ($responsableActuel) {
            $erreur = "Ce bloc a déjà un ouvrier responsable (\"$responsableActuel\"). Retirez-le d'abord (page RH) ou choisissez un autre bloc.";
        } else {
            if ($nouveauMdp !== '') {
                $sql = "UPDATE utilisateurs SET nom_complet = :n, telephone = :t, email = :e, fonction = :f, mot_de_passe_hash = :p, bloc_id = :b WHERE id = :id";
                $params = [
                    'n' => $nomComplet,
                    't' => $telephone ?: null,
                    'e' => $email ?: null,
                    'f' => $roleActuel === 'admin' ? null : $fonction,
                    'p' => password_hash($nouveauMdp, PASSWORD_DEFAULT),
                    'b' => $roleActuel === 'admin' ? null : $blocId,
                    'id' => $utilisateurId,
                ];
            } else {
                $sql = "UPDATE utilisateurs SET nom_complet = :n, telephone = :t, email = :e, fonction = :f, bloc_id = :b WHERE id = :id";
                $params = [
                    'n' => $nomComplet,
                    't' => $telephone ?: null,
                    'e' => $email ?: null,
                    'f' => $roleActuel === 'admin' ? null : $fonction,
                    'b' => $roleActuel === 'admin' ? null : $blocId,
                    'id' => $utilisateurId,
                ];
            }

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                // Si l'utilisateur modifié est déjà connecté ailleurs, sa session en cours
                // garde l'ancien nom jusqu'à sa prochaine connexion ; toute NOUVELLE
                // connexion utilisera immédiatement le nouveau nom / mot de passe.
                $message = "Utilisateur mis à jour avec succès (changement appliqué immédiatement).";
            } catch (PDOException $e) {
                $erreur = "Erreur lors de la modification : " . $e->getMessage();
            }
        }
    }
}

// ---------- Activer / désactiver un utilisateur ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_utilisateur') {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET actif = 1 - actif WHERE id = ?");
    $stmt->execute([(int)$_POST['utilisateur_id']]);
    $message = "Statut de l'utilisateur mis à jour.";
}

// ---------- Suppression DÉFINITIVE d'un utilisateur ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer_utilisateur') {
    $utilisateurId = (int)($_POST['utilisateur_id'] ?? 0);
    $moi           = utilisateurConnecte();

    if ($utilisateurId <= 0) {
        $erreur = "Utilisateur invalide.";
    } elseif ($moi && (int)$moi['id'] === $utilisateurId) {
        // On ne peut pas se supprimer soi-même (on perdrait sa propre session admin).
        $erreur = "Vous ne pouvez pas supprimer votre propre compte (connecté).";
    } else {
        $stmt = $pdo->prepare("SELECT nom_utilisateur, nom_complet, role FROM utilisateurs WHERE id = ?");
        $stmt->execute([$utilisateurId]);
        $cible = $stmt->fetch();

        if (!$cible) {
            $erreur = "Cet utilisateur n'existe plus.";
        } elseif ($cible['role'] === 'admin') {
            // On ne peut jamais supprimer le dernier compte administrateur restant
            // (l'application deviendrait inaccessible en gestion).
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role = 'admin'");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() <= 1) {
                $erreur = "Impossible de supprimer ce compte : c'est le dernier administrateur restant.";
            }
        }

        if ($erreur === '') {
            // Un utilisateur qui a déjà des factures (commandes) ne peut pas être
            // supprimé définitivement : la suppression effacerait aussi tout son
            // historique de commandes (ON DELETE CASCADE sur factures.utilisateur_id).
            // On protège cet historique en bloquant la suppression et en orientant
            // vers "Désactiver", qui conserve les données mais coupe l'accès.
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE utilisateur_id = ?");
            $stmt->execute([$utilisateurId]);
            $nbFactures = (int)$stmt->fetchColumn();

            if ($nbFactures > 0) {
                $erreur = "Impossible de supprimer \"" . $cible['nom_complet'] . "\" : cet utilisateur a $nbFactures facture(s)/commande(s) enregistrée(s), et les supprimer effacerait cet historique. Utilisez plutôt \"Désactiver\" pour lui couper l'accès sans perdre les données.";
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?");
                    $stmt->execute([$utilisateurId]);
                    $message = "Utilisateur \"" . $cible['nom_complet'] . "\" (" . $cible['nom_utilisateur'] . ") supprimé définitivement.";
                } catch (PDOException $e) {
                    $erreur = "Erreur lors de la suppression : " . $e->getMessage();
                }
            }
        }
    }
}

// ===================================================================
// BLOCS & BATIMENTS
// ===================================================================

// ---------- Création d'un bloc avec un ou plusieurs bâtiments ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'creer_bloc') {
    $nom            = trim($_POST['nom'] ?? '');
    $lotCode        = trim($_POST['lot_code'] ?? '');
    $superficie     = (int)($_POST['superficie'] ?? 0);
    $effectifEntree = (int)($_POST['effectif_entree'] ?? 0);

    $batNoms      = $_POST['batiment_nom'] ?? [];
    $batEffectifs = $_POST['batiment_effectif'] ?? [];
    $batSouches   = $_POST['batiment_souche'] ?? [];
    $batAges      = $_POST['batiment_age'] ?? [];

    // On ne garde que les lignes de bâtiment où un nom a été saisi
    $batiments = [];
    foreach ($batNoms as $i => $bn) {
        $bn = trim($bn);
        if ($bn === '') continue;
        $batiments[] = [
            'nom'       => $bn,
            'effectif'  => (int)($batEffectifs[$i] ?? 0),
            'souche'    => trim($batSouches[$i] ?? '') !== '' ? trim($batSouches[$i]) : 'LOHMANN',
            'age'       => (int)($batAges[$i] ?? 0),
        ];
    }

    if ($nom === '' || $lotCode === '') {
        $erreur = "Le nom du bloc et le code du lot sont obligatoires.";
    } elseif (count($batiments) < 1) {
        $erreur = "Un bloc doit contenir au moins un bâtiment (nom obligatoire pour chaque bâtiment ajouté).";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO blocs (exercice, speculation, nom, lot_code, superficie, effectif_entree, date_entree_effectif, etat, valide)
                VALUES (:ex, 'Pondeuse', :nom, :lot, :sup, :eff, CURDATE(), 'EN COURS', 0)
            ");
            $stmt->execute([
                'ex' => date('Y'), 'nom' => $nom, 'lot' => $lotCode,
                'sup' => $superficie, 'eff' => $effectifEntree,
            ]);
            $blocId = (int)$pdo->lastInsertId();

            $stmtBat = $pdo->prepare("
                INSERT INTO batiments (nom, bloc_id, age_semaines, souche, effectif_depart)
                VALUES (:nom, :bloc_id, :age, :souche, :effectif)
            ");
            foreach ($batiments as $b) {
                $stmtBat->execute([
                    'nom' => $b['nom'], 'bloc_id' => $blocId,
                    'age' => $b['age'], 'souche' => $b['souche'], 'effectif' => $b['effectif'],
                ]);
            }

            $pdo->commit();
            $message = "Bloc \"$nom\" créé avec " . count($batiments) . " bâtiment(s).";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreur = "Erreur lors de la création du bloc : " . $e->getMessage();
        }
    }
}

// ===================================================================
// ALERTES : seuils configurables par l'administrateur
// ===================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'modifier_parametres_alertes') {
    $seuilMortalite = (float)str_replace(',', '.', $_POST['seuil_mortalite_pct'] ?? '0');
    $seuilPonte     = (float)str_replace(',', '.', $_POST['seuil_baisse_ponte_pct'] ?? '0');
    $joursSansSaisie = max(1, (int)($_POST['jours_sans_saisie'] ?? 1));
    $emailActif     = isset($_POST['email_actif']) ? 1 : 0;

    if ($seuilMortalite < 0 || $seuilMortalite > 100 || $seuilPonte < 0 || $seuilPonte > 100) {
        $erreur = "Les seuils en pourcentage doivent être compris entre 0 et 100.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO parametres_alertes (id, seuil_mortalite_pct, seuil_baisse_ponte_pct, jours_sans_saisie, email_actif)
            VALUES (1, :m, :p, :j, :e)
            ON DUPLICATE KEY UPDATE
                seuil_mortalite_pct = VALUES(seuil_mortalite_pct),
                seuil_baisse_ponte_pct = VALUES(seuil_baisse_ponte_pct),
                jours_sans_saisie = VALUES(jours_sans_saisie),
                email_actif = VALUES(email_actif)
        ");
        $stmt->execute(['m' => $seuilMortalite, 'p' => $seuilPonte, 'j' => $joursSansSaisie, 'e' => $emailActif]);
        $message = "Seuils d'alerte mis à jour.";
    }
}

// ===================================================================
// Données d'affichage
// ===================================================================

$utilisateurs = $pdo->query("
    SELECT u.*, b.nom AS bloc_nom FROM utilisateurs u LEFT JOIN blocs b ON b.id = u.bloc_id ORDER BY u.role DESC, u.nom_complet
")->fetchAll();

$moiConnecte = utilisateurConnecte();

$blocs = getBlocsAvecStats($pdo);

$batimentsParBloc = [];
$tousLesBatiments = $pdo->query("SELECT * FROM batiments ORDER BY bloc_id, nom")->fetchAll();
foreach ($tousLesBatiments as $b) {
    $batimentsParBloc[$b['bloc_id']][] = $b;
}

$parametresAlertes = getParametresAlertes($pdo);

require_once 'includes/header.php';
?>

<div class="page-header"><h1>Paramétrage</h1></div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert alert-error"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="sub-nav">
    <ul>
        <li><a href="#tab-utilisateurs" data-tab="tab-utilisateurs">Utilisateurs (ouvriers)</a></li>
        <li><a href="#tab-blocs" data-tab="tab-blocs">Blocs &amp; Bâtiments</a></li>
        <li><a href="#tab-alertes" data-tab="tab-alertes">Alertes</a></li>
    </ul>
</div>

<!-- ============================= UTILISATEURS ============================= -->
<div id="tab-utilisateurs" class="tab-panel">

<div class="cards-row">
    <div class="card">
        <h3>Créer un utilisateur (= un ouvrier)</h3>
        <p class="hint">Chaque utilisateur non-admin est un ouvrier qui voit et contrôle <b>un seul bloc</b>.</p>
        <form method="post">
            <input type="hidden" name="action" value="creer_utilisateur">
            <div class="form-group"><label>Identifiant</label><input type="text" name="nom_utilisateur" required></div>
            <div class="form-group"><label>Nom complet</label><input type="text" name="nom_complet" required></div>
            <div class="form-group"><label>Téléphone</label><input type="text" name="telephone"></div>
            <div class="form-group"><label>Email (pour les alertes)</label><input type="email" name="email"></div>
            <div class="form-group">
                <label>Fonction</label>
                <input type="text" name="fonction" value="Ouvrier responsable de bloc">
            </div>
            <div class="form-group"><label>Mot de passe</label><input type="password" name="mot_de_passe" required></div>
            <div class="form-group">
                <label>Rôle</label>
                <select name="role" id="roleSelect">
                    <option value="utilisateur">Utilisateur (ouvrier)</option>
                    <option value="admin">Administrateur</option>
                </select>
            </div>
            <div class="form-group" id="blocFieldWrap">
                <label>Bloc à voir/contrôler (un seul)</label>
                <select name="bloc_id">
                    <option value="">-- Aucun --</option>
                    <?php foreach ($blocs as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['nom']) ?> (<?= htmlspecialchars($b['lot_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-submit">Créer l'utilisateur</button>
        </form>
    </div>
</div>

<h2 class="section-title">Utilisateurs (ouvriers)</h2>
<div class="table-wrapper">
<table class="tdb">
<thead><tr><th>Identifiant</th><th>Nom complet</th><th>Téléphone</th><th>Email</th><th>Fonction</th><th>Rôle</th><th>Bloc</th><th>Statut</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($utilisateurs as $u): ?>
    <tr>
        <td><?= htmlspecialchars($u['nom_utilisateur']) ?></td>
        <td><?= htmlspecialchars($u['nom_complet']) ?></td>
        <td><?= htmlspecialchars($u['telephone'] ?? '—') ?></td>
        <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
        <td><?= htmlspecialchars($u['fonction'] ?? '—') ?></td>
        <td><span class="badge badge-<?= $u['role']==='admin'?'validee':'en_attente' ?>"><?= ucfirst($u['role']) ?></span></td>
        <td><?= htmlspecialchars($u['bloc_nom'] ?? '—') ?></td>
        <td><?= $u['actif'] ? '<span class="badge badge-validee">Actif</span>' : '<span class="badge badge-annulee">Inactif</span>' ?></td>
        <td>
            <button type="button" class="btn-edit btn-toggle-edit" data-target="edit-user-<?= $u['id'] ?>">Modifier</button>
            <form method="post" class="form-inline-compact">
                <input type="hidden" name="action" value="toggle_utilisateur">
                <input type="hidden" name="utilisateur_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn-edit"><?= $u['actif'] ? 'Désactiver' : 'Activer' ?></button>
            </form>
            <?php if (!($moiConnecte && (int)$moiConnecte['id'] === (int)$u['id'])): ?>
            <form method="post" class="form-inline-compact form-delete-user"
                  data-nom="<?= htmlspecialchars($u['nom_complet']) ?>">
                <input type="hidden" name="action" value="supprimer_utilisateur">
                <input type="hidden" name="utilisateur_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn-edit btn-danger">Supprimer définitivement</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <tr id="edit-user-<?= $u['id'] ?>" class="row-edit-form">
        <td colspan="9">
            <form method="post" class="form-inline">
                <input type="hidden" name="action" value="modifier_utilisateur">
                <input type="hidden" name="utilisateur_id" value="<?= $u['id'] ?>">
                <div class="form-group">
                    <label>Nom complet</label>
                    <input type="text" name="nom_complet" value="<?= htmlspecialchars($u['nom_complet']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="text" name="telephone" value="<?= htmlspecialchars($u['telephone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Email (pour les alertes)</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($u['email'] ?? '') ?>">
                </div>
                <?php if ($u['role'] !== 'admin'): ?>
                <div class="form-group">
                    <label>Fonction</label>
                    <input type="text" name="fonction" value="<?= htmlspecialchars($u['fonction'] ?? 'Ouvrier responsable de bloc') ?>">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label>Nouveau mot de passe</label>
                    <input type="password" name="nouveau_mot_de_passe" placeholder="laisser vide = inchangé">
                </div>
                <?php if ($u['role'] !== 'admin'): ?>
                <div class="form-group">
                    <label>Bloc à voir/contrôler (un seul)</label>
                    <select name="bloc_id">
                        <option value="">-- Aucun --</option>
                        <?php foreach ($blocs as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= (int)$u['bloc_id']===(int)$b['id']?'selected':'' ?>><?= htmlspecialchars($b['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn-submit">Enregistrer </button>
            </form>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<!-- ============================= BLOCS & BATIMENTS ============================= -->
<div id="tab-blocs" class="tab-panel">

<div class="cards-row">
    <div class="card" style="max-width:640px;">
        <h3>Créer un bloc</h3>
        <p class="hint">Un bloc contient directement un ou plusieurs bâtiments (plus de région/société/centre). Ajoutez au moins un bâtiment avec son nom et son effectif.</p>
        <form method="post" id="formCreerBloc">
            <input type="hidden" name="action" value="creer_bloc">
            <div class="form-group"><label>Nom du bloc</label><input type="text" name="nom" required></div>
            <div class="form-group"><label>Code lot</label><input type="text" name="lot_code" placeholder="ex: 26L01X01" required></div>
            <div class="form-group"><label>Superficie</label><input type="number" name="superficie" min="0" value="0"></div>
            <div class="form-group"><label>Effectif entrée (total, info)</label><input type="number" name="effectif_entree" min="0" value="0"></div>

            <h4 style="margin:14px 0 6px;">Bâtiments du bloc *</h4>
            <div id="batimentsContainer">
                <div class="batiment-row">
                    <input type="text" name="batiment_nom[]" placeholder="Nom du bâtiment" required>
                    <input type="number" name="batiment_effectif[]" placeholder="Effectif" min="0">
                    <input type="text" name="batiment_souche[]" placeholder="Souche (LOHMANN)">
                    <input type="number" name="batiment_age[]" placeholder="Age (sem.)" min="0">
                    <button type="button" class="btn-remove-row" onclick="this.closest('.batiment-row').remove()">✕</button>
                </div>
            </div>
            <button type="button" class="btn-add-row" id="btnAjouterBatiment">+ Ajouter un bâtiment</button>

            <div class="form-actions">
                <button type="submit" class="btn-submit">Créer le bloc</button>
            </div>
        </form>
    </div>
</div>

<h2 class="section-title">Blocs existants</h2>
<div class="table-wrapper">
<table class="tdb">
<thead><tr><th>Nom</th><th>Lot</th><th>Nb bâtiments</th><th>Effectif total</th><th>État</th><th>Bâtiments</th></tr></thead>
<tbody>
<?php foreach ($blocs as $b): ?>
    <tr>
        <td><?= htmlspecialchars($b['nom']) ?></td>
        <td><?= htmlspecialchars($b['lot_code']) ?></td>
        <td class="num"><?= (int)$b['nb_batiments'] ?></td>
        <td class="num"><?= fmtNum($b['effectif_total']) ?></td>
        <td><span class="badge badge-<?= $b['etat']==='EN COURS' ? 'en_attente' : 'validee' ?>"><?= htmlspecialchars($b['etat']) ?></span></td>
        <td>
            <?php if (!empty($batimentsParBloc[$b['id']])): ?>
                <ul class="mini-list">
                <?php foreach ($batimentsParBloc[$b['id']] as $bat): ?>
                    <li><?= htmlspecialchars($bat['nom']) ?> — <?= fmtNum($bat['effectif_depart']) ?> suj. (<?= htmlspecialchars($bat['souche']) ?>, <?= $bat['age_semaines'] ?> sem.)</li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <span class="hint">Aucun</span>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<!-- ============================= ALERTES ============================= -->
<div id="tab-alertes" class="tab-panel">
<div class="cards-row">
    <div class="card" style="max-width:560px;">
        <h3>Seuils des alertes automatiques</h3>
        <p class="hint" style="padding:0 0 10px;">Ces seuils s'appliquent à tous les blocs/bâtiments. Dès qu'une saisie journalière dépasse un seuil, une alerte est créée, affichée en bandeau à l'administrateur ET à l'ouvrier du bloc concerné, et conservée définitivement dans l'historique (page "Historique").</p>
        <form method="post">
            <input type="hidden" name="action" value="modifier_parametres_alertes">

            <div class="form-group">
                <label>Seuil de mortalité (% de l'effectif présent, par jour)</label>
                <input type="number" step="0.01" min="0" max="100" name="seuil_mortalite_pct"
                       value="<?= htmlspecialchars($parametresAlertes['seuil_mortalite_pct']) ?>" required>
            </div>

            <div class="form-group">
                <label>Seuil de chute du taux de ponte (% de baisse vs objectif du jour)</label>
                <input type="number" step="0.01" min="0" max="100" name="seuil_baisse_ponte_pct"
                       value="<?= htmlspecialchars($parametresAlertes['seuil_baisse_ponte_pct']) ?>" required>
            </div>

            <div class="form-group">
                <label>Absence de saisie : alerter après combien de jours</label>
                <input type="number" step="1" min="1" name="jours_sans_saisie"
                       value="<?= htmlspecialchars($parametresAlertes['jours_sans_saisie']) ?>" required>
            </div>

            <div class="form-group">
                <label><input type="checkbox" name="email_actif" style="width:auto;vertical-align:middle;" <?= $parametresAlertes['email_actif'] ? 'checked' : '' ?>>
                    Envoyer aussi les alertes par email</label>
                <p class="hint" style="padding:4px 0 0;">Envoyée à tous les administrateurs et à l'ouvrier du bloc concerné qui ont une adresse email renseignée (onglet "Utilisateurs"). Nécessite d'avoir renseigné les identifiants SMTP (SMTP_USER / SMTP_PASS) dans config.php — voir LISEZMOI.md, section "Alertes par email".</p>
            </div>

            <button type="submit" class="btn-submit">Enregistrer les seuils</button>
        </form>
    </div>
</div>
</div>

<script>
// Masque le champ "bloc" si le rôle choisi est admin
document.getElementById('roleSelect').addEventListener('change', function() {
    document.getElementById('blocFieldWrap').style.display = this.value === 'admin' ? 'none' : 'block';
});

// Ajout dynamique de lignes "bâtiment" dans le formulaire de création de bloc
document.getElementById('btnAjouterBatiment').addEventListener('click', function() {
    const container = document.getElementById('batimentsContainer');
    const row = document.createElement('div');
    row.className = 'batiment-row';
    row.innerHTML = `
        <input type="text" name="batiment_nom[]" placeholder="Nom du bâtiment" required>
        <input type="number" name="batiment_effectif[]" placeholder="Effectif" min="0">
        <input type="text" name="batiment_souche[]" placeholder="Souche (LOHMANN)">
        <input type="number" name="batiment_age[]" placeholder="Age (sem.)" min="0">
        <button type="button" class="btn-remove-row" onclick="this.closest('.batiment-row').remove()">✕</button>
    `;
    container.appendChild(row);
});

// Boutons "Modifier" qui affichent/masquent la ligne d'édition inline d'un utilisateur
document.querySelectorAll('.btn-toggle-edit').forEach(btn => {
    btn.addEventListener('click', () => {
        const row = document.getElementById(btn.dataset.target);
        if (row) row.classList.toggle('active-inline');
    });
});

// Confirmation obligatoire avant suppression DÉFINITIVE d'un utilisateur
document.querySelectorAll('.form-delete-user').forEach(form => {
    form.addEventListener('submit', function (e) {
        const nom = this.dataset.nom || 'cet utilisateur';
        const ok = confirm(
            'Supprimer DÉFINITIVEMENT "' + nom + '" ?\n\n' +
            'Cette action est irréversible. Le compte ne pourra plus se connecter ' +
            'et son historique de connexion sera perdu.\n\n' +
            'Astuce : si vous voulez seulement lui couper l\'accès sans perdre ses ' +
            'données, utilisez plutôt "Désactiver".'
        );
        if (!ok) e.preventDefault();
    });
});
</script>

<script src="assets/script.js"></script>

<?php require_once 'includes/footer.php'; ?>
