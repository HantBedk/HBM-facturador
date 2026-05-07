@echo off
REM Doble clic aquí = levantar Docker + Vite SIN migraciones ni seeders.
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0HBM.ps1" -Levantar
