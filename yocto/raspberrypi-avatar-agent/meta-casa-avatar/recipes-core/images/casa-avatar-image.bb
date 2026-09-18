SUMMARY = "CASA/JARVIS multimodal avatar image for Raspberry Pi"
LICENSE = "MIT"

inherit core-image

IMAGE_FEATURES += "ssh-server-openssh"

IMAGE_INSTALL:append = " \
    packagegroup-core-boot \
    kernel-modules \
    ca-certificates \
    curl \
    openssh \
    alsa-utils \
    espeak-ng \
    python3-core \
    python3-requests \
    python3-pygame \
    python3-pillow \
    libfreenect \
    casa-avatar \
"

IMAGE_FSTYPES += "wic.bz2"
