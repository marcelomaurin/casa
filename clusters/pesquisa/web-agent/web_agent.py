#!/usr/bin/env python3
import os
import re
import socket
import ipaddress
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

app = FastAPI(title="JARVIS Web Agent", version="1.0")

BRAVE_API_KEY = os.getenv("BRAVE_SEARCH_API_KEY", "").strip()
USER_AGENT = os.getenv("JARVIS_WEB_USER_AGENT", "JARVIS-Residencial/1.0")
TIMEOUT = float(os.getenv("JARVIS_WEB_TIMEOUT", "8"))
MAX_PAGE_CHARS = int(os.getenv("JARVIS_WEB_MAX_PAGE_CHARS", "12000"))

class SearchRequest(BaseModel):
    query: str = Field(min_length=2, max_length=500)
    max_results: int = Field(default=5, ge=1, le=10)
    fetch_pages: bool = True


def _host_publico(url: str) -> bool:
    try:
        p = urlparse(url)
        if p.scheme not in ("http", "https") or not p.hostname:
            return False
        infos = socket.getaddrinfo(p.hostname, p.port or (443 if p.scheme == "https" else 80))
        for info in infos:
            ip = ipaddress.ip_address(info[4][0])
            if (ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_multicast
                    or ip.is_reserved or ip.is_unspecified):
                return False
        return True
    except Exception:
        return False


def _limpar_texto(html: str) -> str:
    soup = BeautifulSoup(html, "html.parser")
    for tag in soup(["script", "style", "noscript", "svg", "form", "nav", "footer", "header"]):
        tag.decompose()
    text = " ".join(soup.stripped_strings)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:MAX_PAGE_CHARS]


def buscar_brave(query: str, n: int):
    if not BRAVE_API_KEY:
        return []
    r = requests.get(
        "https://api.search.brave.com/res/v1/web/search",
        params={"q": query, "count": n, "search_lang": "pt-br"},
        headers={"Accept": "application/json", "X-Subscription-Token": BRAVE_API_KEY,
                 "User-Agent": USER_AGENT},
        timeout=TIMEOUT,
    )
    r.raise_for_status()
    data = r.json()
    out = []
    for item in data.get("web", {}).get("results", [])[:n]:
        out.append({
            "titulo": item.get("title", ""),
            "url": item.get("url", ""),
            "trecho": item.get("description", ""),
            "provedor": "brave"
        })
    return out


def buscar_duckduckgo(query: str, n: int):
    # Fallback sem chave. O provedor principal recomendado e Brave Search API.
    r = requests.get(
        "https://html.duckduckgo.com/html/",
        params={"q": query},
        headers={"User-Agent": USER_AGENT},
        timeout=TIMEOUT,
    )
    r.raise_for_status()
    soup = BeautifulSoup(r.text, "html.parser")
    out = []
    for result in soup.select(".result"):
        a = result.select_one(".result__a")
        sn = result.select_one(".result__snippet")
        if not a:
            continue
        url = a.get("href", "")
        out.append({
            "titulo": a.get_text(" ", strip=True),
            "url": url,
            "trecho": sn.get_text(" ", strip=True) if sn else "",
            "provedor": "duckduckgo"
        })
        if len(out) >= n:
            break
    return out


def coletar_pagina(url: str):
    if not _host_publico(url):
        return {"ok": False, "erro": "URL bloqueada por politica SSRF"}
    try:
        r = requests.get(url, headers={"User-Agent": USER_AGENT}, timeout=TIMEOUT,
                         allow_redirects=True, stream=True)
        r.raise_for_status()
        ctype = r.headers.get("content-type", "").lower()
        if "text/html" not in ctype and "text/plain" not in ctype:
            return {"ok": False, "erro": "Tipo de conteudo nao suportado"}
        raw = r.content[:250000].decode(r.encoding or "utf-8", errors="ignore")
        return {"ok": True, "texto": _limpar_texto(raw)}
    except Exception as exc:
        return {"ok": False, "erro": str(exc)[:300]}


@app.get("/health")
def health():
    return {"status": "ok", "provider": "brave" if BRAVE_API_KEY else "duckduckgo"}


@app.post("/search")
def search(req: SearchRequest):
    query = req.query.strip()
    try:
        resultados = buscar_brave(query, req.max_results)
        if not resultados:
            resultados = buscar_duckduckgo(query, req.max_results)
    except Exception as exc:
        try:
            resultados = buscar_duckduckgo(query, req.max_results)
        except Exception as exc2:
            raise HTTPException(status_code=502, detail=f"Falha nos provedores de busca: {exc}; {exc2}")

    if req.fetch_pages:
        for item in resultados:
            pagina = coletar_pagina(item.get("url", ""))
            item["conteudo"] = pagina.get("texto", "") if pagina.get("ok") else ""
            if not pagina.get("ok"):
                item["erro_coleta"] = pagina.get("erro")

    return {
        "status": "ok",
        "query": query,
        "quantidade": len(resultados),
        "resultados": resultados,
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8099, log_level="info")
