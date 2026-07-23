<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireAdmin();

// ---------- Export CSV direct (pas d'API : le fichier est généré et streamé ici) ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // On masque l'affichage des erreurs/avertissements PHP pendant l'export :
    // le moindre warning imprimé ici (ex: "Deprecated: fputcsv()...") pollue
    // directement le fichier CSV téléchargé et le rend illisible par Excel.
    $ancienDisplayErrors = ini_set('display_errors', '0');

    $debut = $_GET['debut'] ?? date('Y-m-d', strtotime('-30 days'));
    $fin   = $_GET['fin'] ?? date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT s.date_saisie, bl.nom AS bloc, b.nom AS batiment,
               s.effectif_present, s.production_totale, s.taux_ponte_objectif,
               s.consommation_eau, s.mortalite, s.pc_pct, s.sale_pct, s.casse_vendue_pct, s.casse_non_vendue_pct
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE s.date_saisie BETWEEN ? AND ?
        ORDER BY s.date_saisie, bl.nom, b.nom
    ");
    $stmt->execute([$debut, $fin]);
    $rows = $stmt->fetchAll();

    // Nettoyage de tout buffer déjà émis (au cas où) avant d'envoyer les en-têtes
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporting_' . $debut . '_' . $fin . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM pour Excel
    // Les 4 paramètres (séparateur, enclosure, escape) sont fournis explicitement :
    // depuis PHP 8.1, ne pas préciser $escape déclenche un avertissement "Deprecated"
    // qui, non masqué, s'imprime dans le flux CSV et corrompt le fichier.
    fputcsv($out, ['Date','Bloc','Bâtiment','Effectif Présent','Production Totale','Taux Objectif','Conso Eau','Mortalité','PC%','Sale%','Casse Vendue%','Casse Non Vendue%'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, $r, ';', '"', '\\');
    }
    fclose($out);
    if ($ancienDisplayErrors !== false) { ini_set('display_errors', $ancienDisplayErrors); }
    exit;
}

// ---------- Export CSV de l'historique des alertes ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv_alertes') {
    $ancienDisplayErrors = ini_set('display_errors', '0');

    $debut = $_GET['debut'] ?? date('Y-m-d', strtotime('-30 days'));
    $fin   = $_GET['fin'] ?? date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT a.date_creation, a.type_alerte, bl.nom AS bloc, b.nom AS batiment,
               a.date_alerte, a.valeur_mesuree, a.seuil_applique, a.message
        FROM alertes a
        JOIN blocs bl ON bl.id = a.bloc_id
        LEFT JOIN batiments b ON b.id = a.batiment_id
        WHERE a.date_alerte BETWEEN ? AND ?
        ORDER BY a.date_creation DESC
    ");
    $stmt->execute([$debut, $fin]);
    $rows = $stmt->fetchAll();

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="alertes_' . $debut . '_' . $fin . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date de création', 'Type', 'Bloc', 'Bâtiment', 'Date concernée', 'Valeur mesurée', 'Seuil appliqué', 'Message'], ';', '"', '\\');
    foreach ($rows as $r) {
        $r['type_alerte'] = libelleTypeAlerte($r['type_alerte']);
        fputcsv($out, $r, ';', '"', '\\');
    }
    fclose($out);
    if ($ancienDisplayErrors !== false) { ini_set('display_errors', $ancienDisplayErrors); }
    exit;
}

// ---------- Export CSV des remarques ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv_remarques') {
    $ancienDisplayErrors = ini_set('display_errors', '0');

    $debut = $_GET['debut'] ?? date('Y-m-d', strtotime('-30 days'));
    $fin   = $_GET['fin'] ?? date('Y-m-d');
    $blocIdFiltre = isset($_GET['bloc_id']) && $_GET['bloc_id'] !== '' ? (int)$_GET['bloc_id'] : null;
    $batimentIdFiltre = isset($_GET['batiment_id']) && $_GET['batiment_id'] !== '' ? (int)$_GET['batiment_id'] : null;

    $cond = ["s.remarque IS NOT NULL", "s.remarque <> ''", "s.date_saisie BETWEEN ? AND ?"];
    $params = [$debut, $fin];
    if ($batimentIdFiltre) {
        $cond[] = "s.batiment_id = ?";
        $params[] = $batimentIdFiltre;
    } elseif ($blocIdFiltre) {
        $cond[] = "b.bloc_id = ?";
        $params[] = $blocIdFiltre;
    }
    $where = implode(' AND ', $cond);

    $stmt = $pdo->prepare("
        SELECT s.date_saisie, bl.nom AS bloc, b.nom AS batiment, s.remarque
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE $where
        ORDER BY s.date_saisie DESC, bl.nom, b.nom
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="remarques_' . $debut . '_' . $fin . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Bloc', 'Bâtiment', 'Remarque'], ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, $r, ';', '"', '\\');
    }
    fclose($out);
    if ($ancienDisplayErrors !== false) { ini_set('display_errors', $ancienDisplayErrors); }
    exit;
}

require_once 'functions.php';
$pageTitle  = 'Reporting';
$activePage = 'edition';

$debut = $_GET['debut'] ?? date('Y-m-d', strtotime('-30 days'));
$fin   = $_GET['fin'] ?? date('Y-m-d');

require_once 'includes/header.php';
?>

<div class="page-header"><h1>Reporting</h1></div>

<div class="cards-row">
    <div class="card" style="max-width:600px;">
        <h3>Exporter les saisies (CSV)</h3>
        <form method="get">
            <input type="hidden" name="export" value="csv">
            <div class="form-group"><label>Du</label><input type="date" name="debut" value="<?= htmlspecialchars($debut) ?>"></div>
            <div class="form-group"><label>Au</label><input type="date" name="fin" value="<?= htmlspecialchars($fin) ?>"></div>
            <button type="submit" class="btn-submit">Télécharger le CSV</button>
        </form>
    </div>
    <div class="card" style="max-width:600px;">
        <h3>Exporter les alertes (CSV)</h3>
        <form method="get">
            <input type="hidden" name="export" value="csv_alertes">
            <div class="form-group"><label>Du</label><input type="date" name="debut" value="<?= htmlspecialchars($debut) ?>"></div>
            <div class="form-group"><label>Au</label><input type="date" name="fin" value="<?= htmlspecialchars($fin) ?>"></div>
            <button type="submit" class="btn-submit">Télécharger les alertes</button>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>