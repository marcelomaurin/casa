#!/usr/bin/env python3
import math
import os
import subprocess
import tempfile
import threading
import time

import pygame
import requests
from PIL import Image

BASE = os.getenv("CASA_BASE_URL", "https://casa.maurinsoft.com.br").rstrip("/")
API_TOKEN = os.getenv("CASA_API_TOKEN", "")
DEVICE_ID = os.getenv("CASA_DEVICE_ID", "avatar-rpi-01")
DEVICE_TOKEN = os.getenv("CASA_DEVICE_TOKEN", "")
CAPTURE_DEV = os.getenv("AUDIO_CAPTURE_DEVICE", "default")
PLAYBACK_DEV = os.getenv("AUDIO_PLAYBACK_DEVICE", "default")
LISTEN_SECONDS = max(2, int(os.getenv("LISTEN_SECONDS", "5")))
VISION_ENABLED = os.getenv("VISION_ENABLED", "1").lower() not in ("0", "false", "off", "no")
RGB_FILE = os.getenv("KINECT_RGB_FILE", "/tmp/casa-kinect-rgb.ppm")
DEPTH_FILE = os.getenv("KINECT_DEPTH_FILE", "/tmp/casa-kinect-depth.pgm")

IDLE, LISTENING, SEEING, THINKING, SPEAKING, ERROR = (
    "IDLE", "LISTENING", "SEEING", "THINKING", "SPEAKING", "ERROR"
)

def auth_headers():
    return {"Authorization": "Bearer " + API_TOKEN} if API_TOKEN else {}

def device_headers():
    return {"X-Device-Token": DEVICE_TOKEN} if DEVICE_TOKEN else {}

class Agent:
    def __init__(self):
        self.state = IDLE
        self.message = "ENTER para falar"
        self.busy = False
        self.running = True
        self.last_error = ""

    def state_set(self, value, message=None):
        self.state = value
        if message is not None:
            self.message = message

    def heartbeat(self):
        if not DEVICE_TOKEN:
            return
        payload = {
            "device_id": DEVICE_ID,
            "transport": "ethernet-wifi",
            "health": "ok",
            "manufacturer": "Raspberry Pi",
            "model": "CASA Kinect Avatar",
            "firmware_version": "yocto-1.0",
            "protocol_version": "1",
            "capabilities": [
                "avatar", "speaker", "microphone",
                "kinect_rgb", "kinect_depth", "vision"
            ],
            "data": {"avatar_state": self.state},
        }
        try:
            requests.post(
                BASE + "/api/v1/device.php?acao=heartbeat",
                json=payload, headers=device_headers(), timeout=8
            )
        except Exception:
            pass

    def record(self, wav):
        subprocess.run([
            "arecord", "-q", "-D", CAPTURE_DEV,
            "-f", "S16_LE", "-r", "16000", "-c", "1",
            "-d", str(LISTEN_SECONDS), wav
        ], check=True, timeout=LISTEN_SECONDS + 10)

    def stt(self, wav):
        with open(wav, "rb") as f:
            r = requests.post(
                BASE + "/api/v1/avatar.php?acao=stt",
                headers=auth_headers(),
                files={"audio": ("speech.wav", f, "audio/wav")},
                timeout=90,
            )
        r.raise_for_status()
        j = r.json()
        text = str(j.get("texto") or j.get("text") or "").strip()
        if not text:
            raise RuntimeError("STT não retornou texto")
        return text

    def capture_scene(self):
        subprocess.run(
            ["kinect-snapshot", RGB_FILE, DEPTH_FILE],
            check=True, timeout=15
        )
        jpg = "/tmp/casa-kinect-rgb.jpg"
        Image.open(RGB_FILE).convert("RGB").save(jpg, "JPEG", quality=78)
        return jpg

    def vision(self, prompt, jpg):
        with open(jpg, "rb") as f:
            r = requests.post(
                BASE + "/api/v1/avatar.php?acao=vision",
                headers=auth_headers(),
                data={"prompt": prompt},
                files={"image": ("kinect.jpg", f, "image/jpeg")},
                timeout=120,
            )
        if r.status_code >= 400:
            return ""
        return str(r.json().get("descricao") or "").strip()

    def ask(self, text, visual=""):
        command = text
        if visual:
            command += "\n\nContexto visual do Kinect: " + visual
        r = requests.post(
            BASE + "/api/v1/comando",
            headers=auth_headers(),
            json={"comando": command, "ia_mode": "auto", "origem": DEVICE_ID},
            timeout=120,
        )
        r.raise_for_status()
        j = r.json()
        answer = str(j.get("resposta") or "").strip()
        if not answer:
            raise RuntimeError("JARVIS não retornou resposta")
        return answer, j.get("audio_url")

    def speak(self, text, audio_url):
        if audio_url:
            try:
                url = audio_url if str(audio_url).startswith("http") else BASE + str(audio_url)
                r = requests.get(url, headers=auth_headers(), timeout=60)
                r.raise_for_status()
                with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
                    path = f.name
                    f.write(r.content)
                try:
                    subprocess.run(
                        ["aplay", "-q", "-D", PLAYBACK_DEV, path],
                        check=True, timeout=180
                    )
                    return
                finally:
                    try:
                        os.unlink(path)
                    except OSError:
                        pass
            except Exception:
                pass

        subprocess.run(
            ["espeak-ng", "-v", "pt-br", "-s", "155", text],
            check=False, timeout=180
        )

    def interaction(self):
        if self.busy:
            return
        self.busy = True
        wav = None
        try:
            self.state_set(LISTENING, "Estou ouvindo...")
            with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as f:
                wav = f.name
            self.record(wav)

            self.state_set(THINKING, "Reconhecendo a fala...")
            text = self.stt(wav)
            self.message = "Você: " + text

            visual = ""
            if VISION_ENABLED:
                try:
                    self.state_set(SEEING, "Observando pelo Kinect...")
                    jpg = self.capture_scene()
                    visual = self.vision(text, jpg)
                except Exception as exc:
                    self.last_error = "Visão indisponível: " + str(exc)

            self.state_set(THINKING, "Consultando o JARVIS...")
            answer, audio = self.ask(text, visual)

            self.state_set(SPEAKING, answer)
            self.speak(answer, audio)
            self.state_set(IDLE, "ENTER para falar")
        except Exception as exc:
            self.last_error = str(exc)
            self.state_set(ERROR, self.last_error)
            time.sleep(3)
            self.state_set(IDLE, "ENTER para tentar novamente")
        finally:
            if wav:
                try:
                    os.unlink(wav)
                except OSError:
                    pass
            self.busy = False

    def start(self):
        threading.Thread(target=self.interaction, daemon=True).start()

def wrap(text, limit=70):
    out = []
    text = str(text)
    while text:
        cut = min(limit, len(text))
        if len(text) > cut:
            sp = text.rfind(" ", 0, cut)
            if sp > 20:
                cut = sp
        out.append(text[:cut])
        text = text[cut:].lstrip()
    return out

def draw(screen, agent, title_font, font, t):
    w, h = screen.get_size()
    screen.fill((242, 236, 222))
    colors = {
        IDLE:(105,145,126), LISTENING:(223,138,84), SEEING:(83,132,174),
        THINKING:(150,116,174), SPEAKING:(203,153,67), ERROR:(192,83,94)
    }
    color = colors.get(agent.state, (100,100,100))
    cx, cy = w//2, max(170, h//2 - 40)
    radius = min(w,h)//5 + int(7*math.sin(t*3))

    pygame.draw.circle(screen, color, (cx,cy), radius, 10)
    pygame.draw.circle(screen, (255,251,243), (cx,cy), radius-16)

    eye_y, eye_dx = cy-radius//4, radius//3
    if int(t*2)%13 == 0:
        for ex in (cx-eye_dx,cx+eye_dx):
            pygame.draw.line(screen,(35,35,35),(ex-12,eye_y),(ex+12,eye_y),4)
    else:
        pygame.draw.circle(screen,(35,35,35),(cx-eye_dx,eye_y),10)
        pygame.draw.circle(screen,(35,35,35),(cx+eye_dx,eye_y),10)

    my = cy + radius//3
    if agent.state == SPEAKING:
        mh = 12 + abs(int(18*math.sin(t*11)))
        pygame.draw.ellipse(screen,(60,45,45),(cx-35,my-mh//2,70,mh),3)
    else:
        pygame.draw.arc(screen,(60,45,45),(cx-38,my-18,76,38),0.15,2.95,4)

    screen.blit(title_font.render("CASA · JARVIS",True,(45,43,47)),(28,22))
    screen.blit(font.render(agent.state,True,color),(28,67))

    lines = wrap(agent.message)[-5:]
    y = h - 55 - len(lines)*25
    for line in lines:
        screen.blit(font.render(line,True,(55,52,55)),(28,y))
        y += 25
    screen.blit(font.render("ENTER/ESPAÇO: falar · ESC: sair",True,(105,100,100)),(28,h-32))

def main():
    pygame.init()
    info = pygame.display.Info()
    screen = pygame.display.set_mode(
        (info.current_w or 800, info.current_h or 480), pygame.FULLSCREEN
    )
    pygame.mouse.set_visible(False)
    title_font = pygame.font.Font(None, 44)
    font = pygame.font.Font(None, 27)
    clock = pygame.time.Clock()
    agent = Agent()
    next_hb = 0.0

    while agent.running:
        now = time.time()
        if now >= next_hb:
            threading.Thread(target=agent.heartbeat, daemon=True).start()
            next_hb = now + 45
        for ev in pygame.event.get():
            if ev.type == pygame.QUIT:
                agent.running = False
            elif ev.type == pygame.KEYDOWN:
                if ev.key == pygame.K_ESCAPE:
                    agent.running = False
                elif ev.key in (pygame.K_RETURN, pygame.K_SPACE):
                    agent.start()
        draw(screen, agent, title_font, font, now)
        pygame.display.flip()
        clock.tick(30)

    pygame.quit()

if __name__ == "__main__":
    main()
