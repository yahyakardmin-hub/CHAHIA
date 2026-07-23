// ==========================================================
// SCRIPT.JS - Logique du formulaire de saisie journalière
// Ne fait AUCUN appel réseau (pas d'API) : la hiérarchie
// Société > Centre > Bâtiment et les saisies existantes sont
// injectées en JSON par saisie.php (window.HIERARCHIE_DATA,
// window.SAISIES_INDEX) et tout le filtrage/calcul se fait ici.
// ==========================================================

// ==========================================================
// SUB-NAV générique : bascule entre plusieurs tableaux (.tab-panel)
// via une barre de type nav-bar (.sub-nav a[data-tab]).
// Utilisé sur parametre.php et RH.php pour naviguer entre les
// différents tableaux d'une même page sans recharger.
// ==========================================================
document.addEventListener('DOMContentLoaded', () => {
    const tabLinks = document.querySelectorAll('.sub-nav a[data-tab]');
    if (tabLinks.length) {
        tabLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const target = link.dataset.tab;
                document.querySelectorAll('.sub-nav a[data-tab]').forEach(l => l.classList.remove('active'));
                document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
                link.classList.add('active');
                const panel = document.getElementById(target);
                if (panel) panel.classList.add('active');
                if (history.replaceState) history.replaceState(null, '', '#' + target);
            });
        });
        // Ouvre l'onglet indiqué dans l'URL (#tab) sinon le premier
        const fromHash = window.location.hash ? window.location.hash.substring(1) : null;
        const initial = fromHash && document.getElementById(fromHash) ? fromHash : tabLinks[0].dataset.tab;
        const initialLink = document.querySelector('.sub-nav a[data-tab="' + initial + '"]');
        if (initialLink) initialLink.click();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('formSaisie');
    if (!form) return; // on n'est pas sur saisie.php

    const hierarchie = window.HIERARCHIE_DATA || [];
    const saisiesIndex = window.SAISIES_INDEX || {};

    const blocSelect      = document.getElementById('bloc_id');
    const batimentSelect = document.getElementById('batiment_id');
    const dateInput      = document.getElementById('date_saisie');
    const effectifDepartAff = document.getElementById('effectif_depart_aff');

    let saisieVeilleCourante = null; // pour les calculs d'écart J/J-1

    // ---------- Bâtiments disponibles pour un bloc (filtrage local) ----------
    function chargerBatiments(blocId, batimentASelectionner = null) {
        batimentSelect.innerHTML = '<option value="">-- Choisir --</option>';
        if (!blocId) {
            batimentSelect.innerHTML = '<option value="">-- Choisir le bloc d\'abord --</option>';
            batimentSelect.disabled = true;
            return;
        }
        hierarchie
            .filter(h => String(h.bloc_id) === String(blocId))
            .forEach(h => {
                const opt = document.createElement('option');
                opt.value = h.batiment_id;
                opt.textContent = h.batiment_nom;
                opt.dataset.effectifDepart = h.effectif_depart;
                if (batimentASelectionner && String(h.batiment_id) === String(batimentASelectionner)) opt.selected = true;
                batimentSelect.appendChild(opt);
            });
        batimentSelect.disabled = false;

        if (batimentASelectionner) chargerInfosBatiment();
    }

    // ---------- Infos du bâtiment (saisie existante + veille) : lecture locale ----------
    function chargerInfosBatiment() {
        const batimentId = batimentSelect.value;
        const date = dateInput.value;
        if (!batimentId || !date) return;

        const ligne = hierarchie.find(h => String(h.batiment_id) === String(batimentId));
        if (ligne) effectifDepartAff.value = Number(ligne.effectif_depart).toLocaleString('fr-FR');

        const dateVeille = decalerDate(date, -1);
        saisieVeilleCourante = saisiesIndex[batimentId + '|' + dateVeille] || null;

        const saisieJour = saisiesIndex[batimentId + '|' + date] || null;
        if (saisieJour) remplirFormulaire(saisieJour);
        calculerApercu();
    }

    function decalerDate(dateStr, jours) {
        const d = new Date(dateStr + 'T00:00:00');
        d.setDate(d.getDate() + jours);
        return d.toISOString().slice(0, 10);
    }

    function remplirFormulaire(s) {
        document.getElementById('effectif_present').value = s.effectif_present;
        document.getElementById('taux_ponte_objectif').value = s.taux_ponte_objectif;
        document.getElementById('production_totale').value = s.production_totale;
        document.getElementById('consommation_eau').value = s.consommation_eau;
        document.getElementById('consommation_aliment').value = s.consommation_aliment ?? 0;
        document.getElementById('consommation_electricite').value = s.consommation_electricite ?? 0;
        document.getElementById('mortalite').value = s.mortalite;
        document.getElementById('reforme_tri').value = s.reforme_tri;
        document.getElementById('nombre_ouvriers').value = s.nombre_ouvriers ?? 0;
        document.getElementById('poids_moyen_oeuf').value = s.poids_moyen_oeuf ?? '';
        document.getElementById('consommation_alveole').value = s.consommation_alveole ?? 0;
        document.getElementById('pc_pct').value = s.pc_pct;
        document.getElementById('sale_pct').value = s.sale_pct;
        document.getElementById('casse_vendue_pct').value = s.casse_vendue_pct;
        document.getElementById('casse_non_vendue_pct').value = s.casse_non_vendue_pct;
        document.getElementById('temp_min').value = s.temp_min ?? '';
        document.getElementById('temp_max').value = s.temp_max ?? '';
        document.getElementById('humidite').value = s.humidite ?? '';
        document.getElementById('remarque').value = s.remarque ?? '';
    }

    // ---------- Calcul en direct (aperçu) - mêmes formules que functions.php ----------
    function calculerApercu() {
        const effectifPresent = parseFloat(document.getElementById('effectif_present').value) || 0;
        const production      = parseFloat(document.getElementById('production_totale').value) || 0;
        const objectif        = parseFloat(document.getElementById('taux_ponte_objectif').value) || 0;
        const mortalite       = parseFloat(document.getElementById('mortalite').value) || 0;
        const pc   = parseFloat(document.getElementById('pc_pct').value) || 0;
        const sale = parseFloat(document.getElementById('sale_pct').value) || 0;
        const cv   = parseFloat(document.getElementById('casse_vendue_pct').value) || 0;
        const cnv  = parseFloat(document.getElementById('casse_non_vendue_pct').value) || 0;

        const tauxRealiseJ = effectifPresent > 0 ? (production / effectifPresent) * 100 : 0;

        let tauxRealiseJ1 = 0;
        let mortaliteJ1 = 0;
        if (saisieVeilleCourante) {
            const effJ1 = parseFloat(saisieVeilleCourante.effectif_present) || 0;
            const prodJ1 = parseFloat(saisieVeilleCourante.production_totale) || 0;
            tauxRealiseJ1 = effJ1 > 0 ? (prodJ1 / effJ1) * 100 : 0;
            mortaliteJ1 = parseFloat(saisieVeilleCourante.mortalite) || 0;
        }

        const ecartJJ1 = tauxRealiseJ - tauxRealiseJ1;
        const ecartRO  = tauxRealiseJ - objectif;
        const ecartMortalite = mortalite - mortaliteJ1;
        const pctTri = pc + sale + cv + cnv;

        const fmt = (v) => v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %';
        const colorer = (el, v) => {
            el.classList.remove('positif', 'negatif', 'neutre');
            el.classList.add(v > 0 ? 'positif' : (v < 0 ? 'negatif' : 'neutre'));
        };

        document.getElementById('apercu_taux').textContent = fmt(tauxRealiseJ);

        const elEcartJJ1 = document.getElementById('apercu_ecart_jj1');
        elEcartJJ1.textContent = fmt(ecartJJ1);
        colorer(elEcartJJ1, ecartJJ1);

        const elEcartRO = document.getElementById('apercu_ecart_ro');
        elEcartRO.textContent = fmt(ecartRO);
        colorer(elEcartRO, ecartRO);

        document.getElementById('apercu_pct_tri').textContent = fmt(pctTri);

        const elEcartMortalite = document.getElementById('apercu_ecart_mortalite');
        elEcartMortalite.textContent = ecartMortalite;
        colorer(elEcartMortalite, -ecartMortalite); // mortalité en hausse = rouge
    }

    // ---------- Écouteurs d'événements ----------
    blocSelect.addEventListener('change', () => chargerBatiments(blocSelect.value));
    batimentSelect.addEventListener('change', chargerInfosBatiment);
    dateInput.addEventListener('change', chargerInfosBatiment);

    ['effectif_present', 'production_totale', 'taux_ponte_objectif', 'mortalite',
     'pc_pct', 'sale_pct', 'casse_vendue_pct', 'casse_non_vendue_pct']
        .forEach(id => document.getElementById(id).addEventListener('input', calculerApercu));

    // Le formulaire se soumet désormais en POST classique vers saisie.php (traitement PHP direct, pas d'API)

    // ---------- Préremplissage si on arrive depuis "✎" (edit) ----------
    (function initFromUrl() {
        const preselectId = document.getElementById('batiment_id_preselect').value;
        if (!preselectId) return;

        const ligne = hierarchie.find(h => String(h.batiment_id) === String(preselectId));
        if (!ligne) return;

        blocSelect.value = ligne.bloc_id;
        chargerBatiments(ligne.bloc_id, ligne.batiment_id);
    })();
});

// ==========================================================
// Aide au calcul du stock disponible pour une commande de facture
// (utilisé par facture.php - voir la balise <script> de cette page)
// ==========================================================
function calculerMontantFacture(quantite, prixUnitaire) {
    return Math.round(quantite * prixUnitaire * 1000) / 1000;
}