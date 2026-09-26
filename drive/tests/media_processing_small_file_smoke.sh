#!/usr/bin/env bash
set -euo pipefail

for bin in ffmpeg ffprobe; do
  command -v "$bin" >/dev/null 2>&1 || {
    echo "FAIL: falta $bin para la prueba multimedia" >&2
    exit 1
  }
done

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

src="$tmp/video.mp4"
mp3="$tmp/video.mp3"

# Archivo deliberadamente pequeño: demuestra que no existe un mínimo artificial.
ffmpeg -hide_banner -loglevel error -y \
  -f lavfi -i "testsrc=size=160x90:rate=10:duration=6" \
  -f lavfi -i "sine=frequency=880:sample_rate=44100:duration=6" \
  -c:v mpeg4 -g 10 -c:a aac -shortest "$src"

duration="$(ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$src")"
awk -v d="$duration" 'BEGIN { if (!(d > 5.0 && d < 7.0)) exit 1 }'

ffmpeg -hide_banner -loglevel error -y -i "$src" -vn -map 0:a:0 -c:a libmp3lame -b:a 128k "$mp3"
test -s "$mp3"

# Misma estrategia del worker: 3 partes y stream copy.
for i in 1 2 3; do
  case "$i" in
    1) start="0.000"; length="4.000" ;;
    2) start="0.000"; length="6.000" ;;
    3) start="2.000"; length="4.000" ;;
  esac
  out="$tmp/video-parte${i}.mp4"
  ffmpeg -hide_banner -loglevel error -y \
    -ss "$start" -i "$src" -t "$length" -map 0 -c copy -avoid_negative_ts make_zero "$out"
  test -s "$out"
done

test -s "$src"
echo "MEDIA_PROCESSING_SMALL_FILE_OK"
