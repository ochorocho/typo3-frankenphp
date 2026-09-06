#!/bin/bash
#
# perfstats.sh [baseline-label]
#
# Regenerate RESULTS.md from everything in data/. Never hand-edited - it is derived, so
# it cannot drift from the measurements. perf.sh calls it after every run.
set -u
export LC_ALL=C
cd "$(dirname "$(readlink -f "$0")")" || exit 1
exec python3 perfstats.py "${1:-${PERF_BASELINE:-baseline}}"
