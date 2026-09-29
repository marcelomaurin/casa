#include <stdio.h>
#include <stdint.h>
#include <libfreenect_sync.h>

static int save_rgb(const char *path, const uint8_t *rgb) {
    FILE *f = fopen(path, "wb");
    if (!f) return -1;
    fprintf(f, "P6\n640 480\n255\n");
    fwrite(rgb, 1, 640 * 480 * 3, f);
    fclose(f);
    return 0;
}

static int save_depth(const char *path, const uint16_t *depth) {
    FILE *f = fopen(path, "wb");
    if (!f) return -1;
    fprintf(f, "P5\n640 480\n255\n");
    for (int i = 0; i < 640 * 480; ++i) {
        uint16_t d = depth[i] & 0x07ff;
        uint8_t v = d == 2047 ? 0 : (uint8_t)(255 - ((d * 255) / 2046));
        fwrite(&v, 1, 1, f);
    }
    fclose(f);
    return 0;
}

int main(int argc, char **argv) {
    if (argc < 3) {
        fprintf(stderr, "uso: %s rgb.ppm depth.pgm\n", argv[0]);
        return 2;
    }

    void *rgb = NULL;
    void *depth = NULL;
    uint32_t ts = 0;

    if (freenect_sync_get_video(&rgb, &ts, 0, FREENECT_VIDEO_RGB) < 0) {
        fprintf(stderr, "falha ao capturar RGB do Kinect\n");
        return 3;
    }
    if (freenect_sync_get_depth(&depth, &ts, 0, FREENECT_DEPTH_11BIT) < 0) {
        freenect_sync_stop();
        fprintf(stderr, "falha ao capturar depth do Kinect\n");
        return 4;
    }

    int rc1 = save_rgb(argv[1], (const uint8_t *)rgb);
    int rc2 = save_depth(argv[2], (const uint16_t *)depth);
    freenect_sync_stop();
    return (rc1 || rc2) ? 5 : 0;
}
