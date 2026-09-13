#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
JARVIS - Encaminha uma imagem da ESP32-CAM para uma IA multimodal
compatível com a API OpenAI Chat Completions.

Uso:
    python3 analisa_cena_ia.py /caminho/imagem.jpg '{"camera":"Entrada"}'

Variáveis de ambiente:
    JARVIS_VISION_AI_URL
      Ex.: https://api.runpod.ai/v2/ENDPOINT/openai/v1/chat/completions
      ou   http://127.0.0.1:8080/v1/chat/completions

    JARVIS_VISION_AI_MODEL
      Modelo multimodal/vision disponível no endpoint.

    JARVIS_VISION_AI_KEY
      Opcional para endpoint local; obrigatório quando o provedor exigir.

A análise deve descrever a cena, objetos e situação de segurança. Este módulo
não realiza identificação biométrica nominal de pessoas.
"""

import base64
import json
import mimetypes
import os
import sys
from pathlib import Path
from urllib import request, error


def emit(payload, code=0):
    print(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
    raise SystemExit(code)


def main():
    if len(sys.argv) < 2:
        emit({"sucesso": False, "erro": "Imagem não informada"}, 2)

    image_path = Path(sys.argv[1]).resolve()
    if not image_path.is_file():
        emit({"sucesso": False, "erro": "Imagem não encontrada", "arquivo": str(image_path)}, 3)

    metadata = {}
    if len(sys.argv) >= 3 and sys.argv[2].strip():
        try:
            metadata = json.loads(sys.argv[2])
        except Exception:
            metadata = {"metadados_brutos": sys.argv[2]}

    api_url = os.getenv("JARVIS_VISION_AI_URL", "").strip()
    model = os.getenv("JARVIS_VISION_AI_MODEL", "").strip()
    api_key = os.getenv("JARVIS_VISION_AI_KEY", "").strip()

    if not api_url or not model:
        emit({
            "sucesso": False,
            "erro": "IA visual não configurada",
            "necessario": ["JARVIS_VISION_AI_URL", "JARVIS_VISION_AI_MODEL"],
        }, 10)

    max_bytes = int(os.getenv("JARVIS_VISION_AI_MAX_IMAGE_BYTES", str(10 * 1024 * 1024)))
    raw = image_path.read_bytes()
    if len(raw) > max_bytes:
        emit({
            "sucesso": False,
            "erro": "Imagem excede o limite da IA visual",
            "tamanho_bytes": len(raw),
            "limite_bytes": max_bytes,
        }, 11)

    mime, _ = mimetypes.guess_type(image_path.name)
    if mime not in ("image/jpeg", "image/png", "image/webp"):
        mime = "image/jpeg"

    b64 = base64.b64encode(raw).decode("ascii")
    data_url = f"data:{mime};base64,{b64}"

    camera = metadata.get("camera") or metadata.get("nome_dispositivo") or "não informada"
    localizacao = metadata.get("localizacao") or "não informada"
    rostos = metadata.get("rostos", 0)

    system_prompt = (
        "Você é o módulo de visão do JARVIS Residencial. Analise imagens de câmera "
        "para segurança residencial. Descreva objetivamente a cena, quantidade aparente "
        "de pessoas, objetos relevantes, veículos, animais, sinais de invasão, risco ou "
        "atividade incomum. Não tente identificar nominalmente pessoas pela face e não "
        "faça inferências sobre atributos sensíveis. Responda em português em JSON válido "
        "com as chaves: resumo, pessoas_aparentes, objetos_relevantes, risco, nivel_risco, acao_sugerida."
    )

    user_text = (
        f"Evento da câmera: {camera}. Localização: {localizacao}. "
        f"O detector facial encontrou {rostos} rosto(s). Analise a imagem completa."
    )

    payload = {
        "model": model,
        "messages": [
            {"role": "system", "content": system_prompt},
            {
                "role": "user",
                "content": [
                    {"type": "text", "text": user_text},
                    {"type": "image_url", "image_url": {"url": data_url}},
                ],
            },
        ],
        "temperature": 0.2,
        "max_tokens": int(os.getenv("JARVIS_VISION_AI_MAX_TOKENS", "500")),
    }

    body = json.dumps(payload).encode("utf-8")
    headers = {"Content-Type": "application/json"}
    if api_key:
        headers["Authorization"] = f"Bearer {api_key}"

    req = request.Request(api_url, data=body, headers=headers, method="POST")

    try:
        with request.urlopen(req, timeout=float(os.getenv("JARVIS_VISION_AI_TIMEOUT", "25"))) as resp:
            response_body = resp.read().decode("utf-8", errors="replace")
            status = resp.getcode()
    except error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        emit({"sucesso": False, "erro": "IA visual retornou erro HTTP", "http_status": exc.code, "detalhe": detail[:2000]}, 20)
    except Exception as exc:
        emit({"sucesso": False, "erro": "Falha ao acessar IA visual", "detalhe": str(exc)}, 21)

    try:
        data = json.loads(response_body)
    except Exception:
        emit({"sucesso": False, "erro": "Resposta não-JSON da IA visual", "http_status": status, "resposta": response_body[:2000]}, 22)

    content = None
    try:
        content = data["choices"][0]["message"]["content"]
    except Exception:
        pass

    if not content:
        emit({"sucesso": False, "erro": "Resposta da IA visual sem conteúdo", "resposta_api": data}, 23)

    parsed = None
    if isinstance(content, str):
        clean = content.strip()
        if clean.startswith("```json"):
            clean = clean[7:]
        if clean.startswith("```"):
            clean = clean[3:]
        if clean.endswith("```"):
            clean = clean[:-3]
        try:
            parsed = json.loads(clean.strip())
        except Exception:
            parsed = None

    emit({
        "sucesso": True,
        "provedor": "openai_compatible_vision",
        "modelo": model,
        "analise": parsed if parsed is not None else content,
    }, 0)


if __name__ == "__main__":
    main()
