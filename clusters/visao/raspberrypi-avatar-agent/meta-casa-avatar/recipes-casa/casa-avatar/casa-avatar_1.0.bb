SUMMARY = "CASA/JARVIS Raspberry Pi avatar agent"
LICENSE = "MIT"
LIC_FILES_CHKSUM = "file://${COMMON_LICENSE_DIR}/MIT;md5=0835ade698e0bcf8506ecda2f7b4f302"

SRC_URI = " \
    file://casa_avatar.py \
    file://kinect_snapshot.c \
    file://casa-avatar.service \
    file://casa-avatar.env \
    file://casa-avatar-diagnostics.sh \
"

S = "${WORKDIR}"

inherit systemd

DEPENDS = "libfreenect libusb1"
RDEPENDS:${PN} = "python3-core python3-requests python3-pillow alsa-utils espeak libfreenect"

SYSTEMD_SERVICE:${PN} = "casa-avatar.service"
SYSTEMD_AUTO_ENABLE:${PN} = "enable"

do_compile() {
    ${CC} ${CFLAGS} -o kinect-snapshot kinect_snapshot.c ${LDFLAGS} \
        -lfreenect_sync -lfreenect -lusb-1.0 -lpthread
}

do_install() {
    install -d ${D}/opt/casa-avatar
    install -m 0755 ${WORKDIR}/casa_avatar.py ${D}/opt/casa-avatar/casa_avatar.py

    install -d ${D}${bindir}
    install -m 0755 ${WORKDIR}/kinect-snapshot ${D}${bindir}/kinect-snapshot
    install -m 0755 ${WORKDIR}/casa-avatar-diagnostics.sh ${D}${bindir}/casa-avatar-diagnostics

    install -d ${D}${sysconfdir}
    install -m 0640 ${WORKDIR}/casa-avatar.env ${D}${sysconfdir}/casa-avatar.env

    install -d ${D}${systemd_system_unitdir}
    install -m 0644 ${WORKDIR}/casa-avatar.service ${D}${systemd_system_unitdir}/casa-avatar.service
}

FILES:${PN} += "/opt/casa-avatar ${sysconfdir}/casa-avatar.env"
