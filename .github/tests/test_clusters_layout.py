#!/usr/bin/env python3
"""Verify discoverable cluster sources without importing agents or contacting hardware."""
import ast
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PROGRAMS = {
    'comunicacao/arm-agent': 'casa-node-agent.py',
    'automacao/casa-scheduler': 'casa-scheduler.py',
    'voz/google-home-agent': 'google_home_agent.py',
    'voz/tts': 'server_tts.py',
    'visao/espcam': 'processa_imagem.py',
    'visao/raspberrypi-avatar-agent': 'meta-casa-avatar/recipes-casa/casa-avatar/files/casa_avatar.py',
    'pesquisa/web-agent': 'web_agent.py',
    'infraestrutura/casa-tunnel': 'casa-tunnel.py',
    'infraestrutura/runpod-agent': 'casa-runpod-agent.py',
}
catalog = (ROOT / 'clusters/README.md').read_text(encoding='utf-8')
for relative, entry in PROGRAMS.items():
    directory = ROOT / 'clusters' / relative
    assert (directory / entry).is_file(), f'Missing program: {relative}'
    assert (directory.parent / 'README.md').is_file(), f'Missing category guide: {relative}'
    assert directory.name in catalog, f'Program missing from catalog: {relative}'

count = 0
for source in (ROOT / 'clusters').rglob('*.py'):
    ast.parse(source.read_text(encoding='utf-8-sig'), filename=str(source))
    count += 1

installer = (ROOT / 'clusters/comunicacao/arm-agent/install_node.sh').read_text()
assert 'master/clusters/comunicacao/arm-agent' in installer, 'Installer still downloads from old location'
assert 'INSTALL_DIR="/opt/casa-node-agent"' in installer, 'Existing installation destination changed'
guard = (ROOT / '.github/tests/test_api_v1_clients.py').read_text()
assert 'ROOT / "clusters" / "comunicacao" / "arm-agent"' in guard
assert 'ROOT / "clusters" / "visao" / "raspberrypi-avatar-agent"' in guard
assert not (ROOT / 'servicos/arm-agent').exists(), 'Duplicate ARM agent source'
assert not (ROOT / 'yocto/raspberrypi-avatar-agent').exists(), 'Duplicate avatar source'
assert (ROOT / 'servicos/device_simulator.py').is_file(), 'Development simulator moved unexpectedly'
print(f'Cluster organization OK: {len(PROGRAMS)} programs, 6 functional areas, {count} Python sources parsed.')
