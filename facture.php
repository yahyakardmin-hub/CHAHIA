<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'Factures';
$activePage = 'facture';
$user = utilisateurConnecte();
$message = '';
$erreur  = '';

// -----------------------------------------------------------------
// ADMIN : définir le prix de l'oeuf du jour
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'definir_prix') {
    requireAdmin();
    $datePrix = $_POST['date_prix'] ?? date('Y-m-d');
    $prix     = (float)($_POST['prix_unitaire'] ?? 0);
    if ($prix <= 0) {
        $erreur = "Le prix doit être supérieur à 0.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO prix_oeuf (date_prix, prix_unitaire, defini_par) VALUES (:d, :p, :u)
            ON DUPLICATE KEY UPDATE prix_unitaire = VALUES(prix_unitaire), defini_par = VALUES(defini_par)
        ");
        $stmt->execute(['d' => $datePrix, 'p' => $prix, 'u' => $user['id']]);
        $message = "Prix de l'oeuf enregistré pour le " . date('d/m/Y', strtotime($datePrix)) . " : " . number_format($prix, 3, ',', ' ') . " DT.";
    }
}

// -----------------------------------------------------------------
// ADMIN : créer directement une facture (pour un bloc, plusieurs blocs
// choisis, ou tous les blocs = stock total). Quantité vérifiée par
// rapport au stock réellement disponible avant toute création.
// Une facture créée par l'admin est validée automatiquement : elle n'a
// pas besoin d'une validation d'administrateur puisqu'elle en émane déjà.
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'commander_admin') {
    requireAdmin();
    $quantiteAdmin = (int)($_POST['quantite_admin'] ?? 0);
    $modeBloc      = $_POST['mode_bloc'] ?? 'unique';
    $dateFacture   = date('Y-m-d');
    $prixDuJour    = getPrixOeufJour($pdo, $dateFacture);

    if ($prixDuJour === null) {
        $erreur = "Le prix de l'oeuf n'a pas encore été fixé aujourd'hui : impossible de créer une facture.";
    } elseif ($quantiteAdmin <= 0) {
        $erreur = "La quantité doit être supérieure à 0.";
    } else {
        if ($modeBloc === 'tous') {
            $blocIdsChoisis = array_column($pdo->query("SELECT id FROM blocs ORDER BY nom")->fetchAll(), 'id');
        } elseif ($modeBloc === 'multi') {
            $blocIdsChoisis = array_map('intval', $_POST['blocs_multi'] ?? []);
        } else { // 'unique'
            $blocIdsChoisis = !empty($_POST['bloc_unique']) ? [(int)$_POST['bloc_unique']] : [];
        }
        $blocIdsChoisis = array_values(array_unique(array_filter($blocIdsChoisis)));

        if (!$blocIdsChoisis) {
            $erreur = "Merci de choisir au moins un bloc.";
        } else {
            $repartition = repartirStockSurBlocs($pdo, $blocIdsChoisis, $quantiteAdmin);
            if ($repartition === null) {
                $stockDispoTotal = 0;
                foreach ($blocIdsChoisis as $bid) { $stockDispoTotal += getStockBloc($pdo, $bid)['stock_disponible']; }
                $erreur = "Quantité demandée (" . fmtNum($quantiteAdmin) . ") supérieure au stock disponible total des blocs sélectionnés (" . fmtNum($stockDispoTotal) . "). Facture non créée.";
            } else {
                $numero = prochainNumeroFacture($pdo);
                $stmtIns = $pdo->prepare("
                    INSERT INTO factures (numero_facture, date_facture, utilisateur_id, bloc_id, quantite, prix_unitaire, statut)
                    VALUES (:num, :date, :uid, :bloc, :qte, :prix, 'validee')
                ");
                $detailBlocs = [];
                foreach ($repartition as $bid => $qtePriseSurBloc) {
                    $stmtIns->execute([
                        'num' => $numero, 'date' => $dateFacture, 'uid' => $user['id'],
                        'bloc' => $bid, 'qte' => $qtePriseSurBloc, 'prix' => $prixDuJour,
                    ]);
                    $stNom = $pdo->prepare("SELECT nom FROM blocs WHERE id = ?");
                    $stNom->execute([$bid]);
                    $detailBlocs[] = $stNom->fetchColumn() . " : " . fmtNum($qtePriseSurBloc);
                }
                $message = "Facture $numero créée et validée automatiquement (" . fmtNum($quantiteAdmin) . " oeufs — " . fmtMontant($quantiteAdmin * $prixDuJour) . "). Répartition : " . implode(', ', $detailBlocs) . ".";
            }
        }
    }
}

// -----------------------------------------------------------------
// UTILISATEUR : commander (créer une facture) - quantité limitée par le stock du bloc
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'commander') {
    if (!$user['bloc_id']) {
        $erreur = "Votre compte n'est rattaché à aucun bloc. Contactez l'administrateur.";
    } else {
        $quantite = (int)($_POST['quantite'] ?? 0);
        $dateFacture = date('Y-m-d');
        $prixDuJour = getPrixOeufJour($pdo, $dateFacture);
        $stock = getStockBloc($pdo, (int)$user['bloc_id']);

        if ($prixDuJour === null) {
            $erreur = "Le prix de l'oeuf n'a pas encore été fixé par l'administrateur aujourd'hui.";
        } elseif ($quantite <= 0) {
            $erreur = "La quantité doit être supérieure à 0.";
        } elseif ($quantite > $stock['stock_disponible']) {
            $erreur = "Quantité demandée (" . fmtNum($quantite) . ") supérieure au stock disponible (" . fmtNum($stock['stock_disponible']) . ").";
        } else {
            $numero = prochainNumeroFacture($pdo);
            $stmt = $pdo->prepare("
                INSERT INTO factures (numero_facture, date_facture, utilisateur_id, bloc_id, quantite, prix_unitaire, statut)
                VALUES (:num, :date, :uid, :bloc, :qte, :prix, 'en_attente')
            ");
            $stmt->execute([
                'num' => $numero, 'date' => $dateFacture, 'uid' => $user['id'],
                'bloc' => $user['bloc_id'], 'qte' => $quantite, 'prix' => $prixDuJour,
            ]);
            $message = "Commande $numero enregistrée : " . fmtNum($quantite) . " oeufs x " . number_format($prixDuJour, 3, ',', ' ') . " DT = " . fmtMontant($quantite * $prixDuJour);
        }
    }
}

// -----------------------------------------------------------------
// ADMIN : valider / annuler une facture
// -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'changer_statut') {
    requireAdmin();
    $factureId = (int)($_POST['facture_id'] ?? 0);
    $statut = $_POST['nouveau_statut'] ?? '';
    if (in_array($statut, ['validee', 'annulee', 'en_attente'], true)) {
        $stmt = $pdo->prepare("UPDATE factures SET statut = ? WHERE id = ?");
        $stmt->execute([$statut, $factureId]);
        $message = "Statut de la facture mis à jour.";
    }
}

// -----------------------------------------------------------------
// Données d'affichage
// -----------------------------------------------------------------
$prixAujourdhui = getPrixOeufJour($pdo, date('Y-m-d'));
$historiquePrix = $pdo->query("SELECT p.*, u.nom_complet FROM prix_oeuf p LEFT JOIN utilisateurs u ON u.id = p.defini_par ORDER BY date_prix DESC LIMIT 15")->fetchAll();

// -----------------------------------------------------------------
// Filtre de date (Du / Au) sur la liste des factures — utilisé notamment
// par le bouton "Imprimer les Factures" de la page Reporting, mais aussi
// disponible directement sur cette page.
// -----------------------------------------------------------------
$debutFacture   = $_GET['debut'] ?? '';
$finFacture     = $_GET['fin']   ?? '';
$modeImpression = isset($_GET['print']) && $_GET['print'] === '1';
$filtreActif    = $debutFacture !== '' || $finFacture !== '';

if (estAdmin()) {
    $sql = "
        SELECT f.*, u.nom_complet, b.nom AS bloc_nom
        FROM factures f
        JOIN utilisateurs u ON u.id = f.utilisateur_id
        JOIN blocs b ON b.id = f.bloc_id
    ";
    $params = [];
    $conditions = [];
    if ($debutFacture !== '') { $conditions[] = 'f.date_facture >= ?'; $params[] = $debutFacture; }
    if ($finFacture   !== '') { $conditions[] = 'f.date_facture <= ?'; $params[] = $finFacture; }
    if ($conditions) { $sql .= ' WHERE ' . implode(' AND ', $conditions); }
    $sql .= ' ORDER BY f.date_facture DESC, f.id DESC';
    if (!$filtreActif) { $sql .= ' LIMIT 100'; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $factures = $stmt->fetchAll();
} else {
    $sql = "
        SELECT f.*, b.nom AS bloc_nom
        FROM factures f JOIN blocs b ON b.id = f.bloc_id
        WHERE f.utilisateur_id = ?
    ";
    $params = [$user['id']];
    if ($debutFacture !== '') { $sql .= ' AND f.date_facture >= ?'; $params[] = $debutFacture; }
    if ($finFacture   !== '') { $sql .= ' AND f.date_facture <= ?'; $params[] = $finFacture; }
    $sql .= ' ORDER BY f.date_facture DESC, f.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $factures = $stmt->fetchAll();
}

$monBloc = null; $monStock = null;
if (!estAdmin() && $user['bloc_id']) {
    $stmt = $pdo->prepare("SELECT * FROM blocs WHERE id = ?");
    $stmt->execute([$user['bloc_id']]);
    $monBloc = $stmt->fetch();
    $monStock = getStockBloc($pdo, (int)$user['bloc_id']);
}

$stocksBlocsAdmin = estAdmin() ? getStockTousBlocs($pdo) : [];
$stockTotalTousBlocs = array_sum(array_column($stocksBlocsAdmin, 'stock_disponible'));

// -----------------------------------------------------------------
// Export CSV des factures (respecte le même périmètre que l'affichage :
// toutes les factures pour l'admin, uniquement les siennes pour un
// utilisateur), sur la période filtrée par Du/Au. Utilisé par le bouton
// "Télécharger les Factures" de la page Reporting, et disponible ici
// directement.
// -----------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $ancienDisplayErrors = ini_set('display_errors', '0');

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="factures_' . ($debutFacture ?: 'toutes') . '_' . ($finFacture ?: 'dates') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    $entetes = ['N° Facture', 'Date'];
    if (estAdmin()) { $entetes[] = 'Utilisateur'; }
    $entetes = array_merge($entetes, ['Bloc', 'Quantité', 'Prix unitaire', 'Montant', 'Statut']);
    fputcsv($out, $entetes, ';', '"', '\\');
    foreach ($factures as $f) {
        $ligne = [$f['numero_facture'], date('d/m/Y', strtotime($f['date_facture']))];
        if (estAdmin()) { $ligne[] = $f['nom_complet']; }
        $ligne = array_merge($ligne, [
            $f['bloc_nom'], $f['quantite'],
            number_format($f['prix_unitaire'], 3, ',', ''),
            number_format($f['montant_total'], 3, ',', ''),
            ucfirst(str_replace('_', ' ', $f['statut'])),
        ]);
        fputcsv($out, $ligne, ';', '"', '\\');
    }
    fclose($out);
    if ($ancienDisplayErrors !== false) { ini_set('display_errors', $ancienDisplayErrors); }
    exit;
}

require_once 'includes/header.php';
?>

<div class="page-header">
    <h1>Factures</h1>
    <?php if ($filtreActif): ?>
        <p class="hint">Période : du <?= $debutFacture !== '' ? date('d/m/Y', strtotime($debutFacture)) : '…' ?>
           au <?= $finFacture !== '' ? date('d/m/Y', strtotime($finFacture)) : "aujourd'hui" ?></p>
    <?php endif; ?>
    <form method="get" class="date-filter no-print">
        <label for="debut">Du</label>
        <input type="date" id="debut" name="debut" value="<?= htmlspecialchars($debutFacture) ?>">
        <label for="fin">Au</label>
        <input type="date" id="fin" name="fin" value="<?= htmlspecialchars($finFacture) ?>">
        <button type="submit" class="btn-saisie">Filtrer</button>
    </form>
</div>

<?php if ($message): ?><div class="alert alert-success no-print"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($erreur): ?><div class="alert alert-error no-print"><?= htmlspecialchars($erreur) ?></div><?php endif; ?>

<div class="cards-row no-print">

<?php if (estAdmin()): ?>
    <div class="card">
        <h3>Prix de l'oeuf du jour</h3>
        <form method="post" class="form-inline">
            <input type="hidden" name="action" value="definir_prix">
            <div class="form-group">
                <label for="date_prix">Date</label>
                <input type="date" id="date_prix" name="date_prix" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label for="prix_unitaire">Prix unitaire (DT/oeuf)</label>
                <input type="number" id="prix_unitaire" name="prix_unitaire" step="0.001" min="0" required
                       value="<?= $prixAujourdhui !== null ? $prixAujourdhui : '' ?>">
            </div>
            <button type="submit" class="btn-submit">Enregistrer le prix</button>
        </form>
    </div>

    <div class="card" style="width:100%;">
        <h3>Créer une facture (Admin)</h3>
        <p class="hint" style="padding:0 0 8px;">Facture créée directement par l'administrateur : elle est <b>validée automatiquement</b>, aucune validation supplémentaire n'est nécessaire. La quantité demandée est vérifiée par rapport au stock réellement disponible avant toute création.</p>

        <form method="post" id="formFactureAdmin">
            <input type="hidden" name="action" value="commander_admin">

            <label class="hint" style="padding:0;">Stock à utiliser</label>
            <div class="radio-list">
                <label><input type="radio" name="mode_bloc" value="unique" checked onchange="majModeFactureAdmin()"> Un seul bloc</label>
                <label><input type="radio" name="mode_bloc" value="multi" onchange="majModeFactureAdmin()"> Plusieurs blocs</label>
                <label><input type="radio" name="mode_bloc" value="tous" onchange="majModeFactureAdmin()"> Tous les blocs (stock total)</label>
            </div>

            <div id="blocUniqueBox" class="form-group" style="max-width:320px;">
                <label for="bloc_unique">Bloc</label>
                <select id="bloc_unique" name="bloc_unique">
                    <?php foreach ($stocksBlocsAdmin as $b): ?>
                        <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['nom']) ?> — stock dispo : <?= fmtNum($b['stock_disponible']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="blocsMultiBox" class="checkbox-list" style="display:none;">
                <?php foreach ($stocksBlocsAdmin as $b): ?>
                    <label><input type="checkbox" name="blocs_multi[]" value="<?= $b['id'] ?>"> <?= htmlspecialchars($b['nom']) ?> (<?= fmtNum($b['stock_disponible']) ?>)</label>
                <?php endforeach; ?>
            </div>

            <div id="tousBlocsInfo" class="hint" style="display:none;padding:0 0 8px;">Stock disponible cumulé de tous les blocs : <b><?= fmtNum($stockTotalTousBlocs) ?></b> oeufs.</div>

            <div class="form-group" style="max-width:260px;">
                <label for="quantite_admin">Quantité d'oeufs</label>
                <input type="number" id="quantite_admin" name="quantite_admin" min="1" required>
            </div>

            <button type="submit" class="btn-submit" <?= $prixAujourdhui===null ? 'disabled' : '' ?>>Créer la facture</button>
            <?php if ($prixAujourdhui === null): ?><p class="hint-error">Le prix du jour n'a pas encore été fixé.</p><?php endif; ?>
        </form>
    </div>
    <script>
        function majModeFactureAdmin() {
            const mode = document.querySelector('input[name="mode_bloc"]:checked').value;
            document.getElementById('blocUniqueBox').style.display = mode === 'unique' ? '' : 'none';
            document.getElementById('blocsMultiBox').style.display = mode === 'multi' ? '' : 'none';
            document.getElementById('tousBlocsInfo').style.display = mode === 'tous' ? '' : 'none';
        }
    </script>
<?php else: ?>
    <div class="card">
        <h3>Mon bloc : <?= $monBloc ? htmlspecialchars($monBloc['nom']) : '—' ?></h3>
        <?php if ($monBloc): ?>
        <table class="mini-table">
            <tr><td>Production cumulée</td><td class="num"><?= fmtNum($monStock['production_totale']) ?></td></tr>
            <tr><td>Déjà commandé</td><td class="num"><?= fmtNum($monStock['quantite_facturee']) ?></td></tr>
            <tr><td><b>Stock disponible</b></td><td class="num"><b id="stockDisponible"><?= fmtNum($monStock['stock_disponible']) ?></b></td></tr>
            <tr><td>Prix du jour</td><td class="num"><?= $prixAujourdhui !== null ? number_format($prixAujourdhui,3,',',' ').' DT' : 'non défini' ?></td></tr>
        </table>
        <?php else: ?>
            <p>Votre compte n'est rattaché à aucun bloc.</p>
        <?php endif; ?>
    </div>

    <?php if ($monBloc): ?>
    <div class="card">
        <h3>Nouvelle commande</h3>
        <form method="post" id="formCommande">
            <input type="hidden" name="action" value="commander">
            <div class="form-group">
                <label for="quantite">Quantité d'oeufs souhaitée</label>
                <input type="number" id="quantite" name="quantite" min="1" max="<?= (int)$monStock['stock_disponible'] ?>" required>
            </div>
            <div class="apercu-box">
                Montant estimé : <span id="montantEstime">0,000 DT</span>
            </div>
            <button type="submit" class="btn-submit" <?= $prixAujourdhui===null ? 'disabled' : '' ?>>Passer la commande</button>
            <?php if ($prixAujourdhui === null): ?><p class="hint-error">Le prix du jour n'a pas encore été fixé par l'admin.</p><?php endif; ?>
        </form>
    </div>
    <?php endif; ?>
<?php endif; ?>

    <div class="card">
        <h3>Historique des prix</h3>
        <table class="mini-table">
            <thead><tr><th>Date</th><th>Prix (DT)</th><th>Défini par</th></tr></thead>
            <tbody>
            <?php foreach ($historiquePrix as $p): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($p['date_prix'])) ?></td>
                    <td class="num"><?= number_format($p['prix_unitaire'],3,',',' ') ?></td>
                    <td><?= htmlspecialchars($p['nom_complet'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<h2 class="section-title"><?= estAdmin() ? 'Toutes les factures' : 'Mes factures' ?></h2>
<div class="table-wrapper">
<table class="tdb">
<thead>
<tr>
    <th>N° Facture</th><th>Date</th>
    <?php if (estAdmin()): ?><th>Utilisateur</th><?php endif; ?>
    <th>Bloc</th><th>Quantité</th><th>Prix unitaire</th><th>Montant</th><th>Statut</th>
    <?php if (estAdmin()): ?><th class="no-print">Action</th><?php endif; ?>
</tr>
</thead>
<tbody>
<?php foreach ($factures as $f): ?>
    <tr>
        <td><?= htmlspecialchars($f['numero_facture']) ?></td>
        <td><?= date('d/m/Y', strtotime($f['date_facture'])) ?></td>
        <?php if (estAdmin()): ?><td><?= htmlspecialchars($f['nom_complet']) ?></td><?php endif; ?>
        <td><?= htmlspecialchars($f['bloc_nom']) ?></td>
        <td class="num"><?= fmtNum($f['quantite']) ?></td>
        <td class="num"><?= number_format($f['prix_unitaire'],3,',',' ') ?> DT</td>
        <td class="num highlight"><?= fmtMontant($f['montant_total']) ?></td>
        <td><span class="badge badge-<?= $f['statut'] ?>"><?= ucfirst(str_replace('_',' ',$f['statut'])) ?></span></td>
        <?php if (estAdmin()): ?>
        <td class="no-print">
            <form method="post" class="form-inline-compact">
                <input type="hidden" name="action" value="changer_statut">
                <input type="hidden" name="facture_id" value="<?= $f['id'] ?>">
                <select name="nouveau_statut" onchange="this.form.submit()">
                    <option value="en_attente" <?= $f['statut']==='en_attente'?'selected':'' ?>>En attente</option>
                    <option value="validee" <?= $f['statut']==='validee'?'selected':'' ?>>Validée</option>
                    <option value="annulee" <?= $f['statut']==='annulee'?'selected':'' ?>>Annulée</option>
                </select>
            </form>
        </td>
        <?php endif; ?>
    </tr>
<?php endforeach; ?>
<?php if (!$factures): ?>
    <tr><td colspan="8" style="text-align:center;color:#888;">Aucune facture pour le moment.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<script>
// Calcul en JS du montant estimé de la commande, limité par le stock disponible
(function() {
    const qteInput = document.getElementById('quantite');
    if (!qteInput) return;
    const prixUnitaire = <?= $prixAujourdhui !== null ? $prixAujourdhui : 0 ?>;
    const stockDisponible = <?= $monStock ? (int)$monStock['stock_disponible'] : 0 ?>;
    const montantEl = document.getElementById('montantEstime');

    qteInput.addEventListener('input', () => {
        let qte = parseInt(qteInput.value) || 0;
        if (qte > stockDisponible) {
            qte = stockDisponible;
            qteInput.value = qte;
        }
        const montant = calculerMontantFacture(qte, prixUnitaire);
        montantEl.textContent = montant.toLocaleString('fr-FR', {minimumFractionDigits:3, maximumFractionDigits:3}) + ' DT';
    });
})();
</script>
<script src="assets/script.js"></script>

<?php if ($modeImpression): ?>
<script>
// Ouvert depuis le bouton "Imprimer les Factures" de la page Reporting :
// déclenche l'impression une fois que cette page est bien chargée.
window.addEventListener('load', function () { window.print(); });
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>