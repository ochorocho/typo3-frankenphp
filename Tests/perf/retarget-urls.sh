#!/bin/bash
#
# retarget-urls.sh <old-base-url> <new-base-url>
#
# The URL files carry absolute URLs of the instance they were written for. Point them at
# yours before the first measurement:
#
#   bash Tests/perf/retarget-urls.sh https://localhost:8885 https://typo3.example.test
#
# Check the result with "grep -o 'https://[^/]*' Tests/perf/*.tsv | sort -u" and curl one URL
# before you trust a measurement - a 404 measures nothing and the harness records it happily.
set -u
export LC_ALL=C
cd "$(dirname "$(readlink -f "$0")")" || exit 1
old=${1:?usage: retarget-urls.sh <old-base-url> <new-base-url>}
new=${2:?usage: retarget-urls.sh <old-base-url> <new-base-url>}
for f in perf-urls.tsv perf-leak-urls.tsv; do
    sed -i '' "s|${old}|${new}|g" "$f"
    printf '%s -> %s\n' "$f" "$(grep -o 'https://[^/]*' "$f" | sort -u | tr '\n' ' ')"
done
