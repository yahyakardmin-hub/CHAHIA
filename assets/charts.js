// ==========================================================
// CHARTS.JS - Calculs statistiques (partie JS) + graphiques
// Aucune donnée n'est recalculée côté serveur : PHP fournit les
// saisies brutes (window.SAISIES_DATA) et le stock par bloc
// (window.STOCK_BLOCS), tout le reste (agrégations, moyennes
// pondérées, évolutions) est calculé ici.
// ==========================================================

(function () {
    const data = Array.isArray(window.SAISIES_DATA) ? window.SAISIES_DATA : [];
    const stockBlocs = Array.isArray(window.STOCK_BLOCS) ? window.STOCK_BLOCS : [];

    if (!document.getElementById('chartTauxPonte')) return; // pas sur TDB_graphe.php

    // ---------- Outils ----------
    const num = (v) => parseFloat(v) || 0;
    const fmtInt = (v) => Math.round(v).toLocaleString('fr-FR');
    const fmtPct = (v) => v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' %';

    function groupBy(arr, keyFn) {
        const map = new Map();
        for (const item of arr) {
            const k = keyFn(item);
            if (!map.has(k)) map.set(k, []);
            map.get(k).push(item);
        }
        return map;
    }

    // ==========================================================
    // 1) Évolution du taux de ponte moyen PONDÉRÉ par jour
    //    taux(jour) = SUM(production_totale) / SUM(effectif_present) * 100
    // ==========================================================
    const parDate = groupBy(data, d => d.date_saisie);
    const dates = Array.from(parDate.keys()).sort();
    const tauxParDate = dates.map(d => {
        const lignes = parDate.get(d);
        const sumProd = lignes.reduce((a, l) => a + num(l.production_totale), 0);
        const sumEff  = lignes.reduce((a, l) => a + num(l.effectif_present), 0);
        return sumEff > 0 ? (sumProd / sumEff) * 100 : 0;
    });
    const consoEauParDate = dates.map(d => parDate.get(d).reduce((a, l) => a + num(l.consommation_eau), 0));
    const productionParDate = dates.map(d => parDate.get(d).reduce((a, l) => a + num(l.production_totale), 0));

    // ==========================================================
    // 2) Production totale par bloc (sur toute la période)
    // ==========================================================
    const parBloc = groupBy(data, d => d.bloc_nom);
    const blocsNoms = Array.from(parBloc.keys());
    const productionParBloc = blocsNoms.map(s => parBloc.get(s).reduce((a, l) => a + num(l.production_totale), 0));

    // ==========================================================
    // 3) Répartition moyenne du tri (pondérée par la production de chaque saisie)
    // ==========================================================
    let sumPc = 0, sumSale = 0, sumCv = 0, sumCnv = 0, sumProdTri = 0;
    data.forEach(l => {
        const prod = num(l.production_totale);
        sumPc  += prod * num(l.pc_pct) / 100;
        sumSale += prod * num(l.sale_pct) / 100;
        sumCv  += prod * num(l.casse_vendue_pct) / 100;
        sumCnv += prod * num(l.casse_non_vendue_pct) / 100;
        sumProdTri += prod;
    });
    const triRepartition = sumProdTri > 0
        ? [sumPc, sumSale, sumCv, sumCnv].map(v => (v / sumProdTri) * 100)
        : [0, 0, 0, 0];
    const pctTriGlobal = triRepartition.reduce((a, b) => a + b, 0);

    // ==========================================================
    // 4) Mortalité cumulée par bloc (sur la période)
    // ==========================================================
    const mortaliteParBloc = blocsNoms.map(c => parBloc.get(c).reduce((a, l) => a + num(l.mortalite), 0));

    // ==========================================================
    // 4bis) Évolution PAR MOIS et PAR ANNEE (changements dans le temps)
    //    Production totale + taux de ponte moyen pondéré, groupés par
    //    "AAAA-MM" (mois) et "AAAA" (année), à partir des saisies brutes.
    // ==========================================================
    const parMois = groupBy(data, d => String(d.date_saisie).slice(0, 7));   // "2026-07"
    const moisTries = Array.from(parMois.keys()).sort();
    const productionParMois = moisTries.map(m => parMois.get(m).reduce((a, l) => a + num(l.production_totale), 0));
    const tauxParMois = moisTries.map(m => {
        const lignes = parMois.get(m);
        const sumProd = lignes.reduce((a, l) => a + num(l.production_totale), 0);
        const sumEff  = lignes.reduce((a, l) => a + num(l.effectif_present), 0);
        return sumEff > 0 ? (sumProd / sumEff) * 100 : 0;
    });
    const moisAffiches = moisTries.map(m => {
        const [an, mo] = m.split('-');
        const noms = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
        return noms[parseInt(mo, 10) - 1] + ' ' + an;
    });

    const parAnnee = groupBy(data, d => String(d.date_saisie).slice(0, 4));  // "2026"
    const anneesTriees = Array.from(parAnnee.keys()).sort();
    const productionParAnnee = anneesTriees.map(a => parAnnee.get(a).reduce((acc, l) => acc + num(l.production_totale), 0));

    // ==========================================================
    // 5) Cartes KPI (indicateurs clés calculés en JS)
    // ==========================================================
    const productionTotalePeriode = data.reduce((a, l) => a + num(l.production_totale), 0);
    const mortaliteTotalePeriode  = data.reduce((a, l) => a + num(l.mortalite), 0);
    const tauxPonteMoyenPeriode   = (() => {
        const sumEff = data.reduce((a, l) => a + num(l.effectif_present), 0);
        return sumEff > 0 ? (productionTotalePeriode / sumEff) * 100 : 0;
    })();
    const stockTotalDisponible = stockBlocs.reduce((a, b) => a + num(b.stock_disponible), 0);

    const cardsHtml = `
        <div class="stat-card">
            <div class="stat-label">Production totale (période)</div>
            <div class="stat-value">${fmtInt(productionTotalePeriode)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Taux de ponte moyen pondéré</div>
            <div class="stat-value">${fmtPct(tauxPonteMoyenPeriode)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Mortalité cumulée</div>
            <div class="stat-value">${fmtInt(mortaliteTotalePeriode)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">% Tri global (moyen pondéré)</div>
            <div class="stat-value">${fmtPct(pctTriGlobal)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Stock d'oeufs disponible (tous blocs)</div>
            <div class="stat-value">${fmtInt(stockTotalDisponible)}</div>
        </div>
    `;
    document.getElementById('statsCards').innerHTML = cardsHtml;

    // ==========================================================
    // Rendu des graphiques (Chart.js)
    // ==========================================================
    const palette = ['#8bc34a', '#1e90ff', '#f4a300', '#c62828', '#7fa9cc', '#689f38', '#9c27b0'];
    const datesAffichees = dates.map(d => new Date(d).toLocaleDateString('fr-FR'));

    new Chart(document.getElementById('chartTauxPonte'), {
        type: 'line',
        data: {
            labels: datesAffichees,
            datasets: [{
                label: 'Taux de ponte moyen (%)',
                data: tauxParDate,
                borderColor: '#689f38',
                backgroundColor: 'rgba(139,195,74,0.2)',
                tension: 0.25,
                fill: true,
            }]
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });

    const chartProdBlocEl = document.getElementById('chartProdBloc');
    if (chartProdBlocEl) {
        new Chart(chartProdBlocEl, {
            type: 'bar',
            data: {
                labels: blocsNoms,
                datasets: [{
                    label: 'Production totale (oeufs)',
                    data: productionParBloc,
                    backgroundColor: palette,
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });
    }

    new Chart(document.getElementById('chartTri'), {
        type: 'doughnut',
        data: {
            labels: ['PC', 'Sale', 'Casse Vendue', 'Casse Non Vendue'],
            datasets: [{
                data: triRepartition,
                backgroundColor: ['#8bc34a', '#f4a300', '#1e90ff', '#c62828'],
            }]
        },
        options: { responsive: true }
    });

    new Chart(document.getElementById('chartMortalite'), {
        type: 'bar',
        data: {
            labels: blocsNoms,
            datasets: [{
                label: 'Mortalité cumulée',
                data: mortaliteParBloc,
                backgroundColor: '#c62828',
            }]
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });

    new Chart(document.getElementById('chartEau'), {
        type: 'line',
        data: {
            labels: datesAffichees,
            datasets: [{
                label: "Consommation d'eau (L)",
                data: consoEauParDate,
                borderColor: '#1e90ff',
                backgroundColor: 'rgba(30,144,255,0.15)',
                tension: 0.25,
                fill: true,
            }]
        },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });

    const chartStockEl = document.getElementById('chartStock');
    if (chartStockEl) {
        new Chart(chartStockEl, {
            type: 'bar',
            data: {
                labels: stockBlocs.map(b => b.nom),
                datasets: [{
                    label: "Stock disponible (oeufs)",
                    data: stockBlocs.map(b => num(b.stock_disponible)),
                    backgroundColor: '#7fa9cc',
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });
    }

    // ==========================================================
    // 6) Graphes d'évolution PAR MOIS et PAR ANNEE (changements dans le temps)
    // ==========================================================
    const chartProdMoisEl = document.getElementById('chartProdMois');
    if (chartProdMoisEl) {
        new Chart(chartProdMoisEl, {
            type: 'bar',
            data: {
                labels: moisAffiches,
                datasets: [{
                    label: 'Production totale par mois (oeufs)',
                    data: productionParMois,
                    backgroundColor: '#689f38',
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });
    }

    const chartTauxMoisEl = document.getElementById('chartTauxMois');
    if (chartTauxMoisEl) {
        new Chart(chartTauxMoisEl, {
            type: 'line',
            data: {
                labels: moisAffiches,
                datasets: [{
                    label: 'Taux de ponte moyen par mois (%)',
                    data: tauxParMois,
                    borderColor: '#1e90ff',
                    backgroundColor: 'rgba(30,144,255,0.15)',
                    tension: 0.25,
                    fill: true,
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });
    }

    const chartProdAnneeEl = document.getElementById('chartProdAnnee');
    if (chartProdAnneeEl) {
        new Chart(chartProdAnneeEl, {
            type: 'bar',
            data: {
                labels: anneesTriees,
                datasets: [{
                    label: 'Production totale par année (oeufs)',
                    data: productionParAnnee,
                    backgroundColor: '#f4a300',
                }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });
    }
})();