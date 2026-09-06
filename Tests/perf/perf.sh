#!/bin/bash
#
# perf.sh <label> [repeats] [mode]
#
# Measure page render time for the URL set in perf-urls.tsv against the Build/ sandbox and
# store the result under data/<label>/. One label per change under test - that is the
# point: perfcmp.sh diffs two labels per URL, so the effect of a single change is readable
# instead of drowning in run-to-run noise.
#
#   repeats   how often the whole first/second/third-call sequence is repeated per URL,
#             default 3. The reported number is the MEDIAN over the repeats.
#   mode      what is flushed before each sequence:
#               page   (default) the `pages` and `hash` caches through TYPO3's CacheManager.
#                      TypoScript, Fluid, DI, l10n and the rootline cache stay warm. This is
#                      the casual uncached request on a warm server: one page fell out.
#               group  "typo3 cache:flush --group pages". Also drops the file based
#                      TypoScript cache - the post-deployment situation, not the casual one.
#               none   no flush at all, measures the fully cached path only.
#
# Environment:
#   PERF_NOTE="..."      free text stored in meta.txt and shown by perfcmp.sh
#   PERF_URLS=path       alternative URL file
#   PERF_ROOT=path       TYPO3 project root (default ../../Build relative to this script)
#   PERF_WARM_IMAGES=0   skip the image warmup (default 1, see perf-warm-images.sh)
#   PERF_FLUSH_URL=url   flush through the server (APCu backends), see perf-flush-web.php
#
# The three calls per sequence are: 1 = cache miss (full render), 2 and 3 = cache hits.
# Call 3 exists to show whether call 2 was really warm; if c2 and c3 differ a lot, some
# part of the page is still being built on every request.
#
# curl runs without cookies and without Accept-Encoding, so every request is cacheable.
# The metric is TTFB (time_starttransfer) - server think time; time_total is recorded too.
#
# Ported from the TAE performance harness (ddev + Redis + three sites) to this sandbox:
# FrankenPHP serves it, caches are flushed through the CacheManager, and the worker
# reports how it reset through the X-FrankenPHP-* headers, which meta.txt records.

set -u
export LC_ALL=C
cd "$(dirname "$(readlink -f "$0")")" || exit 1
PERF_DIR=$PWD
REPO=$(cd ../.. && pwd)
ROOT=$(cd "${PERF_ROOT:-$REPO/Build}" && pwd) || { echo "no TYPO3 root at ${PERF_ROOT:-$REPO/Build}" >&2; exit 66; }

label=${1:-}
repeats=${2:-3}
mode=${3:-page}
note=${PERF_NOTE:-}
urlfile=${PERF_URLS:-$PERF_DIR/perf-urls.tsv}
warmimages=${PERF_WARM_IMAGES:-1}

if [ -z "$label" ]; then
    echo "usage: bash Tests/perf/perf.sh <label> [repeats] [mode: page|group|none]" >&2
    exit 64
fi
case "$mode" in page|group|none) ;; *) echo "unknown mode: $mode" >&2; exit 64;; esac
[ -s "$urlfile" ] || { echo "missing url file: $urlfile" >&2; exit 66; }

# TYPO3's CLI needs the same context the server runs with (Build/.env).
export TYPO3_CONTEXT
TYPO3_CONTEXT=$(sed -n 's/^TYPO3_CONTEXT=//p' "$ROOT/.env" 2>/dev/null | head -1)
TYPO3_CONTEXT=${TYPO3_CONTEXT:-Development}
typo3() { (cd "$ROOT" && vendor/bin/typo3 "$@"); }

# The server must be up, and we record what serves the pages.
firsturl=$(awk -F'\t' '!/^#/ && NF>=6 {print $6; exit}' "$urlfile")
headers=$(curl -sk -o /dev/null -D - --max-time 30 "$firsturl") || { echo "no answer from $firsturl - is FrankenPHP running?" >&2; exit 69; }
printf '%s' "$headers" | grep -q "^HTTP/[0-9.]* 200" || { echo "$firsturl does not answer 200:"; printf '%s\n' "$headers" | head -1; exit 69; } >&2
server=$(printf '%s' "$headers" | awk 'tolower($1)=="server:" {sub(/\r$/,""); $1=""; sub(/^ /,""); print}')
if printf '%s' "$headers" | grep -qi '^x-frankenphp-reset-mode:'; then
    server="$server, worker mode (reset: $(printf '%s' "$headers" | awk 'tolower($1)=="x-frankenphp-reset-mode:" {sub(/\r$/,""); print $2}'))"
else
    server="$server, per-request PHP"
fi

out="$PERF_DIR/data/$label"
rm -rf "$out"; mkdir -p "$out"

# PERF_FLUSH_URL: flush through the server instead of a CLI process. Needed for APCu,
# whose shared memory segment is per process (see perf-flush-web.php).
flush_caches() {
    if [ -n "${PERF_FLUSH_URL:-}" ]; then
        curl -sk -o /dev/null --max-time 60 "${PERF_FLUSH_URL}$1"
    else
        php "$PERF_DIR/perf-flush.php" "$ROOT" "$1" >/dev/null 2>&1
    fi
}
flush() {
    case "$mode" in
        page)  flush_caches pages,hash ;;
        group) typo3 cache:flush --group pages >/dev/null 2>&1 ;;
        none)  : ;;
    esac
}

# --- meta ---------------------------------------------------------------------------
urlcount=$(awk -F'\t' '!/^#/ && NF>=6 {n++} END {print n+0}' "$urlfile")
{
    echo "label       $label"
    echo "date        $(date +%Y-%m-%dT%H:%M:%S%z)"
    echo "mode        $mode"
    echo "repeats     $repeats"
    echo "urls        $urlcount"
    echo "branch      $(git -C "$REPO" rev-parse --abbrev-ref HEAD 2>/dev/null)"
    echo "commit      $(git -C "$REPO" rev-parse --short HEAD 2>/dev/null)"
    echo "dirty       $(test -n "$(git -C "$REPO" status --porcelain 2>/dev/null)" && echo yes || echo no)"
    echo "php         $(php -r 'echo PHP_VERSION;' 2>/dev/null) (CLI); server: $server"
    echo "context     $TYPO3_CONTEXT"
    echo "warmimages  $warmimages"
    echo "flush       ${PERF_FLUSH_URL:-cli}"
    echo "note        $note"
} > "$out/meta.txt"
php "$PERF_DIR/probe-caches.php" "$ROOT" > "$out/caches.txt" 2>/dev/null

# --- warmup -------------------------------------------------------------------------
# The premise of the measurement: container, TCA, Fluid and TypoScript are warm, only the
# page cache is not. The discard passes also take the first-request cost of every worker
# thread out of the numbers.
echo "warmup: typo3 cache:warmup"
typo3 cache:warmup >/dev/null 2>&1
if [ "$warmimages" = 1 ]; then
    PERF_ROOT="$ROOT" bash "$PERF_DIR/perf-warm-images.sh" "$urlfile"
else
    echo "warmup: image warmup SKIPPED"
fi
echo "warmup: two discarded passes over $urlcount urls"
for _ in 1 2; do
    awk -F'\t' '!/^#/ && NF>=6 {print $6}' "$urlfile" | while read -r u; do
        curl -sk -o /dev/null --max-time 300 "$u"
    done
done

# --- measure ------------------------------------------------------------------------
raw="$out/raw.tsv"
printf 'id\trep\tcall\tcode\tttfb_ms\ttotal_ms\tsize\n' > "$raw"
printf '%-14s %-5s %s\n' ID CALLS 'MEDIAN TTFB ms (c1 cold / c2 / c3)'

while IFS=$'\t' read -r -u 3 id site lang category kind url; do
    case "$id" in \#*|"") continue;; esac
    [ -n "${url:-}" ] || continue
    for rep in $(seq 1 "$repeats"); do
        flush
        for call in 1 2 3; do
            curl -sk -o /dev/null --max-time 300 \
                -w '%{http_code} %{time_starttransfer} %{time_total} %{size_download}\n' \
                "$url" \
            | awk -v id="$id" -v rep="$rep" -v call="$call" \
                  '{printf "%s\t%s\t%s\t%s\t%.1f\t%.1f\t%s\n", id, rep, call, $1, $2*1000, $3*1000, $4}' \
                >> "$raw"
        done
    done
    printf '%-14s %-5s %s\n' "$id" "$((repeats*3))" "$(python3 "$PERF_DIR/perfdata.py" median "$raw" "$id")"
done 3< "$urlfile"

# --- summary ------------------------------------------------------------------------
bash "$PERF_DIR/perf-summarise.sh" "$label" "$urlfile"
bash "$PERF_DIR/perfstats.sh" >/dev/null 2>&1 || true
echo
echo "data:    $out"
echo "report:  $PERF_DIR/RESULTS.md"
