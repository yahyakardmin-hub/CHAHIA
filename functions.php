<?php
/**
 * FUNCTIONS.PHP
 * Fonctions utilitaires PHP partagées par toutes les pages :
 *  - formatage (nombres, pourcentages, montants)
 *  - calculs du TDB journalier (taux de ponte, écarts, % tri...)
 *  - accès aux saisies / stock / factures / prix de l'oeuf
 *
 * Remarque : les statistiques et graphiques de TDB_graphe.php sont
 * volontairement calculés côté JS (assets/charts.js) à partir des
 * saisies brutes. Les fonctions ci-dessous servent au TDB journalier
 * (calcul ligne par ligne, un jour donné) et aux pages Facture/Stock.
 */

// -----------------------------------------------------------------
// Formatage
// -----------------------------------------------------------------
function fmtNum($v): string
{
    if ($v === null || $v === '') return '-';
    return number_format((float)$v, 0, ',', ' ');
}

function fmtPct($v): string
{
    if ($v === null) return '-';
    return number_format((float)$v, 2, ',', ' ') . ' %';
}

function fmtMontant($v): string
{
    if ($v === null) return '-';
    return number_format((float)$v, 3, ',', ' ') . ' DT';
}

/** Classe CSS selon le signe d'un écart (vert si positif, rouge si négatif) */
function classeEcart($v): string
{
    if ($v === null) return '';
    $v = (float)$v;
    if ($v > 0) return 'positif';
    if ($v < 0) return 'negatif';
    return 'neutre';
}

// -----------------------------------------------------------------
// TDB journalier : récupération et calculs
// -----------------------------------------------------------------

/** Retourne les saisies d'une date donnée, indexées par batiment_id */
function getSaisies(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare("SELECT * FROM saisies WHERE date_saisie = ?");
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt->fetchAll() as $s) {
        $out[$s['batiment_id']] = $s;
    }
    return $out;
}

/**
 * Calcule toutes les valeurs affichées sur une ligne bâtiment du TDB
 * (taux réalisé J / J-1, écarts, consommation, mortalité, % tri...)
 */
function calculerLigne(array $sJour, ?array $sVeille): array
{
    $effectifPresent = (float)($sJour['effectif_present'] ?? 0);
    $production      = (float)($sJour['production_totale'] ?? 0);
    $objectif        = (float)($sJour['taux_ponte_objectif'] ?? 0);

    $tauxRealiseJ = $effectifPresent > 0 ? ($production / $effectifPresent) * 100 : 0;

    $tauxRealiseJ1 = 0;
    $mortaliteJ1   = 0;
    $consoJ1       = 0;
    if ($sVeille) {
        $effJ1  = (float)($sVeille['effectif_present'] ?? 0);
        $prodJ1 = (float)($sVeille['production_totale'] ?? 0);
        $tauxRealiseJ1 = $effJ1 > 0 ? ($prodJ1 / $effJ1) * 100 : 0;
        $mortaliteJ1   = (float)($sVeille['mortalite'] ?? 0);
        $consoJ1       = (float)($sVeille['consommation_eau'] ?? 0);
    }

    $ecartJJ1 = $tauxRealiseJ - $tauxRealiseJ1;
    $ecartRO  = $tauxRealiseJ - $objectif;

    $mortaliteJ      = (float)($sJour['mortalite'] ?? 0);
    $ecartMortalite  = $mortaliteJ - $mortaliteJ1;

    $pc  = (float)($sJour['pc_pct'] ?? 0);
    $sale = (float)($sJour['sale_pct'] ?? 0);
    $cv  = (float)($sJour['casse_vendue_pct'] ?? 0);
    $cnv = (float)($sJour['casse_non_vendue_pct'] ?? 0);
    $pctTri = $pc + $sale + $cv + $cnv;

    return [
        'effectif_present'     => $effectifPresent,
        'taux_realise_j'       => $tauxRealiseJ,
        'taux_realise_j1'      => $tauxRealiseJ1,
        'ecart_j_j1'           => $ecartJJ1,
        'taux_objectif'        => $objectif,
        'ecart_ro'             => $ecartRO,
        'conso_j'              => (float)($sJour['consommation_eau'] ?? 0),
        'conso_j1'             => $consoJ1,
        'mortalite_j'          => $mortaliteJ,
        'mortalite_j1'         => $mortaliteJ1,
        'ecart_mortalite'      => $ecartMortalite,
        'production_totale'    => $production,
        'pct_tri'              => $pctTri,
        'pc_pct'               => $pc,
        'sale_pct'             => $sale,
        'casse_vendue_pct'     => $cv,
        'casse_non_vendue_pct' => $cnv,
        'temp_min'             => $sJour['temp_min'] ?? null,
        'temp_max'             => $sJour['temp_max'] ?? null,
    ];
}

/** Agrège (somme / moyenne pondérée) un tableau de lignes déjà calculées par calculerLigne() */
function calculerTotal(array $lignes): array
{
    $n = count($lignes);
    if ($n === 0) {
        return [
            'effectif_present' => 0, 'taux_realise_j' => 0, 'taux_realise_j1' => 0,
            'ecart_j_j1' => 0, 'taux_objectif' => 0, 'ecart_ro' => 0,
            'conso_j' => 0, 'conso_j1' => 0,
            'mortalite_j' => 0, 'mortalite_j1' => 0, 'ecart_mortalite' => 0,
            'production_totale' => 0, 'pct_tri' => 0,
            'pc_pct' => 0, 'sale_pct' => 0, 'casse_vendue_pct' => 0, 'casse_non_vendue_pct' => 0,
        ];
    }

    $sumEffectif   = array_sum(array_column($lignes, 'effectif_present'));
    $sumProduction = array_sum(array_column($lignes, 'production_totale'));
    $sumEffectifJ1 = 0;
    $sumProductionJ1 = 0;
    foreach ($lignes as $l) {
        // reconstruire l'effectif J-1 à partir du taux J-1 (approximation par ligne, sinon 0)
        $sumEffectifJ1 += $l['effectif_present']; // l'effectif ne change pas beaucoup d'un jour à l'autre
        $sumProductionJ1 += $l['effectif_present'] > 0 ? ($l['taux_realise_j1'] / 100) * $l['effectif_present'] : 0;
    }

    $tauxRealiseJ  = $sumEffectif   > 0 ? ($sumProduction   / $sumEffectif)   * 100 : 0;
    $tauxRealiseJ1 = $sumEffectifJ1 > 0 ? ($sumProductionJ1 / $sumEffectifJ1) * 100 : 0;

    // taux objectif : moyenne pondérée par effectif
    $sumObjectifPondere = 0;
    foreach ($lignes as $l) { $sumObjectifPondere += $l['taux_objectif'] * $l['effectif_present']; }
    $tauxObjectif = $sumEffectif > 0 ? $sumObjectifPondere / $sumEffectif : 0;

    $sumPcPondere = 0; $sumSalePondere = 0; $sumCvPondere = 0; $sumCnvPondere = 0;
    foreach ($lignes as $l) {
        $sumPcPondere   += $l['pc_pct']   * $l['production_totale'];
        $sumSalePondere += $l['sale_pct'] * $l['production_totale'];
        $sumCvPondere   += $l['casse_vendue_pct']     * $l['production_totale'];
        $sumCnvPondere  += $l['casse_non_vendue_pct'] * $l['production_totale'];
    }
    $pcMoy  = $sumProduction > 0 ? $sumPcPondere   / $sumProduction : 0;
    $saleMoy = $sumProduction > 0 ? $sumSalePondere / $sumProduction : 0;
    $cvMoy  = $sumProduction > 0 ? $sumCvPondere   / $sumProduction : 0;
    $cnvMoy = $sumProduction > 0 ? $sumCnvPondere  / $sumProduction : 0;

    return [
        'effectif_present'  => $sumEffectif,
        'taux_realise_j'    => $tauxRealiseJ,
        'taux_realise_j1'   => $tauxRealiseJ1,
        'ecart_j_j1'        => $tauxRealiseJ - $tauxRealiseJ1,
        'taux_objectif'     => $tauxObjectif,
        'ecart_ro'          => $tauxRealiseJ - $tauxObjectif,
        'conso_j'           => array_sum(array_column($lignes, 'conso_j')),
        'conso_j1'          => array_sum(array_column($lignes, 'conso_j1')),
        'mortalite_j'       => array_sum(array_column($lignes, 'mortalite_j')),
        'mortalite_j1'      => array_sum(array_column($lignes, 'mortalite_j1')),
        'ecart_mortalite'   => array_sum(array_column($lignes, 'mortalite_j')) - array_sum(array_column($lignes, 'mortalite_j1')),
        'production_totale' => $sumProduction,
        'pct_tri'           => $pcMoy + $saleMoy + $cvMoy + $cnvMoy,
        'pc_pct'            => $pcMoy,
        'sale_pct'          => $saleMoy,
        'casse_vendue_pct'  => $cvMoy,
        'casse_non_vendue_pct' => $cnvMoy,
    ];
}

// -----------------------------------------------------------------
// Prix de l'oeuf / Stock / Factures
// -----------------------------------------------------------------

/** Prix de l'oeuf fixé par l'admin pour une date donnée (ou null si non défini) */
function getPrixOeufJour(PDO $pdo, string $date): ?float
{
    $stmt = $pdo->prepare("SELECT prix_unitaire FROM prix_oeuf WHERE date_prix = ?");
    $stmt->execute([$date]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (float)$v;
}

/**
 * Stock disponible d'un bloc = production cumulée de TOUS les bâtiments
 * qui appartiennent directement à ce bloc (somme de leurs saisies)
 * moins la quantité déjà commandée (factures en_attente ou validee) pour ce bloc.
 */
function getStockBloc(PDO $pdo, int $blocId): array
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(s.production_totale), 0) AS total
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        WHERE b.bloc_id = ?
    ");
    $stmt->execute([$blocId]);
    $productionTotale = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantite), 0) FROM factures
        WHERE bloc_id = ? AND statut IN ('en_attente','validee')
    ");
    $stmt->execute([$blocId]);
    $quantiteFacturee = (float)$stmt->fetchColumn();

    return [
        'production_totale'  => $productionTotale,
        'quantite_facturee'  => $quantiteFacturee,
        'stock_disponible'   => max(0, $productionTotale - $quantiteFacturee),
    ];
}

/** Stock (production / commandé / disponible) pour tous les blocs, avec leurs infos */
function getStockTousBlocs(PDO $pdo): array
{
    $blocs = $pdo->query("SELECT * FROM blocs ORDER BY nom")->fetchAll();
    $out = [];
    foreach ($blocs as $b) {
        $stock = getStockBloc($pdo, (int)$b['id']);
        $out[] = array_merge($b, $stock);
    }
    return $out;
}

/**
 * Répartit une quantité demandée sur une liste de blocs (dans l'ordre donné) :
 * on prend d'abord tout ce qui est disponible sur le premier bloc de la liste,
 * puis on passe au suivant si ce n'est pas suffisant, etc.
 * Utilisé par la facture ADMIN qui peut porter sur un bloc, plusieurs blocs
 * précis, ou tous les blocs (stock total).
 *
 * Retourne un tableau [bloc_id => quantite_prise] (uniquement les blocs
 * réellement utilisés) si le stock total des blocs sélectionnés suffit à
 * couvrir la quantité demandée, ou null sinon (quantité demandée trop
 * grande par rapport au stock disponible réellement existant).
 */
function repartirStockSurBlocs(PDO $pdo, array $blocIds, int $quantiteVoulue): ?array
{
    if ($quantiteVoulue <= 0 || !$blocIds) {
        return null;
    }

    $stocks = [];
    $stockTotal = 0;
    foreach ($blocIds as $bid) {
        $stocks[$bid] = getStockBloc($pdo, (int)$bid)['stock_disponible'];
        $stockTotal += $stocks[$bid];
    }

    // On vérifie que la quantité demandée existe réellement dans le stock
    // disponible cumulé des blocs choisis avant de répartir quoi que ce soit.
    if ($stockTotal < $quantiteVoulue) {
        return null;
    }

    $restant = $quantiteVoulue;
    $repartition = [];
    foreach ($blocIds as $bid) {
        if ($restant <= 0) break;
        $dispo = (int)$stocks[$bid];
        if ($dispo <= 0) continue;
        $pris = min($dispo, $restant);
        $repartition[$bid] = $pris;
        $restant -= $pris;
    }
    return $repartition;
}

/** Génère le prochain numéro de facture au format FAC-YYYY-000001 */
function prochainNumeroFacture(PDO $pdo): string
{
    $annee = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE numero_facture LIKE ?");
    $stmt->execute(["FAC-$annee-%"]);
    $n = (int)$stmt->fetchColumn() + 1;
    return sprintf("FAC-%s-%06d", $annee, $n);
}

/**
 * Dernière date de saisie disponible (globale si $blocId est null, sinon pour
 * ce bloc uniquement). Sert de valeur par défaut pour les pages "du jour" (TDB,
 * RH, Stat. simple...) au lieu de la date système exacte : si aucune saisie
 * n'existe pour "aujourd'hui" (ex: démo avec des dates fictives), la page
 * affiche quand même la dernière journée réellement saisie plutôt que des 0
 * partout. Retombe sur la date du jour si la table est totalement vide.
 */
function getDerniereDateSaisie(PDO $pdo, ?int $blocId = null): string
{
    if ($blocId) {
        $stmt = $pdo->prepare("
            SELECT MAX(s.date_saisie) FROM saisies s
            JOIN batiments b ON b.id = s.batiment_id
            WHERE b.bloc_id = ?
        ");
        $stmt->execute([$blocId]);
    } else {
        $stmt = $pdo->query("SELECT MAX(date_saisie) FROM saisies");
    }
    $derniere = $stmt->fetchColumn();
    return $derniere ?: date('Y-m-d');
}

// -----------------------------------------------------------------
// RH : l'utilisateur EST l'ouvrier. Chaque ouvrier (non-admin) voit/
// contrôle exactement UN SEUL bloc (utilisateurs.bloc_id).
// -----------------------------------------------------------------

/** L'ouvrier (utilisateur) qui gère un bloc donné, ou null si aucun. */
function getResponsableBloc(PDO $pdo, int $blocId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, nom_utilisateur, nom_complet, telephone, email, fonction, actif
        FROM utilisateurs
        WHERE bloc_id = ? AND role = 'utilisateur'
        LIMIT 1
    ");
    $stmt->execute([$blocId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Tous les blocs avec, si présent, l'ouvrier (utilisateur) qui les gère. */
function getBlocsAvecResponsable(PDO $pdo): array
{
    return $pdo->query("
        SELECT bl.*, u.id AS utilisateur_id, u.nom_complet AS responsable_nom,
               u.nom_utilisateur AS responsable_login, u.telephone AS responsable_tel,
               u.fonction AS responsable_fonction, u.actif AS responsable_actif
        FROM blocs bl
        LEFT JOIN utilisateurs u ON u.bloc_id = bl.id AND u.role = 'utilisateur'
        ORDER BY bl.nom
    ")->fetchAll();
}

/** Nombre de bâtiments et effectif total pour chaque bloc (pour Planning / Paramétrage) */
function getBlocsAvecStats(PDO $pdo): array
{
    return $pdo->query("
        SELECT bl.*,
               COUNT(b.id) AS nb_batiments,
               COALESCE(SUM(b.effectif_depart), 0) AS effectif_total
        FROM blocs bl
        LEFT JOIN batiments b ON b.bloc_id = bl.id
        GROUP BY bl.id
        ORDER BY bl.nom
    ")->fetchAll();
}

// -----------------------------------------------------------------
// ALERTES AUTOMATIQUES
// Seuils configurables par l'admin (page Paramétrage) : mortalité,
// chute du taux de ponte, absence de saisie. Chaque alerte déclenchée
// est enregistrée dans la table "alertes" (jamais supprimée par
// l'application) et notifiée (bandeau + email) à l'administrateur ET
// à l'ouvrier responsable du bloc concerné.
// -----------------------------------------------------------------

/** Seuils actuels configurés par l'administrateur (une seule ligne en base). */
function getParametresAlertes(PDO $pdo): array
{
    $p = $pdo->query("SELECT * FROM parametres_alertes WHERE id = 1")->fetch();
    return $p ?: [
        'seuil_mortalite_pct' => 2.00, 'seuil_baisse_ponte_pct' => 10.00,
        'jours_sans_saisie' => 2, 'email_actif' => 0,
    ];
}

/**
 * Enregistre une alerte si elle n'existe pas déjà (même type / bâtiment /
 * date -> UNIQUE KEY côté base = idempotent, INSERT IGNORE), puis déclenche
 * la notification (bandeau visible immédiatement + email) UNIQUEMENT si
 * l'alerte vient réellement d'être créée (évite les doublons de notif si
 * une saisie du même jour est ré-enregistrée plusieurs fois).
 */
function creerAlerte(PDO $pdo, string $type, int $blocId, ?int $batimentId, string $dateAlerte, ?float $valeur, ?float $seuil, string $message): void
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO alertes (type_alerte, bloc_id, batiment_id, date_alerte, valeur_mesuree, seuil_applique, message)
        VALUES (:type, :bloc, :bat, :date, :valeur, :seuil, :msg)
    ");
    $stmt->execute([
        'type' => $type, 'bloc' => $blocId, 'bat' => $batimentId, 'date' => $dateAlerte,
        'valeur' => $valeur, 'seuil' => $seuil, 'msg' => $message,
    ]);
    if ($stmt->rowCount() > 0) {
        envoyerEmailAlerte($pdo, $blocId, $message);
    }
}

/**
 * Vérifie les seuils de mortalité et de chute du taux de ponte pour UNE
 * saisie donnée (appelée juste après l'enregistrement d'une saisie dans
 * saisie.php). Crée une alerte pour chaque seuil dépassé.
 */
function verifierAlertesSaisie(PDO $pdo, int $batimentId, string $dateSaisie): void
{
    $stmt = $pdo->prepare("
        SELECT s.*, b.nom AS batiment_nom, b.bloc_id, bl.nom AS bloc_nom
        FROM saisies s
        JOIN batiments b ON b.id = s.batiment_id
        JOIN blocs bl ON bl.id = b.bloc_id
        WHERE s.batiment_id = ? AND s.date_saisie = ?
    ");
    $stmt->execute([$batimentId, $dateSaisie]);
    $s = $stmt->fetch();
    if (!$s) return;

    $params = getParametresAlertes($pdo);
    $effectif   = (float)$s['effectif_present'];
    $mortalite  = (float)$s['mortalite'];
    $production = (float)$s['production_totale'];
    $objectif   = (float)$s['taux_ponte_objectif'];
    $dateFr     = date('d/m/Y', strtotime($dateSaisie));

    // 1) Mortalité du jour, en % de l'effectif présent
    if ($effectif > 0) {
        $tauxMortalite = ($mortalite / $effectif) * 100;
        if ($tauxMortalite >= (float)$params['seuil_mortalite_pct']) {
            creerAlerte(
                $pdo, 'mortalite', (int)$s['bloc_id'], $batimentId, $dateSaisie,
                $tauxMortalite, (float)$params['seuil_mortalite_pct'],
                "Mortalité élevée — {$s['batiment_nom']} ({$s['bloc_nom']}), le $dateFr : " .
                number_format($tauxMortalite, 2, ',', ' ') . " % (seuil : " .
                number_format((float)$params['seuil_mortalite_pct'], 2, ',', ' ') . " %), soit " . fmtNum($mortalite) . " sujets."
            );
        }
    }

    // 2) Chute du taux de ponte réalisé par rapport à l'objectif du jour
    if ($effectif > 0 && $objectif > 0) {
        $tauxRealise = ($production / $effectif) * 100;
        $baissePct = (($objectif - $tauxRealise) / $objectif) * 100;
        if ($baissePct >= (float)$params['seuil_baisse_ponte_pct']) {
            creerAlerte(
                $pdo, 'ponte', (int)$s['bloc_id'], $batimentId, $dateSaisie,
                $tauxRealise, (float)$params['seuil_baisse_ponte_pct'],
                "Chute du taux de ponte — {$s['batiment_nom']} ({$s['bloc_nom']}), le $dateFr : " .
                number_format($tauxRealise, 2, ',', ' ') . " % réalisé contre " .
                number_format($objectif, 2, ',', ' ') . " % d'objectif (baisse de " .
                number_format($baissePct, 1, ',', ' ') . " %, seuil : " .
                number_format((float)$params['seuil_baisse_ponte_pct'], 2, ',', ' ') . " %)."
            );
        }
    }
}

/**
 * Vérifie, pour TOUS les bâtiments actifs, si leur dernière saisie remonte
 * à plus de X jours (seuil configuré par l'admin). Comme personne ne
 * "déclenche" cette vérification en soumettant un formulaire, elle est
 * appelée automatiquement une fois par jour et par session (voir
 * includes/header.php) plutôt qu'à chaque chargement de page.
 */
function verifierAlertesAbsenceSaisie(PDO $pdo): void
{
    $params = getParametresAlertes($pdo);
    $jours = (int)$params['jours_sans_saisie'];
    if ($jours <= 0) return;

    $aujourdhui = date('Y-m-d');
    $seuilDate  = date('Y-m-d', strtotime("-$jours days"));

    $batiments = $pdo->query("
        SELECT b.id, b.nom, b.bloc_id, bl.nom AS bloc_nom, MAX(s.date_saisie) AS derniere_saisie
        FROM batiments b
        JOIN blocs bl ON bl.id = b.bloc_id
        LEFT JOIN saisies s ON s.batiment_id = b.id
        GROUP BY b.id
    ")->fetchAll();

    foreach ($batiments as $b) {
        $derniere = $b['derniere_saisie'];
        if ($derniere !== null && $derniere >= $seuilDate) continue; // saisie récente -> rien à signaler

        $joursEcart = $derniere ? (int)floor((strtotime($aujourdhui) - strtotime($derniere)) / 86400) : null;
        $message = $derniere
            ? "Aucune saisie depuis $joursEcart jours — {$b['nom']} ({$b['bloc_nom']}) : dernière saisie le " . date('d/m/Y', strtotime($derniere)) . "."
            : "Aucune saisie n'a jamais été enregistrée pour {$b['nom']} ({$b['bloc_nom']}).";

        creerAlerte(
            $pdo, 'absence_saisie', (int)$b['bloc_id'], (int)$b['id'], $aujourdhui,
            $joursEcart, $jours, $message
        );
    }
}

/**
 * Envoie l'email d'alerte (si activé dans les paramètres) à tous les
 * administrateurs ET à l'ouvrier responsable du bloc concerné, pour
 * ceux qui ont une adresse email renseignée. Utilise un envoi SMTP réel
 * via PHPMailer (voir includes/mailer.php et les constantes SMTP_* dans
 * config.php) ; en cas d'échec ou d'absence de configuration SMTP,
 * l'alerte reste de toute façon visible dans le bandeau et l'historique.
 */
function envoyerEmailAlerte(PDO $pdo, int $blocId, string $message): void
{
    $params = getParametresAlertes($pdo);
    if (empty($params['email_actif'])) return;

    $destinataires = $pdo->query("
        SELECT email FROM utilisateurs WHERE role = 'admin' AND email IS NOT NULL AND email <> ''
    ")->fetchAll(PDO::FETCH_COLUMN);

    $ouvrier = getResponsableBloc($pdo, $blocId);
    if ($ouvrier && !empty($ouvrier['email'])) {
        $destinataires[] = $ouvrier['email'];
    }
    $destinataires = array_values(array_unique(array_filter($destinataires)));
    if (!$destinataires) return;

    require_once __DIR__ . '/includes/mailer.php';
    $sujet = "[ELhana] Alerte CHAHIA";
    envoyerMailSMTP($destinataires, $sujet, $message);
}

/** Alertes déclenchées aujourd'hui (pour le bandeau) : toutes pour l'admin, filtrées par bloc pour un ouvrier. */
function getAlertesDuJour(PDO $pdo, ?int $blocId): array
{
    $sql = "SELECT a.*, bl.nom AS bloc_nom, b.nom AS batiment_nom
            FROM alertes a
            JOIN blocs bl ON bl.id = a.bloc_id
            LEFT JOIN batiments b ON b.id = a.batiment_id
            WHERE DATE(a.date_creation) = CURDATE()";
    $params = [];
    if ($blocId) { $sql .= " AND a.bloc_id = ?"; $params[] = $blocId; }
    $sql .= " ORDER BY a.date_creation DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Libellé lisible pour un type d'alerte */
function libelleTypeAlerte(string $type): string
{
    return match ($type) {
        'mortalite' => 'Mortalité élevée',
        'ponte' => 'Chute du taux de ponte',
        'absence_saisie' => 'Absence de saisie',
        default => $type,
    };
}