@echo off
title CASA - Gravador de Firmware (Lazarus)
cd /d "%~dp0"
if exist gravafirmware_new.exe (
    copy /y gravafirmware_new.exe gravafirmware.exe >nul 2>&1
    if not errorlevel 1 del /f /q gravafirmware_new.exe >nul 2>&1
)
if exist gravafirmware.exe (
    start "" gravafirmware.exe
) else (
    echo gravafirmware.exe nao encontrado. Tentando compilar...
    "C:\lazarus\lazbuild.exe" gravafirmware.lpi
    if exist gravafirmware.exe (
        start "" gravafirmware.exe
    ) else (
        echo Falha ao encontrar ou compilar gravafirmware.exe.
        pause
    )
)
