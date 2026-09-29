#!/usr/bin/env python3
import math
import mmap
import os
import select
import struct
import subprocess
import sys
import tempfile
import termios
import threading
import time
import tty

import requests
from PIL import Image, ImageDraw, ImageFont

BASE = os.getenv("CASA_BASE_URL", "https://maurinsoft.com.br/casa").rstrip("/")
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

class Framebuffer:
    def __init__(self, dev="/dev/fb0"):
        self.dev = dev
        self.fd = None
        self.mem = None
        self.width = 800
        self.height = 480
        self.bpp = 32
        try:
            with open("/sys/class/graphics/fb0/virtual_size", "r", encoding="ascii") as f:
                w, h = f.read().strip().split(",")
                self.width, self.height = int(w), int(h)
            with open("/sys/class/graphics/fb0/bits_per_pixel", "r", encoding="ascii") as f:
                self.bpp = int(f.read().strip())
            self.fd = os.open(dev, os.O_RDWR)
            size = self.width * self.height * max(2, self.bpp // 8)
            self.mem = mmap.mmap(self.fd, size, mmap.MAP_SHARED, mmap.PROT_WRITE | mmap.PROT_READ)
        except Exception:
            self.close()

    @property
    def available(self):
        return self.mem is not None

    def show(self, image):
        if not self.available:
            return
        image = image.resize((self.width, self.height)).convert("RGB")
        if self.bpp == 32:
            raw = image.tobytes("raw", "BGRX")
        elif self.bpp == 16:
            out = bytearray(self.width * self.height * 2)
            p = 0
            for r, g, b in image.getdata():
                v = ((r >> 3) << 11) | ((g >> 2) << 5) | (b >> 3)
                struct.pack_into("<H", out, p, v)
                p += 2
            raw = bytes(out)
        else:
            return
        self.mem.seek(0)
        self.mem.write(raw)

    def close(self):
        if self.mem is not None:
            try:
                self.mem.close()
            except Exception:
                pass
        self.mem = None
        if self.fd is not None:
            try:
                os.close(self.fd)
            except Exception:
                pass
        self.fd = None

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
            "capabilities": ["avatar","speaker","microphone","kinect_rgb","kinect_depth","vision"],
            "data": {"avatar_state": self.state},
        }
        try:
            requests.post(BASE + "/api/v1/device.php?acao=heartbeat", json=payload, headers=device_headers(), timeout=8)
        except Exception:
            pass

    def record(self, wav):
        subprocess.run([
            "arecord","-q","-D",CAPTURE_DEV,"-f","S16_LE","-r","16000","-c","1",
            "-d",str(LISTEN_SECONDS),wav
        ], check=True, timeout=LISTEN_SECONDS + 10)

    def stt(self, wav):
        with open(wav, "rb") as f:
            r=requests.post(BASE+"/api/v1/avatar.php?acao=stt",headers=auth_headers(),
                            files={"audio":("speech.wav",f,"audio/wav")},timeout=90)
        r.raise_for_status()
        j=r.json()
        text=str(j.get("texto") or j.get("text") or "").strip()
        if not text:
            raise RuntimeError("STT não retornou texto")
        return text

    def capture_scene(self):
        subprocess.run(["kinect-snapshot",RGB_FILE,DEPTH_FILE],check=True,timeout=15)
        jpg="/tmp/casa-kinect-rgb.jpg"
        Image.open(RGB_FILE).convert("RGB").save(jpg,"JPEG",quality=78)
        return jpg

    def vision(self, prompt, jpg):
        with open(jpg,"rb") as f:
            r=requests.post(BASE+"/api/v1/avatar.php?acao=vision",headers=auth_headers(),
                            data={"prompt":prompt},
                            files={"image":("kinect.jpg",f,"image/jpeg")},timeout=120)
        if r.status_code >= 400:
            return ""
        return str(r.json().get("descricao") or "").strip()

    def ask(self, text, visual=""):
        command=text
        if visual:
            command += "\n\nContexto visual do Kinect: " + visual
        r=requests.post(BASE+"/api/v1/comando",headers=auth_headers(),
                        json={"comando":command,"ia_mode":"auto","origem":DEVICE_ID},timeout=120)
        r.raise_for_status()
        j=r.json()
        answer=str(j.get("resposta") or "").strip()
        if not answer:
            raise RuntimeError("JARVIS não retornou resposta")
        return answer,j.get("audio_url")

    def speak(self, text, audio_url):
        if audio_url:
            try:
                url=audio_url if str(audio_url).startswith("http") else BASE+str(audio_url)
                rr=requests.get(url,headers=auth_headers(),timeout=60)
                rr.raise_for_status()
                with tempfile.NamedTemporaryFile(suffix=".wav",delete=False) as f:
                    path=f.name
                    f.write(rr.content)
                try:
                    subprocess.run(["aplay","-q","-D",PLAYBACK_DEV,path],check=True,timeout=180)
                    return
                finally:
                    try: os.unlink(path)
                    except OSError: pass
            except Exception:
                pass
        subprocess.run(["espeak","-v","pt-br","-s","155",text],check=False,timeout=180)

    def interaction(self):
        if self.busy:
            return
        self.busy=True
        wav=None
        try:
            self.state_set(LISTENING,"Estou ouvindo...")
            with tempfile.NamedTemporaryFile(suffix=".wav",delete=False) as f:
                wav=f.name
            self.record(wav)
            self.state_set(THINKING,"Reconhecendo a fala...")
            text=self.stt(wav)
            self.message="Você: "+text

            visual=""
            if VISION_ENABLED:
                try:
                    self.state_set(SEEING,"Observando pelo Kinect...")
                    jpg=self.capture_scene()
                    visual=self.vision(text,jpg)
                except Exception as exc:
                    self.last_error="Visão indisponível: "+str(exc)

            self.state_set(THINKING,"Consultando o JARVIS...")
            answer,audio=self.ask(text,visual)
            self.state_set(SPEAKING,answer)
            self.speak(answer,audio)
            self.state_set(IDLE,"ENTER para falar")
        except Exception as exc:
            self.last_error=str(exc)
            self.state_set(ERROR,self.last_error)
            time.sleep(3)
            self.state_set(IDLE,"ENTER para tentar novamente")
        finally:
            if wav:
                try: os.unlink(wav)
                except OSError: pass
            self.busy=False

    def start(self):
        threading.Thread(target=self.interaction,daemon=True).start()

def wrap(text, limit=62):
    words=str(text).split()
    lines=[]
    current=""
    for word in words:
        test=(current+" "+word).strip()
        if len(test)>limit and current:
            lines.append(current)
            current=word
        else:
            current=test
    if current:
        lines.append(current)
    return lines

def draw_avatar(width, height, agent, t):
    img=Image.new("RGB",(width,height),(242,236,222))
    d=ImageDraw.Draw(img)
    font=ImageFont.load_default()
    colors={
        IDLE:(105,145,126),LISTENING:(223,138,84),SEEING:(83,132,174),
        THINKING:(150,116,174),SPEAKING:(203,153,67),ERROR:(192,83,94)
    }
    color=colors.get(agent.state,(100,100,100))
    cx,cy=width//2,max(150,height//2-35)
    radius=min(width,height)//5+int(6*math.sin(t*3))

    d.ellipse((cx-radius,cy-radius,cx+radius,cy+radius),outline=color,width=10,fill=(255,251,243))
    ey=cy-radius//4
    ex=radius//3
    if int(t*2)%13==0:
        d.line((cx-ex-12,ey,cx-ex+12,ey),fill=(35,35,35),width=4)
        d.line((cx+ex-12,ey,cx+ex+12,ey),fill=(35,35,35),width=4)
    else:
        d.ellipse((cx-ex-9,ey-9,cx-ex+9,ey+9),fill=(35,35,35))
        d.ellipse((cx+ex-9,ey-9,cx+ex+9,ey+9),fill=(35,35,35))

    my=cy+radius//3
    if agent.state==SPEAKING:
        mh=10+abs(int(16*math.sin(t*11)))
        d.ellipse((cx-32,my-mh//2,cx+32,my+mh//2),outline=(60,45,45),width=3)
    else:
        d.arc((cx-36,my-18,cx+36,my+22),0,180,fill=(60,45,45),width=4)

    d.text((25,20),"CASA / JARVIS",font=font,fill=(45,43,47))
    d.text((25,42),agent.state,font=font,fill=color)
    lines=wrap(agent.message)[-6:]
    y=height-35-len(lines)*15
    for line in lines:
        d.text((25,y),line,font=font,fill=(55,52,55))
        y+=15
    d.text((25,height-18),"ENTER: falar | ESC: sair",font=font,fill=(105,100,100))
    return img

def main():
    fb=Framebuffer()
    agent=Agent()
    old_term=None
    if sys.stdin.isatty():
        try:
            old_term=termios.tcgetattr(sys.stdin)
            tty.setcbreak(sys.stdin.fileno())
        except Exception:
            old_term=None

    try:
        next_hb=0.0
        while agent.running:
            now=time.time()
            if now>=next_hb:
                threading.Thread(target=agent.heartbeat,daemon=True).start()
                next_hb=now+45

            if sys.stdin.isatty():
                ready,_,_=select.select([sys.stdin],[],[],0)
                if ready:
                    ch=sys.stdin.read(1)
                    if ch=="\x1b":
                        agent.running=False
                    elif ch in ("\r","\n"," "):
                        agent.start()

            if fb.available:
                fb.show(draw_avatar(fb.width,fb.height,agent,now))
            else:
                print("\r[%s] %s" % (agent.state,agent.message[:100]),end="",flush=True)
            time.sleep(0.12)
    finally:
        if old_term is not None:
            termios.tcsetattr(sys.stdin,termios.TCSADRAIN,old_term)
        fb.close()

if __name__=="__main__":
    main()
