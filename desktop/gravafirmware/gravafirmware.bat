@echo off
title CASA JARVIS - GRAVAFIRMWARE & TOKEN MANAGER
cd /d "%~dp0"
python gravafirmware.py
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo Falha ao iniciar gravafirmware.py. Pressione qualquer tecla para sair.
    pause >nul
)
