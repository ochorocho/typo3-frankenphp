# Page render harness (ported from the TAE performance harness)

Measures what a **page render** costs on the `Build/` sandbox, one page at a time: the cold
call after the page cache was dropped, then two warm calls. Stores every measurement under a
label, diffs two labels per URL, and checks that a shared worker never serves one request's
page or nonce to another. The k6 scenarios in `Tests/load` answer "what does the server do
under N users"; this harness answers "what does one page cost, cold and warm".

Origin: `~/Development/tae-perf-harness` (b13, 2026-09-03), built for a ddev + Redis + three-site
TAE install. Ported here for FrankenPHP: caches are flushed through TYPO3's `CacheManager`
(any backend), the gawk-only pieces are Python (`perfdata.py`), the leak check is per page
and per request, and `meta.txt` records whether the worker or per-request PHP served the run.

## The three commands

```bash
bash Tests/perf/perf.sh <label> [repeats] [mode]   # measure, store under Tests/perf/data/<label>/
bash Tests/perf/perfcmp.sh <label-a> <label-b>     # diff two labels per URL
bash Tests/perf/perf-leak.sh                       # no request serves another one's page or nonce
```

`Tests/perf/RESULTS.md` is regenerated from `data/` after every run (`perfstats.sh`), both are
gitignored: they describe one machine. Quote totals or a named page that moved by far more than
its own noise, never the per-page median.

```bash
PERF_NOTE="worker, post-response reset" bash Tests/perf/perf.sh worker 3 page
PERF_NOTE="control, identical code"     bash Tests/perf/perf.sh worker-recheck 3 page
bash Tests/perf/perfcmp.sh worker worker-recheck   # the machine's own noise
bash Tests/perf/perf-leak.sh --learn               # once, records perf-fingerprints.tsv
bash Tests/perf/perf-leak.sh                       # every line must say ok
```

## Worker versus per-request PHP

The comparison the harness was built for. Both run through FrankenPHP; only the entry script
differs. In `Build/Caddyfile`, at the end of the `route` block, change
`rewrite * /worker.php` to `rewrite * /index.php`, restart, measure under a second label,
then revert (or `vendor/bin/typo3 frankenphp:init --force`).

## Modes

| mode | what is dropped before each sequence | what it represents |
|------|--------------------------------------|--------------------|
| `page` (default) | `pages` and `hash` caches via `perf-flush.php` | one page fell out of the cache on a warm server |
| `group` | `typo3 cache:flush --group pages` | post-deployment: the TypoScript cache is gone too |
| `none` | nothing | the fully cached path only |

## Files

- `perf-urls.tsv` – the measured pages (id, site, lang, category, kind, url). Keyed by ID.
- `perf-leak-urls.tsv`, `perf-fingerprints.tsv` – the leak check set and its learned markers.
- `retarget-urls.sh <old-base> <new-base>` – point both URL files at another instance.
- `perf-flush.php <root> <caches>`, `probe-caches.php <root>` – CacheManager based helpers.
- `perfdata.py` – median / summarise / compare; `perfstats.py` – RESULTS.md.
- `perf-warm-images.sh` – materialises processed images so cold numbers exclude image processing.

Environment: `PERF_ROOT` (default `Build/`), `PERF_URLS`, `PERF_NOTE`, `PERF_WARM_IMAGES=0`.
Requires `php` and `python3` on the PATH; `LC_ALL=C` is set in every script because a German
locale writes decimal commas into the data files.
