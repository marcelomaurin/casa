#!/usr/bin/env bash
set -euo pipefail
source_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
chatgpt_root="${1:-${CHATGPT_ROOT:-}}"
if [[ -z "$chatgpt_root" || ! -f "$chatgpt_root/pacote/AI Agent/aisipvoiceagent.pas" ]]; then
  echo 'Use: build.sh /caminho/CHATGPT (com o agente SIP de voz)' >&2
  exit 2
fi
mkdir -p "$source_dir/build/units"
compiler="${FPC:-fpc}"
options=()
if [[ -n "${FPC_UNITS:-}" ]]; then
  options+=(-n "-Fu$FPC_UNITS/*")
fi
"$compiler" "${options[@]}" -O2 -gl \
  "-Fu$chatgpt_root/pacote/AI" \
  "-Fu$chatgpt_root/pacote/AI Agent" \
  "-Fu$chatgpt_root/pacote/AI Communication/AISIP" \
  "-FU$source_dir/build/units" "-FE$source_dir/build" \
  -ocasa-sip-agent "$source_dir/casa_sip_agent.lpr"
echo "Gerado: $source_dir/build/casa-sip-agent"
