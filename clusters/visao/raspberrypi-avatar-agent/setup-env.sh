#!/bin/sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
WORKDIR="$ROOT/workspace"
BRANCH="${YOCTO_BRANCH:-scarthgap}"

mkdir -p "$WORKDIR"
cd "$WORKDIR"

clone_layer() {
    url="$1"
    dir="$2"
    if [ ! -d "$dir/.git" ]; then
        git clone -b "$BRANCH" "$url" "$dir"
    else
        git -C "$dir" fetch origin "$BRANCH"
    fi
}

clone_layer https://git.yoctoproject.org/poky poky
clone_layer https://git.openembedded.org/meta-openembedded meta-openembedded
clone_layer https://github.com/agherzan/meta-raspberrypi.git meta-raspberrypi

cat > "$ROOT/build-env" <<EOF
#!/bin/sh
set -e
ROOT="$ROOT"
WORKDIR="$WORKDIR"
cd "$WORKDIR/poky"
. ./oe-init-build-env "$ROOT/build"
bitbake-layers add-layer "$WORKDIR/meta-openembedded/meta-oe" 2>/dev/null || true
bitbake-layers add-layer "$WORKDIR/meta-openembedded/meta-python" 2>/dev/null || true
bitbake-layers add-layer "$WORKDIR/meta-openembedded/meta-networking" 2>/dev/null || true
bitbake-layers add-layer "$WORKDIR/meta-raspberrypi" 2>/dev/null || true
bitbake-layers add-layer "$ROOT/meta-casa-avatar" 2>/dev/null || true
cp "$ROOT/conf/local.conf.sample" "$ROOT/build/conf/local.conf"
echo "Ambiente pronto. Execute: bitbake casa-avatar-image"
EOF
chmod +x "$ROOT/build-env"

echo "Criado $ROOT/build-env"
echo "Execute: source $ROOT/build-env"
