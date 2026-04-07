@echo off
REM Arranque del proyecto (Docker + migrate + seed + Vite). Opcion: -Build
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0HBM.ps1" %*
