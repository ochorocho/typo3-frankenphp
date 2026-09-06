#!/bin/bash
#
# perf-warm-images.sh [urlfile]
#
# Materialise the processed image variants of every measured page: TYPO3 writes the
# sys_file_processedfile row on render but creates the file when the image is requested.
# A page whose variants are not on disk pays for processing on its first render, which is
# a state production is almost never in. Warming once takes that out of the cold numbers.
set -u
export LC_ALL=C
cd "$(dirname "$(readlink -f "$0")")" || exit 1
ROOT=${PERF_ROOT:-$(cd ../../Build && pwd)}
urlfile=${1:-perf-urls.tsv}

pages=$(awk -F'\t' '!/^#/ && NF>=6 {print $6}' "$urlfile")
host=$(printf '%s\n' "$pages" | head -1 | sed -E 's#(https://[^/]+).*#\1#')

images=$(for page in $pages; do curl -sk --max-time 300 "$page"; done \
    | grep -oE '(src|srcset)="[^"]+"' \
    | sed -E 's/^(src|srcset)="//; s/"$//' \
    | tr ',' '\n' \
    | awk '{print $1}' \
    | grep -E '/(fileadmin|typo3temp/assets)/' \
    | sort -u)

count=$(printf '%s\n' "$images" | grep -c . || true)
echo "warmup: $count image urls from $(printf '%s\n' "$pages" | grep -c .) pages"
printf '%s\n' "$images" | grep . | sed "s#^/#$host/#" \
    | xargs -P 8 -n 1 curl -sk -o /dev/null --max-time 300 2>/dev/null
echo "warmup: processed files on disk: $(find "$ROOT/public/fileadmin/_processed_" -type f 2>/dev/null | wc -l | tr -d ' ')"
