<?php
/**
 * Widget flottant "Assistant CHAHIA" — icône en bas à droite qui ouvre un
 * petit panneau de chat connecté au serveur local Flask/Ollama (my-ai/app.py,
 * http://localhost:5001). Affiché uniquement côté UTILISATEUR (ouvrier).
 *
 * Ce fichier est inclus depuis includes/footer.php lorsque !estAdmin().
 * L'URL du serveur peut être changée ci-dessous si besoin (CHATBOT_API_URL).
 */
?>
<div id="chatbotIcon" class="chatbot-icon" title="Assistant CHAHIA" aria-label="Ouvrir l'assistant CHAHIA">
    <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
    </svg>
</div>

<div id="chatbotPanel" class="chatbot-panel" role="dialog" aria-label="Assistant CHAHIA">
    <div class="chatbot-header">
        <span>🐔 Assistant CHAHIA</span>
        <div class="chatbot-header-actions">
            <button type="button" id="chatbotReset" title="Effacer la conversation">↺</button>
            <button type="button" id="chatbotClose" title="Fermer">✕</button>
        </div>
    </div>
    <div id="chatbotMessages" class="chatbot-messages">
        <div class="chatbot-msg chatbot-msg-bot">
            Bonjour 👋 Je suis l'assistant CHAHIA. Pose-moi une question sur l'utilisation de l'application (saisie, TDB, factures, stock, statistiques...).
        </div>
    </div>
    <form id="chatbotForm" class="chatbot-form">
        <textarea id="chatbotInput" placeholder="Écris ta question ici..." rows="1" required></textarea>
        <button type="submit" id="chatbotSend" title="Envoyer">➤</button>
    </form>
</div>

<script>
(function () {
    // Adresse du serveur du chatbot (my-ai/app.py, lancé en local via demarrer.bat)
    var CHATBOT_API_URL = 'http://localhost:5001';

    // Endpoint PHP local (même origine, utilise la session) pour les
    // questions portant sur les DONNÉES réelles (production, stock, prix...).
    var IA_QUERY_URL = 'ia_query.php';

    // Mots-clés signalant une question d'AIDE évidente (navigation, "comment
    // faire...") : on part directement sur l'assistant Flask, pas besoin
    // d'interroger la base pour ça.
    var MOTS_CLES_AIDE = /comment\s+(faire|saisir|acc[ée]der|ouvrir|trouver|utiliser)|où\s+(est|se trouve|puis-je)|quelle?\s+page|quel\s+menu|comment\s+naviguer|c'est\s+quoi|qu'est-ce\s+que|explique(?:-moi)?\s+(la|le|l')/i;

    function appelerAssistantAide(botDiv, text) {
        fetch(CHATBOT_API_URL + '/chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: text })
        })
        .then(function (response) {
            if (!response.ok || !response.body) {
                throw new Error('reponse_invalide');
            }
            var reader = response.body.getReader();
            var decoder = new TextDecoder('utf-8');
            var fullText = '';
            botDiv.textContent = '';

            function lire() {
                return reader.read().then(function (result) {
                    if (result.done) return;
                    fullText += decoder.decode(result.value, { stream: true });
                    botDiv.textContent = fullText;
                    scrollToBottom();
                    return lire();
                });
            }
            return lire();
        })
        .catch(function () {
            botDiv.textContent = "⚠️ Le serveur de l'assistant (localhost:5001) ne répond pas. Vérifie qu'il est bien démarré (demarrer.bat) sur ce poste.";
        })
        .finally(function () {
            input.disabled = false;
            sendBtn.disabled = false;
            input.focus();
        });
    }

    var icon     = document.getElementById('chatbotIcon');
    var panel    = document.getElementById('chatbotPanel');
    var closeBtn = document.getElementById('chatbotClose');
    var resetBtn = document.getElementById('chatbotReset');
    var form     = document.getElementById('chatbotForm');
    var input    = document.getElementById('chatbotInput');
    var messages = document.getElementById('chatbotMessages');
    var sendBtn  = document.getElementById('chatbotSend');

    function scrollToBottom() { messages.scrollTop = messages.scrollHeight; }

    function addMessage(text, who) {
        var div = document.createElement('div');
        div.className = 'chatbot-msg chatbot-msg-' + who;
        div.textContent = text;
        messages.appendChild(div);
        scrollToBottom();
        return div;
    }

    icon.addEventListener('click', function () {
        panel.classList.toggle('open');
        if (panel.classList.contains('open')) {
            input.focus();
        }
    });
    closeBtn.addEventListener('click', function () {
        panel.classList.remove('open');
    });

    resetBtn.addEventListener('click', function () {
        fetch(CHATBOT_API_URL + '/reset', { method: 'POST' }).catch(function () {});
        fetch(IA_QUERY_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reset: true })
        }).catch(function () {});
        messages.innerHTML = '';
        addMessage("Conversation effacée. Comment puis-je t'aider ?", 'bot');
    });

    // La zone de texte grandit automatiquement avec le contenu (jusqu'à 4 lignes)
    input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 100) + 'px';
    });

    // Entrée = envoyer, Maj+Entrée = nouvelle ligne
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text) return;

        addMessage(text, 'user');
        input.value = '';
        input.style.height = 'auto';
        input.disabled = true;
        sendBtn.disabled = true;

        var botDiv = addMessage('...', 'bot');

        if (MOTS_CLES_AIDE.test(text)) {
            appelerAssistantAide(botDiv, text);
            return;
        }

        // Mode "question sur les données" : PHP génère le SQL via Ollama,
        // l'exécute (lecture seule, filtré sur ton bloc), puis Ollama
        // rédige la réponse à partir des résultats. Si la question ne
        // correspond à aucune donnée disponible, on bascule automatiquement
        // sur l'assistant d'aide (navigation).
        fetch(IA_QUERY_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: text })
        })
        .then(function (response) {
            return response.text().then(function (raw) {
                var data;
                try { data = JSON.parse(raw); }
                catch (e) {
                    throw new Error('Réponse non-JSON (HTTP ' + response.status + '): ' + raw.slice(0, 300));
                }
                return data;
            });
        })
        .then(function (data) {
            if (data && data.non_applicable) {
                appelerAssistantAide(botDiv, text);
                return;
            }
            botDiv.textContent = (data && (data.reponse || data.erreur)) || "Je n'ai pas pu traiter cette question.";
            if (data && data.debug) {
                botDiv.textContent += '\n\n[debug] ' + data.debug;
            }
            scrollToBottom();
            input.disabled = false;
            sendBtn.disabled = false;
            input.focus();
        })
        .catch(function (err) {
            console.error('ia_query.php error:', err);
            botDiv.textContent = "⚠️ Impossible de récupérer les données pour l'instant (" + err.message + ").";
            input.disabled = false;
            sendBtn.disabled = false;
            input.focus();
        });
    });
})();
</script>
