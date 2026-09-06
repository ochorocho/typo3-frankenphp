#!/bin/bash
#
# perfcmp.sh <label-a> <label-b>
#
# Compare two measurement labels per URL, keyed by ID. Label A is the before state.
# Sorted by the absolute change of the cold call, biggest first, because that is the
# number this whole exercise is about.
#
# The size column is not decoration: a page that got faster and smaller at the same time
# probably lost content. Any size change beyond 1 % is printed as a warning.
set -u
export LC_ALL=C
cd "$(dirname "$(readlink -f "$0")")" || exit 1
a=${1:?usage: perfcmp.sh <label-a> <label-b>}
b=${2:?usage: perfcmp.sh <label-a> <label-b>}
for l in "$a" "$b"; do
    [ -s "data/$l/summary.tsv" ] || { echo "missing data/$l/summary.tsv" >&2; exit 66; }
done
exec python3 perfdata.py compare "$a" "$b"
