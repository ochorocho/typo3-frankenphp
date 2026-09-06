#!/bin/bash
# perf-summarise.sh <label> [urlfile]
# Re-derive data/<label>/summary.tsv from raw.tsv and refresh the row in data/index.tsv.
# Safe to re-run; it never re-measures.
set -u
export LC_ALL=C
cd "$(dirname "$(readlink -f "$0")")" || exit 1
label=${1:?usage: perf-summarise.sh <label> [urlfile]}
urlfile=${2:-perf-urls.tsv}
[ -s "data/$label/raw.tsv" ] || { echo "missing data/$label/raw.tsv" >&2; exit 66; }
exec python3 perfdata.py summarise "$label" "$urlfile"
