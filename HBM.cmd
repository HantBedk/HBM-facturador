@echo off
REM Ejecutar desde CMD o doble clic (NO uses doble clic en HBM.ps1: Windows abre "Abrir con").
REM   HBM.cmd              → Docker + hbm:sync + Vite
REM   HBM.cmd -Levantar    → Docker + Vite solamente (sin tocar BD)
REM   HBM.cmd -Build       → rebuild imágenes + lo anterior
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0HBM.ps1" %*
