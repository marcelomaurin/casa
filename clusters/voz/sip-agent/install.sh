#!/usr/bin/env bash
set -euo pipefail
source_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
chatgpt_root="${1:-${CHATGPT_ROOT:-}}"
if [[ "$(id -u)" != 0 ]]; then
  echo 'Execute como root para instalar o servico e os arquivos de configuracao.' >&2
  exit 2
fi
bash "$source_dir/build.sh" "$chatgpt_root"
bridge="${SIP_BRIDGE_LIBRARY:-$chatgpt_root/native/sip/build/libchatgpt_sip_bridge.so}"
if [[ ! -f "$bridge" ]]; then
  echo 'Compile a ponte PJSIP conforme native/sip/README.md do CHATGPT.' >&2
  exit 2
fi
if ! getent passwd casa-sip >/dev/null; then
  useradd --system --user-group --no-create-home --shell /usr/sbin/nologin casa-sip
fi
install -d -m 0755 /opt/casa/servicos/sip-agent /etc/casa
install -m 0755 "$source_dir/build/casa-sip-agent" /opt/casa/servicos/sip-agent/casa-sip-agent.new
mv -f /opt/casa/servicos/sip-agent/casa-sip-agent.new /opt/casa/servicos/sip-agent/casa-sip-agent
install -m 0755 "$bridge" /opt/casa/servicos/sip-agent/libchatgpt_sip_bridge.so.new
mv -f /opt/casa/servicos/sip-agent/libchatgpt_sip_bridge.so.new /opt/casa/servicos/sip-agent/libchatgpt_sip_bridge.so
install -m 0644 "$source_dir/casa-sip-agent.service" /etc/systemd/system/casa-sip-agent.service
if [[ ! -e /etc/casa/sip-agent.env ]]; then
  install -o root -g casa-sip -m 0640 "$source_dir/sip-agent.env.exemplo" /etc/casa/sip-agent.env
fi
if [[ ! -e /etc/casa/sip-users.ini ]]; then
  install -o root -g casa-sip -m 0640 "$source_dir/sip-users.ini.exemplo" /etc/casa/sip-users.ini
fi
systemctl daemon-reload
echo 'Instalado. Configure as senhas SIP e o token da IA em /etc/casa/.'
echo 'Depois: systemctl enable --now casa-sip-agent'
