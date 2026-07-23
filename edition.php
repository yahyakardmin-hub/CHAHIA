<?php
require_once 'config.php';
require_once 'includes/auth.php';
require_once 'functions.php';
requireLogin();

$pageTitle  = 'Reporting';
$activePage = 'edition';

$debutReporting = $_GET['debut'] ?? date('Y-m-d', strtotime('-30 days'));
$finReporting   = $_GET['fin'] ?? date('Y-m-d');

require_once 'includes/header.php';
?>

<div class="page-header"><h1>Reporting</h1></div>

<div class="cards-row">
    <div class="card card-edition">
        <h3>Imprimer le TDB</h3>
        <p>Ouvre le tableau de bord du jour choisi dans une vue prête à l'impression.</p>
        <form onsubmit="return imprimerTDB(event)">
            <div class="form-group"><label for="date_tdb_print">Jour</label><input type="date" id="date_tdb_print" name="date_tdb_print" value="<?= date('Y-m-d') ?>"></div>
            <button type="submit" class="btn-saisie">Imprimer le TDB</button>
        </form>
    </div>
    <?php if (estAdmin()): ?>
    <div class="card card-edition">
        <h3>Export CSV — Saisies</h3>
        <p>Génère un export des saisies sur une période choisie.</p>
        <form method="get" action="reporting.php">
            <input type="hidden" name="export" value="csv">
            <div class="form-group"><label>Du</label><input type="date" name="debut" value="<?= htmlspecialchars($debutReporting) ?>"></div>
            <div class="form-group"><label>Au</label><input type="date" name="fin" value="<?= htmlspecialchars($finReporting) ?>"></div>
            <button type="submit" class="btn-saisie">Télécharger le CSV</button>
        </form>
    </div>
    <div class="card card-edition">
        <h3>Export CSV — Alertes</h3>
        <p>Télécharge l'historique des alertes (mortalité, ponte, absence de saisie) sur une période choisie.</p>
        <form method="get" action="reporting.php">
            <input type="hidden" name="export" value="csv_alertes">
            <div class="form-group"><label>Du</label><input type="date" name="debut" value="<?= htmlspecialchars($debutReporting) ?>"></div>
            <div class="form-group"><label>Au</label><input type="date" name="fin" value="<?= htmlspecialchars($finReporting) ?>"></div>
            <button type="submit" class="btn-saisie">Télécharger les alertes</button>
        </form>
    </div>
    <div class="card card-edition">
        <h3>Remarque</h3>
        <p>Télécharge l'historique des remarques (saisies par bloc et bâtiment) sur une période choisie. Le tableau complet est visible depuis la page Historique.</p>
        <form method="get" action="reporting.php">
            <input type="hidden" name="export" value="csv_remarques">
            <div class="form-group"><label>Du</label><input type="date" name="debut" value="<?= htmlspecialchars($debutReporting) ?>"></div>
            <div class="form-group"><label>Au</label><input type="date" name="fin" value="<?= htmlspecialchars($finReporting) ?>"></div>
            <button type="submit" class="btn-saisie">Télécharger les remarques</button>
        </form>
    </div>
    <?php endif; ?>
    <div class="card card-edition">
        <h3>Télécharger les Factures</h3>
        <p>Télécharge l'historique des factures (CSV) sur une période choisie.</p>
        <form method="get" action="facture.php">
            <input type="hidden" name="export" value="csv">
            <div class="form-group"><label>Du</label><input type="date" name="debut" value="<?= htmlspecialchars($debutReporting) ?>"></div>
            <div class="form-group"><label>Au</label><input type="date" name="fin" value="<?= htmlspecialchars($finReporting) ?>"></div>
            <button type="submit" class="btn-saisie">Télécharger les Factures</button>
        </form>
    </div>
</div>

<script>
// Ouvre le TDB du jour choisi dans un nouvel onglet et déclenche l'impression
// UNE FOIS que cette nouvelle page est chargée (et non sur la page
// Reporting elle-même, qui était le bug précédent : window.print()
// s'exécutait sur la fenêtre courante au lieu du nouvel onglet TDB).
function imprimerTDB(e) {
    if (e) e.preventDefault();
    const jour = document.getElementById('date_tdb_print').value;
    const url = 'TDB.php' + (jour ? '?date=' + encodeURIComponent(jour) : '');
    const fenetre = window.open(url, '_blank');
    if (!fenetre) { alert("Le navigateur a bloqué l'ouverture du TDB (pop-up). Autorisez les pop-ups pour ce site."); return false; }
    fenetre.addEventListener('load', function () {
        fenetre.focus();
        fenetre.print();
    });
    return false;
}
</script>

<?php require_once 'includes/footer.php'; ?>
