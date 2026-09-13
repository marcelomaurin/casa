#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
JARVIS - Processamento de imagens da ESP32-CAM com Google Cloud Vision.

Uso:
    python3 processa_imagem.py /caminho/para/imagem.jpg

Saida:
    JSON em stdout.

Autenticacao Google:
    export GOOGLE_APPLICATION_CREDENTIALS=/etc/jarvis/google-vision.json

Observacao importante:
    Google Cloud Vision faz DETECCAO FACIAL, nao reconhecimento da identidade
    individual da pessoa.
"""

import json
import os
import sys
from pathlib import Path


def saida(payload, exit_code=0):
    print(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
    raise SystemExit(exit_code)


def vertex_to_dict(vertex):
    return {
        "x": int(getattr(vertex, "x", 0) or 0),
        "y": int(getattr(vertex, "y", 0) or 0),
    }


def likelihood_name(value):
    nomes = {
        0: "UNKNOWN",
        1: "VERY_UNLIKELY",
        2: "UNLIKELY",
        3: "POSSIBLE",
        4: "LIKELY",
        5: "VERY_LIKELY",
    }
    try:
        return nomes.get(int(value), "UNKNOWN")
    except Exception:
        return "UNKNOWN"


def face_to_dict(face, indice):
    vertices = [vertex_to_dict(v) for v in face.bounding_poly.vertices]

    xs = [v["x"] for v in vertices] or [0]
    ys = [v["y"] for v in vertices] or [0]

    x_min = min(xs)
    y_min = min(ys)
    x_max = max(xs)
    y_max = max(ys)

    return {
        "indice": indice,
        "confianca_deteccao": round(float(face.detection_confidence or 0.0), 6),
        "confianca_landmarks": round(float(face.landmarking_confidence or 0.0), 6),
        "bounding_box": {
            "x": x_min,
            "y": y_min,
            "largura": max(0, x_max - x_min),
            "altura": max(0, y_max - y_min),
            "vertices": vertices,
        },
        "angulos": {
            "roll": round(float(face.roll_angle or 0.0), 3),
            "pan": round(float(face.pan_angle or 0.0), 3),
            "tilt": round(float(face.tilt_angle or 0.0), 3),
        },
        "atributos": {
            "alegria": likelihood_name(face.joy_likelihood),
            "tristeza": likelihood_name(face.sorrow_likelihood),
            "raiva": likelihood_name(face.anger_likelihood),
            "surpresa": likelihood_name(face.surprise_likelihood),
            "subexposta": likelihood_name(face.under_exposed_likelihood),
            "desfocada": likelihood_name(face.blurred_likelihood),
            "chapeu": likelihood_name(face.headwear_likelihood),
        },
    }


def main():
    if len(sys.argv) != 2:
        saida({
            "sucesso": False,
            "erro": "Uso: processa_imagem.py /caminho/imagem.jpg",
            "rostos": 0,
            "faces": [],
        }, 2)

    image_path = Path(sys.argv[1]).resolve()

    if not image_path.exists() or not image_path.is_file():
        saida({
            "sucesso": False,
            "erro": "Arquivo de imagem nao encontrado",
            "arquivo": str(image_path),
            "rostos": 0,
            "faces": [],
        }, 3)

    max_bytes = int(os.getenv("JARVIS_VISION_MAX_IMAGE_BYTES", str(10 * 1024 * 1024)))
    tamanho = image_path.stat().st_size
    if tamanho <= 0:
        saida({
            "sucesso": False,
            "erro": "Arquivo de imagem vazio",
            "arquivo": str(image_path),
            "rostos": 0,
            "faces": [],
        }, 4)

    if tamanho > max_bytes:
        saida({
            "sucesso": False,
            "erro": "Imagem excede o tamanho maximo permitido",
            "arquivo": str(image_path),
            "tamanho_bytes": tamanho,
            "limite_bytes": max_bytes,
            "rostos": 0,
            "faces": [],
        }, 5)

    try:
        from google.cloud import vision
    except Exception as exc:
        saida({
            "sucesso": False,
            "erro": "Biblioteca google-cloud-vision nao instalada",
            "detalhe": str(exc),
            "rostos": 0,
            "faces": [],
        }, 10)

    try:
        content = image_path.read_bytes()
        client = vision.ImageAnnotatorClient()
        image = vision.Image(content=content)
        max_results = max(1, min(int(os.getenv("JARVIS_VISION_MAX_FACES", "20")), 100))

        response = client.face_detection(image=image, max_results=max_results)

        if response.error.message:
            saida({
                "sucesso": False,
                "erro": "Google Cloud Vision retornou erro",
                "detalhe": response.error.message,
                "rostos": 0,
                "faces": [],
            }, 20)

        faces = [face_to_dict(face, idx + 1) for idx, face in enumerate(response.face_annotations)]
        confianca_max = max((f["confianca_deteccao"] for f in faces), default=0.0)

        saida({
            "sucesso": True,
            "provedor": "google_cloud_vision",
            "recurso": "FACE_DETECTION",
            "arquivo": image_path.name,
            "tamanho_bytes": tamanho,
            "rostos": len(faces),
            "confianca_max": round(confianca_max, 6),
            "faces": faces,
        }, 0)

    except Exception as exc:
        saida({
            "sucesso": False,
            "erro": "Falha ao processar imagem",
            "detalhe": str(exc),
            "rostos": 0,
            "faces": [],
        }, 30)


if __name__ == "__main__":
    main()
