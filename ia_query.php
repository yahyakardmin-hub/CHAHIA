<?php
/**
 * IA_QUERY.PHP — "Assistant CHAHIA" mode données.
 *
 * Implémente l'architecture :
 *   Question utilisateur → Ollama génère une requête SQL → PHP valide
 *   et exécute cette requête (lecture seule) → PHP renvoie le résultat
 *   à Ollama → Ollama rédige la réponse finale en français.
 *
 * Ollama ne communique JAMAIS directement avec MySQL : PHP reste
 * l'unique intermédiaire, comme recommandé.
 *
 * Sécurité appliquée (cf. recommandations) :
 *   - seules les requêtes SELECT sont autorisées ;
 *   - la syntaxe est vérifiée avant exécution (mots-clés interdits,
 *     une seule instruction, pas de commentaires SQL) ;
 *   - seules les tables listées dans $TABLES_AUTORISEES sont accessibles ;
 *   - un ouvrier (non-admin) ne peut interroger QUE les données de son
 *     propre bloc : la requête générée est vérifiée pour s'assurer
 *     qu'elle filtre bien sur son bloc_id ;
 *   - un LIMIT est forcé pour éviter les extractions massives.
 *
 * ⚠️ Pour une sécurité renforcée en production, utilisez en plus un
 * compte MySQL dédié à cette page, avec des droits SELECT uniquement
 * sur les tables listées ci-dessous (GRANT SELECT ON chahia.blocs, ... ).
 */

require_once __DIR__ . '/config.php';       // $pdo + session
require_once __DIR__ . '/includes/auth.php';

// Empêche tout notice/warning/deprecated PHP de s'afficher dans la sortie :
// un seul octet de texte avant/après le JSON suffit à casser response.json()
// côté navigateur. Les erreurs restent journalisées (error_log) si configuré.
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

// Garde-fou : si quelque chose plante de façon inattendue (extension PHP
// manquante, erreur fatale...), on renvoie tout de même du JSON exploitable
// par le widget au lieu d'une page d'erreur HTML qui casse response.json().
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['erreur' => 'Erreur serveur.', 'debug' => $e->getMessage()]);
    exit;
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['erreur' => 'Erreur serveur.', 'debug' => $err['message']]);
    }
});

if (!extension_loaded('curl')) {
    echo json_encode(['erreur' => "L'extension PHP curl n'est pas activée (nécessaire pour parler à Ollama)."]);
    exit;
}

if (!estConnecte()) {
    http_response_code(401);
    echo json_encode(['erreur' => 'Non authentifié.']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);

if (!empty($input['reset'])) {
    unset($_SESSION['ia_last_question'], $_SESSION['ia_last_sql'], $_SESSION['ia_last_result']);
    echo json_encode(['status' => 'ok']);
    exit;
}

$question = trim($input['question'] ?? '');

if ($question === '') {
    http_response_code(400);
    echo json_encode(['erreur' => 'Question vide.']);
    exit;
}

$user     = utilisateurConnecte();
$isAdmin  = estAdmin();
$blocId   = $user['bloc_id'] ?? null;

const OLLAMA_URL = 'http://localhost:11434/api/chat';
const MODEL       = 'qwen2.5';

// ------------------------------------------------------------------
// 1) Schéma autorisé — uniquement des tables/colonnes de consultation,
//    jamais de table contenant des identifiants/mots de passe.
// ------------------------------------------------------------------
$TABLES_AUTORISEES = ['blocs', 'batiments', 'saisies', 'prix_oeuf', 'factures', 'saisie_valeurs', 'parametres_suivi'];

$schema = <<<SCHEMA
Tables disponibles (lecture seule, base MySQL "chahia") :

blocs(id, exercice, speculation, nom, lot_code, superficie, effectif_entree,
      date_debut_nettoyage, date_entree_effectif, date_cloture, etat, valide)

batiments(id, nom, bloc_id, age_semaines, souche, effectif_depart)
  -- bloc_id référence blocs.id

saisies(id, batiment_id, date_saisie, effectif_present, taux_ponte_objectif,
        production_totale, consommation_eau, consommation_aliment,
        consommation_electricite, mortalite, reforme_tri, pc_pct, sale_pct,
        casse_vendue_pct, casse_non_vendue_pct, temp_min, temp_max, humidite,
        nombre_ouvriers, poids_moyen_oeuf, consommation_alveole, remarque)
  -- batiment_id référence batiments.id ; une ligne = un bâtiment, un jour ;
  -- contient TOUTES les statistiques journalières (production, mortalité,
  -- consommation, tri en %, température, effectif...)

parametres_suivi(code, libelle_fr, libelle_ar)
  -- catégories détaillées du tri des oeufs (ex : C1 Blanc, Gros JO...)

saisie_valeurs(id, saisie_id, code, valeur)
  -- détail par catégorie de tri pour une saisie donnée ; code référence
  -- parametres_suivi.code, saisie_id référence saisies.id

prix_oeuf(id, date_prix, prix_unitaire, defini_par)
  -- prix_unitaire est en DINARS TUNISIENS (DT), donnée globale (pas de bloc_id)

factures(id, numero_facture, date_facture, utilisateur_id, bloc_id, quantite,
         prix_unitaire, montant_total, statut)
  -- prix_unitaire et montant_total sont en DINARS TUNISIENS (DT)
SCHEMA;

// ------------------------------------------------------------------
// 2) Contexte de la question précédente (permet les questions de suivi
//    comme "en pourcentage ?" ou "et le mois dernier ?" sans tout redemander)
// ------------------------------------------------------------------
$precQuestion = $_SESSION['ia_last_question'] ?? null;
$precSql      = $_SESSION['ia_last_sql'] ?? null;
$precResult   = $_SESSION['ia_last_result'] ?? null;

$contexteSuivi = '';
if ($precQuestion !== null && $precResult !== null) {
    $precResultJson = json_encode($precResult, JSON_UNESCAPED_UNICODE);
    $contexteSuivi = "\nCONTEXTE DE LA QUESTION PRÉCÉDENTE (utilise-le si la nouvelle question s'y "
        . "rapporte, par exemple \"en pourcentage\", \"et hier ?\", \"compare avec...\") :\n"
        . "Question précédente : {$precQuestion}\n"
        . "Résultat précédent (JSON) : {$precResultJson}\n";
}

// ------------------------------------------------------------------
// 3) Prompt de génération SQL
// ------------------------------------------------------------------
$regleBloc = $isAdmin
    ? "L'utilisateur est ADMINISTRATEUR : il peut consulter tous les blocs, sans restriction."
    : "L'utilisateur est un OUVRIER rattaché UNIQUEMENT au bloc id = {$blocId}. "
      . "Pour les tables batiments, saisies, factures et blocs, la requête générée DOIT "
      . "impérativement restreindre les résultats à ce bloc "
      . "(par exemple : batiments.bloc_id = {$blocId}, blocs.id = {$blocId}, "
      . "ou factures.bloc_id = {$blocId} selon les tables utilisées). "
      . "La table prix_oeuf est une donnée GLOBALE fixée par l'administrateur pour toute "
      . "la ferme : elle n'a PAS de colonne bloc_id, n'ajoute donc AUCUN filtre de bloc "
      . "sur cette table. Ne renvoie JAMAIS de données d'un autre bloc.";

$sqlSystemPrompt = <<<PROMPT
Tu es un générateur de requêtes SQL MySQL en LECTURE SEULE pour l'application
d'élevage CHAHIA.

{$schema}
{$contexteSuivi}
RÈGLES ABSOLUES :
- Si la nouvelle question est une question de SUIVI qui peut être répondue en
  recalculant à partir du RÉSULTAT PRÉCÉDENT ci-dessus (ex : "en pourcentage",
  "et en %", "compare ces deux chiffres") SANS avoir besoin d'aller chercher
  de nouvelles données en base, réponds exactement : CALCUL_SANS_SQL
- Sinon, réponds UNIQUEMENT avec une requête SQL, rien d'autre : pas de texte,
  pas d'explication, pas de balises Markdown (pas de \`\`\`sql).
- La requête doit commencer par SELECT.
- N'utilise QUE les tables listées ci-dessus. N'invente aucune colonne.
- Pour toute question sur la RÉPARTITION DU TRI ou le pourcentage de tri
  (PC, sale, casse vendue, casse non vendue), utilise en priorité les
  colonnes déjà prêtes à l'emploi de la table saisies (pc_pct, sale_pct,
  casse_vendue_pct, casse_non_vendue_pct), avec AVG(...) si la question
  porte sur une période. N'utilise saisie_valeurs/parametres_suivi QUE si la
  question demande explicitement un détail par catégorie précise (ex :
  "Gros JO", "C1 Blanc") non couverte par ces 4 colonnes.
- Si la question demande un TAUX DE PONTE (réalisé, moyen, évolution...),
  calcule-le en SQL à partir de production_totale et effectif_present
  (par exemple AVG(production_totale / effectif_present) * 100), ne te
  base pas sur une colonne "taux" qui n'existe pas déjà pré-calculée.
- Le "STOCK D'OEUFS DISPONIBLE" n'est PAS une colonne existante : c'est
  TOUJOURS un calcul = (somme de production_totale de toutes les saisies des
  bâtiments du bloc) − (somme de quantite des factures de ce bloc dont
  statut IN ('en_attente','validee')). Calcule-le avec deux sous-requêtes
  agrégées combinées par une soustraction (pas de jointure directe entre
  saisies et factures, ce sont deux échelles différentes).
- Pour une comparaison "J / J-1" (jour précédent) sur un bâtiment donné,
  utilise une auto-jointure sur saisies : s1 JOIN saisies s2 ON
  s2.batiment_id = s1.batiment_id AND s2.date_saisie = DATE_SUB(s1.date_saisie, INTERVAL 1 DAY).
- Si la question demande une vue d'ensemble ou "toutes les statistiques" d'un
  bloc/bâtiment, sélectionne PLUSIEURS colonnes pertinentes (effectif,
  production, mortalité, taux de ponte, consommation, tri, température...)
  plutôt qu'une seule valeur isolée.
- Une seule instruction SQL, sans point-virgule final, sans commentaire.
- {$regleBloc}
- Si la question ne peut pas être traduite en requête sur ces tables, réponds
  exactement : NON_APPLICABLE
PROMPT;

$sqlGenere = appellerOllama($sqlSystemPrompt, $question);
$sqlGenere = nettoyerSQL($sqlGenere);

if ($sqlGenere === '' || strtoupper($sqlGenere) === 'NON_APPLICABLE') {
    echo json_encode(['non_applicable' => true]);
    exit;
}

// ------------------------------------------------------------------
// Cas "question de suivi" : pas de nouvelle requête, on recalcule à
// partir du résultat précédent déjà en mémoire de session.
// ------------------------------------------------------------------
if (strtoupper($sqlGenere) === 'CALCUL_SANS_SQL' && $precResult !== null) {
    $promptCalcul = <<<PROMPT
Tu es l'assistant CHAHIA. Voici la question précédente et son résultat, puis
une NOUVELLE question de suivi qui demande de recalculer/reformuler ce même
résultat (ex : en pourcentage, comparaison...). Fais le calcul toi-même à
partir des chiffres fournis et donne UNIQUEMENT la réponse finale en français,
courte et directe, sans JSON ni tableau brut, sans montrer la méthode/formule.

RÈGLES ABSOLUES :
- N'invente JAMAIS de chiffre d'exemple hypothétique. Si le résultat précédent
  ne contient pas assez d'information pour ce calcul, dis-le simplement en
  une phrase (ex : "Il me manque la donnée de comparaison, peux-tu préciser
  la période ?").
- production_totale, effectif_present, mortalite : unités (œufs/poules),
  JAMAIS "DT". Seuls prix_unitaire/montant_total sont en DINARS TUNISIENS
  ("DT"), jamais "€"/"euro(s)".

Question précédente : {$precQuestion}
Résultat précédent (JSON) : {$precResultJson}
PROMPT;
    $reponseFinale = appellerOllama($promptCalcul, "Nouvelle question de suivi : {$question}");

    $_SESSION['ia_last_question'] = $question;
    // On garde le même résultat précédent en mémoire puisqu'aucune nouvelle
    // donnée n'a été interrogée.

    echo json_encode([
        'reponse' => $reponseFinale !== '' ? $reponseFinale : "Je n'ai pas réussi à faire ce calcul, peux-tu reformuler ?",
    ]);
    exit;
}

if (strtoupper($sqlGenere) === 'CALCUL_SANS_SQL') {
    // Le modèle pense qu'il s'agit d'un suivi, mais on n'a aucun contexte
    // précédent en mémoire (ex : après un rechargement de page) : on
    // redemande la question complète à l'utilisateur.
    echo json_encode([
        'reponse' => "Je n'ai plus le contexte de ta question précédente (la conversation a peut-être été réinitialisée). "
                   . "Peux-tu reformuler ta question en entier ?",
    ]);
    exit;
}

// ------------------------------------------------------------------
// 3) Validation stricte avant exécution
// ------------------------------------------------------------------
$erreurValidation = validerSQL($sqlGenere, $TABLES_AUTORISEES, $isAdmin, $blocId);
if ($erreurValidation !== null) {
    echo json_encode([
        'reponse' => "Je n'ai pas pu vérifier la sécurité de cette requête, merci de reformuler ta question.",
        'debug'   => $erreurValidation, // utile en développement, à retirer en prod si besoin
    ]);
    exit;
}

// Limite de sécurité si la requête n'en a pas déjà une
if (!preg_match('/\bLIMIT\s+\d+/i', $sqlGenere)) {
    $sqlGenere .= ' LIMIT 200';
}

// ------------------------------------------------------------------
// 4) Exécution (lecture seule)
// ------------------------------------------------------------------
try {
    $stmt = $pdo->query($sqlGenere);
    $lignes = $stmt->fetchAll();
} catch (PDOException $e) {
    echo json_encode([
        'reponse' => "Je n'ai pas réussi à récupérer ces données, merci de reformuler ta question.",
        'debug'   => $e->getMessage(),
    ]);
    exit;
}

// ------------------------------------------------------------------
// 5) Ollama rédige la réponse finale à partir des résultats
// ------------------------------------------------------------------
$explicationSystemPrompt = <<<PROMPT
Tu es l'assistant CHAHIA. On te donne une question posée par l'utilisateur et
le résultat JSON d'une requête SQL qui y répond. Rédige une réponse DIRECTE et
courte en français : donne UNIQUEMENT le(s) chiffre(s) final(aux) demandé(s),
sans JSON, sans tableau brut.

RÈGLES ABSOLUES :
- Ne montre JAMAIS la méthode de calcul, la formule, ni d'exemple hypothétique
  ("supposons que...") : calcule toi-même en interne et ne donne que le résultat.
- Si les données JSON fournies sont vides ou insuffisantes pour répondre,
  dis-le simplement en une phrase (ex : "Aucune donnée pour cette période.")
  SANS inventer de chiffres d'exemple.
- UNITÉS — ne confonds jamais les unités entre elles :
  * production_totale, effectif_present, mortalite, reforme_tri : nombre
    d'œufs ou de poules (unités), PAS de l'argent → n'utilise JAMAIS "DT" ici.
  * taux_ponte_objectif, pc_pct, sale_pct, casse_vendue_pct,
    casse_non_vendue_pct, tout "taux" ou "écart" : un pourcentage (%).
  * consommation_eau (litres), consommation_aliment (kg),
    consommation_electricite (kWh), poids_moyen_oeuf (grammes),
    temp_min/temp_max (°C), humidite (%).
  * SEULS prix_unitaire et montant_total (tables prix_oeuf, factures) sont
    des sommes d'argent : uniquement pour ceux-là, utilise "DT" (Dinar
    Tunisien), jamais "€"/"euro(s)".

FORMULES OFFICIELLES DE L'APPLICATION (utilise-les si le calcul le nécessite,
à partir des colonnes déjà présentes dans le résultat JSON) :
- Taux de ponte réalisé (%) = (production_totale ÷ effectif_present) × 100
- Écart J/J-1 (%) = Taux réalisé aujourd'hui − Taux réalisé la veille
- Écart Réalisé/Objectif (%) = Taux réalisé − taux_ponte_objectif
- Écart mortalité = mortalité du jour − mortalité de la veille
- % Tri total = pc_pct + sale_pct + casse_vendue_pct + casse_non_vendue_pct
- Pourcentage d'évolution entre deux périodes = ((valeur récente − valeur
  ancienne) ÷ valeur ancienne) × 100
PROMPT;

$messagePourOllama = "Question : {$question}\n\nRésultat SQL (JSON) :\n"
    . json_encode($lignes, JSON_UNESCAPED_UNICODE);

$reponseFinale = appellerOllama($explicationSystemPrompt, $messagePourOllama);

// Mémorise cet échange pour permettre les questions de suivi
// ("en pourcentage ?", "et hier ?"...) sans tout redemander.
$_SESSION['ia_last_question'] = $question;
$_SESSION['ia_last_sql']      = $sqlGenere;
$_SESSION['ia_last_result']   = $lignes;

echo json_encode([
    'reponse' => $reponseFinale !== '' ? $reponseFinale : "Voici les données trouvées : " . json_encode($lignes, JSON_UNESCAPED_UNICODE),
]);
exit;

// ==================================================================
// Fonctions utilitaires
// ==================================================================

/** Appelle Ollama (non-streaming) avec un system prompt + un message utilisateur. */
function appellerOllama(string $system, string $userMessage): string
{
    $payload = [
        'model'    => MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userMessage],
        ],
        'stream' => false,
    ];

    $ch = curl_init(OLLAMA_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
    ]);
    $result = curl_exec($ch);
    $err    = curl_error($ch);

    if ($result === false || $err) {
        return '';
    }

    $data = json_decode($result, true);
    return trim($data['message']['content'] ?? '');
}

/** Nettoie la sortie du modèle : retire ```sql ... ``` éventuels, espaces, point-virgule final. */
function nettoyerSQL(string $sql): string
{
    $sql = trim($sql);
    $sql = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/m', '', $sql);
    $sql = trim($sql, " \t\n\r;");
    return trim($sql);
}

/**
 * Vérifie que la requête est un SELECT unique, sans mot-clé dangereux,
 * n'utilisant que des tables autorisées, et (pour un ouvrier) filtrant
 * bien sur son bloc_id. Retourne null si OK, ou un message d'erreur.
 */
function validerSQL(string $sql, array $tablesAutorisees, bool $isAdmin, ?int $blocId): ?string
{
    if (!preg_match('/^\s*SELECT\s/i', $sql)) {
        return 'La requête ne commence pas par SELECT.';
    }

    if (strpos($sql, ';') !== false) {
        return 'Plusieurs instructions détectées.';
    }

    $motsInterdits = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE',
        'REPLACE', 'GRANT', 'REVOKE', 'EXEC', 'CALL', 'INFORMATION_SCHEMA',
        'LOAD_FILE', 'OUTFILE', 'DUMPFILE', 'SLEEP(', 'BENCHMARK(', 'UNION',
        '--', '/*', '#', 'MOT_DE_PASSE', 'PASSWORD',
    ];
    foreach ($motsInterdits as $mot) {
        if (stripos($sql, $mot) !== false) {
            return "Mot-clé interdit détecté : {$mot}";
        }
    }

    // Extraire les noms de table après FROM / JOIN
    preg_match_all('/\b(?:FROM|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $sql, $m);
    $tablesUtilisees = array_unique(array_map('strtolower', $m[1] ?? []));

    if (empty($tablesUtilisees)) {
        return 'Aucune table détectée.';
    }

    foreach ($tablesUtilisees as $table) {
        if (!in_array($table, $tablesAutorisees, true)) {
            return "Table non autorisée : {$table}";
        }
    }

    // Pour un ouvrier : la requête doit filtrer sur son bloc UNIQUEMENT si
    // elle touche une table réellement rattachée à un bloc (prix_oeuf, par
    // exemple, est une donnée globale fixée par l'admin, pas liée à un bloc).
    $tablesLieesAuBloc = ['blocs', 'batiments', 'saisies', 'factures', 'saisie_valeurs'];
    $toucheDonneesDeBloc = count(array_intersect($tablesUtilisees, $tablesLieesAuBloc)) > 0;

    if (!$isAdmin && $toucheDonneesDeBloc) {
        if ($blocId === null) {
            return 'Aucun bloc rattaché à cet utilisateur.';
        }
        $motif = '/\b(bloc_id|id)\s*=\s*' . preg_quote((string) $blocId, '/') . '\b/i';
        if (!preg_match($motif, $sql)) {
            return 'La requête ne filtre pas explicitement sur le bloc de l\'utilisateur.';
        }
    }

    return null;
}
