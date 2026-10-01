#!/usr/bin/env python3
"""Gera manifesto a partir do conteúdo imutável de um commit, sem usar a worktree."""
import argparse
import hashlib
import importlib.util
import json
import subprocess
from pathlib import Path
spec = importlib.util.spec_from_file_location('updater', Path(__file__).with_name('casa-cluster-update.py'))
updater = importlib.util.module_from_spec(spec)
spec.loader.exec_module(updater)

def build(repo, commit, version):
    def git(*args):
        return subprocess.check_output(['git', '-C', repo] + list(args))
    commit = git('rev-parse', '--verify', commit + '^{commit}').decode().strip()
    manifest = {'schema': 1, 'version': version, 'source_commit': commit, 'components': {}}
    for name, (prefix, _, _) in updater.CATALOG.items():
        files = []
        paths = git('ls-tree', '-r', '--name-only', commit, '--', prefix).decode().splitlines()
        for source in paths:
            target = source[len(prefix):]
            if not updater.safe_file(source) or not updater.safe_file(target) or target.startswith('tests/') or target in ('build_manifest.py', 'install.sh'):
                continue
            data = git('show', commit + ':' + source)
            files.append({'source': source, 'target': target, 'size': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
        if files:
            manifest['components'][name] = {'files': files}
    updater.validate(manifest)
    return manifest

if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--repo', required=True)
    p.add_argument('--commit', required=True)
    p.add_argument('--version', required=True)
    p.add_argument('--output', required=True)
    args = p.parse_args()
    Path(args.output).write_text(json.dumps(build(args.repo, args.commit, args.version), indent=2) + '\n')
