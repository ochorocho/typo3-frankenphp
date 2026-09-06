#!/bin/bash
#
# perf-leak.sh            check that no request serves another request's page or nonce
# perf-leak.sh --learn    (re)record the per-page fingerprints
#
# Why this exists: the cheapest way to make TYPO3 faster on a shared worker is also the
# cheapest way to break it - state that survives from one request into the next. A status
# code diff and a timing diff both stay green for that, because the leaked page is a
# perfectly valid page. The TAE original checked three sites against each other; this
# sandbox has one site, so the check is per page and per request instead.
#
# What is checked per response, against perf-fingerprints.tsv:
#   - HTTP 200
#   - <link rel="canonical"> points at the URL that was requested
#   - <title> is this page's title, and no other page's title appears
#   - <html lang> is the expected language
#   - the CSP nonce in the header matches the nonce in the body, and no nonce is
#     seen twice across the whole run (RequestId must be fresh per request)
#   - repeats of the same page are byte-identical apart from the nonce
#
# Three passes: cold (pages and hash caches dropped), then two warm passes straight after.
# The pages are fired back to back so consecutive requests land on the same worker threads.
#
# Exits non-zero on the first violation found; every violation is printed.

set -u
export LC_ALL=C
cd "$(dirname "$(readlink -f "$0")")" || exit 1
PERF_DIR=$PWD
ROOT=${PERF_ROOT:-$(cd ../../Build && pwd)}
export TYPO3_CONTEXT
TYPO3_CONTEXT=$(sed -n 's/^TYPO3_CONTEXT=//p' "$ROOT/.env" 2>/dev/null | head -1)
TYPO3_CONTEXT=${TYPO3_CONTEXT:-Development}

urls=perf-leak-urls.tsv
fingerprints=perf-fingerprints.tsv

title_of() { printf '%s' "$1" | tr '\n' ' ' | grep -oE '<title>[^<]*</title>' | head -1 | sed -E 's/<\/?title>//g'; }
canonical_of() { printf '%s' "$1" | grep -oE '<link rel="canonical" href="[^"]*"' | head -1 | sed -E 's/.*href="([^"]*)".*/\1/'; }
body_nonces() { printf '%s' "$1" | grep -oE 'nonce="[^"]+"' | sed -E 's/nonce="//;s/"//' | sort -u; }

if [ "${1:-}" = --learn ]; then
    {
        printf '# id\tcanonical\ttitle\n'
        printf '# Recorded by perf-leak.sh --learn. The title identifies the page that actually\n'
        printf '# rendered; the canonical must match the requested URL.\n'
        while IFS=$'\t' read -r -u 3 id lang url; do
            case "$id" in \#*|"") continue;; esac
            body=$(curl -sk --max-time 300 "$url")
            title=$(title_of "$body")
            [ -n "$title" ] || { echo "no <title> found for $id ($url)" >&2; exit 70; }
            printf '%s\t%s\t%s\n' "$id" "$(canonical_of "$body")" "$title"
        done 3< "$urls"
    } > "$fingerprints" || exit 70
    cat "$fingerprints"
    exit 0
fi

[ -s "$fingerprints" ] || { echo "missing $fingerprints - run: bash Tests/perf/perf-leak.sh --learn" >&2; exit 66; }

# macOS ships bash 3.2 (no associative arrays): fingerprints are looked up in the file,
# nonces and body hashes are tracked in a scratch directory.
scratch=$(mktemp -d "${TMPDIR:-/tmp}/perf-leak.XXXXXX")
trap 'rm -rf "$scratch"' EXIT
fingerprint() { awk -F'\t' -v id="$1" -v col="$2" '!/^#/ && $1 == id {print $col; exit}' "$fingerprints"; }
other_titles() { awk -F'\t' -v id="$1" -v mine="$2" '!/^#/ && $1 != id && $3 != mine {print $1 "\t" $3}' "$fingerprints"; }

fail=0
check_pass() {
    local pass=$1
    while IFS=$'\t' read -r -u 3 id lang url; do
        case "$id" in \#*|"") continue;; esac
        [ -n "${url:-}" ] || continue
        local raw headers body code problems="" title mytitle header_nonce nonces hash owner
        raw=$(curl -sk -D - --max-time 300 "$url")
        headers=${raw%%$'\r\n\r\n'*}
        body=${raw#*$'\r\n\r\n'}
        code=$(printf '%s' "$headers" | head -1 | awk '{print $2}')
        [ "$code" = 200 ] || problems="$problems code=$code"
        [ "$(canonical_of "$body")" = "$(fingerprint "$id" 2)" ] || problems="$problems canonical"
        title=$(title_of "$body")
        mytitle=$(fingerprint "$id" 3)
        [ "$title" = "$mytitle" ] || problems="$problems title"
        while IFS=$'\t' read -r -u 4 other othertitle; do
            [ -n "$other" ] || continue
            printf '%s' "$body" | tr '\n' ' ' | grep -F "<title>$othertitle</title>" >/dev/null && problems="$problems foreign-title:$other"
        done 4< <(other_titles "$id" "$mytitle")
        printf '%s' "$body" | grep -E "<html[^>]*lang=\"$lang\"" >/dev/null || problems="$problems lang"
        header_nonce=$(printf '%s' "$headers" | grep -ioE "'nonce-[A-Za-z0-9_=-]+'" | head -1 | tr -d "'" | sed 's/^nonce-//')
        nonces=$(body_nonces "$body")
        if [ -n "$nonces" ]; then
            [ "$(printf '%s\n' "$nonces" | grep -c .)" = 1 ] || problems="$problems several-body-nonces"
            [ -z "$header_nonce" ] || [ "$header_nonce" = "$nonces" ] || problems="$problems header-body-nonce-mismatch"
            if [ -f "$scratch/nonce.$nonces" ]; then
                problems="$problems nonce-reused-from:$(cat "$scratch/nonce.$nonces")"
            else
                printf '%s/%s' "$pass" "$id" > "$scratch/nonce.$nonces"
            fi
        fi
        hash=$(printf '%s' "$body" | sed -E 's/nonce="[^"]*"/nonce="X"/g; s/nonce-[A-Za-z0-9_=-]+/nonce-X/g' | shasum | cut -c1-12)
        if [ -f "$scratch/body.$id" ] && [ "$(cat "$scratch/body.$id")" != "$hash" ]; then
            problems="$problems body-differs-from-earlier-pass"
        fi
        printf '%s' "$hash" > "$scratch/body.$id"
        if [ -n "$problems" ]; then
            fail=1
            printf 'LEAK  %-6s %-12s %-45s %s\n' "$pass" "$id" "$url" "$problems"
        else
            printf 'ok    %-6s %-12s %s\n' "$pass" "$id" "$url"
        fi
    done 3< "$urls"
}

if [ -n "${PERF_FLUSH_URL:-}" ]; then
    curl -sk -o /dev/null --max-time 60 "${PERF_FLUSH_URL}pages,hash,rootline"
else
    php "$PERF_DIR/perf-flush.php" "$ROOT" pages,hash,rootline >/dev/null 2>&1
fi
check_pass cold
check_pass warm
check_pass warm2

if [ "$fail" -eq 0 ]; then
    echo "--- LEAK CHECK OK"
else
    echo "--- LEAK CHECK FAILED"
fi
exit "$fail"
