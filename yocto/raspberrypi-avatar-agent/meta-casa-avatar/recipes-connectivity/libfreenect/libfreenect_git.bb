SUMMARY = "OpenKinect libfreenect driver for Xbox 360 Kinect"
HOMEPAGE = "https://github.com/OpenKinect/libfreenect"
LICENSE = "Apache-2.0"
LIC_FILES_CHKSUM = "file://APACHE20;md5=89aea4e17d99a7cacdbeed46a0096b10"

SRC_URI = "git://github.com/OpenKinect/libfreenect.git;protocol=https;branch=master"
SRCREV = "${AUTOREV}"
PV = "0.7+git${SRCPV}"

S = "${WORKDIR}/git"

inherit cmake pkgconfig

DEPENDS = "libusb1"

EXTRA_OECMAKE += " \
    -DBUILD_EXAMPLES=OFF \
    -DBUILD_FAKENECT=OFF \
    -DBUILD_OPENNI2_DRIVER=OFF \
    -DBUILD_PYTHON=OFF \
    -DBUILD_PYTHON2=OFF \
    -DBUILD_PYTHON3=OFF \
"

FILES:${PN} += "${libdir}/libfreenect*.so.* ${sysconfdir}/udev/rules.d/*"
FILES:${PN}-dev += "${includedir}/libfreenect* ${libdir}/libfreenect*.so ${libdir}/cmake"
