@echo off
echo === Demarrage du serveur ELHANA AI ===
echo.

:: Installer les dependances si besoin
pip install flask flask-cors requests >nul 2>&1

:: Lancer Flask
echo Serveur demarre sur http://localhost:5001
echo Appuyez sur CTRL+C pour arreter.
echo.
python app.py
pause
