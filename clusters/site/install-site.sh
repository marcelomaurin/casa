#!/bin/bash
set -e

echo "=== Instalando CASA Cluster Site Portal (/opt/casa/site) ==="
mkdir -p /opt/casa/site
cp -r index.html server.py /opt/casa/site/
chmod +x /opt/casa/site/server.py

if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
    echo "Detectado systemd. Instalando servico casa-cluster-site.service..."
    cp casa-cluster-site.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable casa-cluster-site
    systemctl restart casa-cluster-site
    systemctl status casa-cluster-site --no-pager || true
else
    echo "Detectado SysVinit. Instalando /etc/init.d/casa-cluster-site..."
    cp casa-cluster-site /etc/init.d/casa-cluster-site
    chmod +x /etc/init.d/casa-cluster-site
    update-rc.d casa-cluster-site defaults 2>/dev/null || true
    /etc/init.d/casa-cluster-site restart
    /etc/init.d/casa-cluster-site status || true
fi

echo "=== Cluster Site ativo em http://$(hostname -I | awk '{print $1}'):8080/ ==="
