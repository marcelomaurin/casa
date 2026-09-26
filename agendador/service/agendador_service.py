"""Serviço local do Agendador FATEC.

Responsabilidades: descoberta TCP, configuração do ESP32, envio autenticado,
retries e execução de agendamentos. O Lazarus usa a mesma base SQLite e pode
chamar a API local em http://127.0.0.1:8765.
"""
from __future__ import annotations
import base64, datetime as dt, hashlib, json, os, queue, socket, sqlite3, threading, time, uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

DB_PATH = Path(os.environ.get("FATEC_AGENDADOR_DB", Path(os.environ.get("LOCALAPPDATA", ".")) / "FatecAgendador" / "agendador.sqlite3"))
TCP_PORT = 8090
API_PORT = int(os.environ.get("FATEC_AGENDADOR_API_PORT", "8765"))
SUBNET = os.environ.get("FATEC_AGENDADOR_SUBNET", "192.168.1")
jobs: queue.Queue[tuple[str, dict]] = queue.Queue()
stop_event = threading.Event()

def db():
    DB_PATH.parent.mkdir(parents=True, exist_ok=True)
    c = sqlite3.connect(DB_PATH, timeout=10)
    c.row_factory = sqlite3.Row
    return c

def init_db():
    with db() as c:
        c.executescript("""
        CREATE TABLE IF NOT EXISTS devices(id INTEGER PRIMARY KEY, hardware_id TEXT UNIQUE, name TEXT NOT NULL, ip TEXT NOT NULL DEFAULT '', ssid TEXT NOT NULL DEFAULT '', password TEXT NOT NULL DEFAULT '', token TEXT NOT NULL DEFAULT '', ap_ssid TEXT NOT NULL DEFAULT '', ap_password TEXT NOT NULL DEFAULT '', online INTEGER NOT NULL DEFAULT 0, last_seen TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY, title TEXT NOT NULL, line1 TEXT NOT NULL, line2 TEXT NOT NULL, line3 TEXT NOT NULL, line4 TEXT NOT NULL, qr TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS schedules(id INTEGER PRIMARY KEY, device_id INTEGER NOT NULL, message_id INTEGER NOT NULL, due TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, request_id TEXT NOT NULL, payload TEXT NOT NULL, error TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS history(id INTEGER PRIMARY KEY, at TEXT NOT NULL, device_id INTEGER, message TEXT NOT NULL);
        UPDATE schedules SET status='pending' WHERE status='sending';
        UPDATE devices SET online=0;
        """)

def tcp(ip: str, payload: dict, timeout=3.0) -> dict:
    wire = (json.dumps(payload, ensure_ascii=False) + "\n").encode("utf-8")
    with socket.create_connection((ip, TCP_PORT), timeout=timeout) as s:
        s.settimeout(timeout); s.sendall(wire); data = bytearray()
        while b"\n" not in data and len(data) < 16384:
            part = s.recv(1024)
            if not part: break
            data.extend(part)
        if b"\n" not in data: raise TimeoutError("resposta incompleta")
    r = json.loads(data.split(b"\n", 1)[0].decode("utf-8"))
    if not r.get("ok", False): raise RuntimeError(r.get("error", "ESP32 recusou o comando"))
    return r

def discover(subnet=SUBNET):
    found=[]; lock=threading.Lock()
    def one(i):
        try:
            r=tcp(f"{subnet}.{i}", {"op":"discover"}, .65)
            if r.get("product")=="FATEC_EPD" and r.get("protocol")==2:
                with lock: found.append({**r, "observed_ip":f"{subnet}.{i}"})
        except Exception: pass
    ts=[threading.Thread(target=one,args=(i,),daemon=True) for i in range(1,255)]
    for t in ts:t.start()
    for t in ts:t.join(1)
    now=dt.datetime.now().isoformat(timespec="seconds")
    with db() as c:
        c.execute("UPDATE devices SET online=0 WHERE ip LIKE ?", (subnet+".%",))
        for x in found:
            row=c.execute("SELECT id FROM devices WHERE hardware_id=?",(x["id"],)).fetchone()
            if row:c.execute("UPDATE devices SET ip=?,online=1,last_seen=?,name=COALESCE(NULLIF(name,''),?) WHERE id=?",(x["observed_ip"],now,x.get("name",""),row["id"]))
            else:c.execute("INSERT INTO devices(hardware_id,name,ip,online,last_seen,ap_ssid,ap_password) VALUES(?,?,?,?,?,?,?)",(x["id"],x.get("name",x["id"]),x["observed_ip"],1,now,x.get("ap_ssid",""),"fatec1234"))
    return found

def send(device_id, message, request_id=None):
    request_id=request_id or uuid.uuid4().hex
    with db() as c:
        d=c.execute("SELECT * FROM devices WHERE id=?",(device_id,)).fetchone()
    if not d: raise RuntimeError("equipamento não cadastrado")
    r=tcp(d["ip"],{"op":"set","token":d["token"],"request_id":request_id,"lines":[message["line1"],message["line2"],message["line3"],message["line4"]],"qr":message["qr"]},8)
    with db() as c:c.execute("INSERT INTO history(at,device_id,message) VALUES(?,?,?)",(dt.datetime.now().isoformat(timespec="seconds"),device_id,"Envio aceito pelo ESP32"))
    return r

def worker():
    while not stop_event.is_set():
        try:kind, args=jobs.get(timeout=.5)
        except queue.Empty:continue
        try:
            if kind=="scan": discover(args.get("subnet",SUBNET))
            elif kind=="send": send(args["device_id"],args["message"],args.get("request_id"))
        except Exception as e:
            with db() as c:c.execute("INSERT INTO history(at,device_id,message) VALUES(?,?,?)",(dt.datetime.now().isoformat(timespec="seconds"),args.get("device_id"),"ERRO: "+str(e)))
        finally: jobs.task_done()

def scheduler():
    while not stop_event.is_set():
        now=dt.datetime.now().isoformat(timespec="seconds")
        with db() as c: row=c.execute("SELECT * FROM schedules WHERE status='pending' AND due<=? ORDER BY due,id LIMIT 1",(now,)).fetchone()
        if row:
            with db() as c:c.execute("UPDATE schedules SET status='sending',attempts=attempts+1 WHERE id=?",(row["id"],))
            try:
                send(row["device_id"],json.loads(row["payload"]),row["request_id"])
                with db() as c:c.execute("UPDATE schedules SET status='sent',error='' WHERE id=?",(row["id"],))
            except Exception as e:
                with db() as c:
                    retry=row["attempts"]+1<5
                    c.execute("UPDATE schedules SET status=?,due=?,error=? WHERE id=?",("pending" if retry else "failed",(dt.datetime.now()+dt.timedelta(seconds=30)).isoformat(timespec="seconds"),str(e),row["id"]))
        time.sleep(1)

class API(BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def reply(self, code, obj):
        data=json.dumps(obj,ensure_ascii=False).encode();self.send_response(code);self.send_header("Content-Type","application/json; charset=utf-8");self.send_header("Content-Length",str(len(data)));self.end_headers();self.wfile.write(data)
    def do_GET(self):
        if self.path=="/status": self.reply(200,{"ok":True,"db":str(DB_PATH),"queue":jobs.qsize()});return
        if self.path.startswith("/scan"):
            jobs.put(("scan",{"subnet":SUBNET}));self.reply(202,{"ok":True,"queued":"scan"});return
        self.reply(404,{"ok":False,"error":"not_found"})
    def do_POST(self):
        try: body=json.loads(self.rfile.read(int(self.headers.get("Content-Length","0"))))
        except Exception:self.reply(400,{"ok":False,"error":"invalid_json"});return
        if self.path=="/send": jobs.put(("send",body));self.reply(202,{"ok":True,"queued":"send"});return
        self.reply(404,{"ok":False,"error":"not_found"})

def main():
    init_db(); threading.Thread(target=worker,daemon=True).start();threading.Thread(target=scheduler,daemon=True).start();
    api=ThreadingHTTPServer(("127.0.0.1",API_PORT),API)
    try: print(f"Agendador serviço ativo em 127.0.0.1:{API_PORT}",flush=True);api.serve_forever()
    except KeyboardInterrupt: pass
    finally: stop_event.set();api.server_close()
if __name__=="__main__": main()
