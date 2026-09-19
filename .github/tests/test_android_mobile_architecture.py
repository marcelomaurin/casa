#!/usr/bin/env python3
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "android" / "JarvisMobile" / "app" / "src" / "main" / "java" / "br" / "com" / "maurinsoft" / "jarvismobile"
errors = []

required = [
    "MobileRoute.kt",
    "MobileAppState.kt",
    "AppStrings.kt",
    "SpeechInputController.kt",
    "JarvisAudioPlayer.kt",
    "JarvisNetworkMonitor.kt",
    "WatchCommandDispatcher.kt",
    "WatchEventProcessor.kt",
    "PhoneSensorProvider.kt",
]
for name in required:
    if not (SRC / name).exists():
        errors.append(f"Componente extraido ausente: {name}")

main = (SRC / "MainActivity.kt").read_text(encoding="utf-8")
service = (SRC / "JarvisConnectionService.kt").read_text(encoding="utf-8")

if len(main.splitlines()) > 600:
    errors.append(f"MainActivity voltou a crescer demais: {len(main.splitlines())} linhas")

for forbidden in ["SpeechRecognizer", "OkHttpClient", "MediaPlayer", "AudioAttributes", "RecognizerIntent"]:
    if forbidden in main:
        errors.append(f"MainActivity voltou a concentrar regra/infra: {forbidden}")

if "private enum class Route" in main:
    errors.append("Navegacao voltou a ser declarada dentro de MainActivity")

if len(service.splitlines()) > 800:
    errors.append(f"JarvisConnectionService voltou a crescer demais: {len(service.splitlines())} linhas")

for forbidden in ["ConnectivityManager.NetworkCallback", "NetworkCapabilities.TRANSPORT_WIFI", "WifiManager"]:
    if forbidden in service:
        errors.append(f"Service voltou a controlar rede diretamente: {forbidden}")

if "private suspend fun handleWatchEvent" in service:
    errors.append("Regras semanticas do Watch voltaram para JarvisConnectionService")

processor = (SRC / "WatchEventProcessor.kt").read_text(encoding="utf-8") if (SRC / "WatchEventProcessor.kt").exists() else ""
if "interface Actions" not in processor:
    errors.append("WatchEventProcessor perdeu sua interface testavel")

monitor = (SRC / "JarvisNetworkMonitor.kt").read_text(encoding="utf-8") if (SRC / "JarvisNetworkMonitor.kt").exists() else ""
if "interface Listener" not in monitor:
    errors.append("JarvisNetworkMonitor perdeu sua interface testavel")

if errors:
    print("Falhas na arquitetura do JARVIS Mobile:")
    for error in errors:
        print(" -", error)
    sys.exit(1)

print("Android architecture OK: Activity/Service permanecem decompostos.")
