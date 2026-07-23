============================
  ELHANA AI — Serveur Flask
============================

PRÉREQUIS :
  - Python 3.x installé
  - Ollama installé et lancé (https://ollama.com)
  - Modèle qwen2.5 téléchargé : ollama pull qwen2.5

DÉMARRAGE :
  Double-cliquez sur  demarrer.bat
  OU dans un terminal :
    pip install flask flask-cors requests
    python app.py

Le serveur tourne sur : http://localhost:5001

ENDPOINTS :
  POST /chat   → envoyer un message  { "message": "..." }
  POST /reset  → effacer l'historique
  GET  /health → vérifier que ça tourne

MODIFIER LE CHATBOT :
  Ouvrez app.py et modifiez la variable SYSTEM_PROMPT
  pour changer les informations d'ELHANA.
