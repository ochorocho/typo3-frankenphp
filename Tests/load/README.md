# Load tests (Grafana k6)

HTTP-level load tests for the `ochorocho/frankenphp` extension. The
suite measures throughput, tail latency, and (most importantly) the
**stability of FrankenPHP worker mode under sustained traffic** — the
soak scenarios are the regression-detection path for
`Classes/Worker/WorkerStateResetter.php`.

For correctness checks the Playwright suite (`Tests/e2e/`) is the right
tool. For *performance* and *stability over time*, this is.

---

## Prerequisites

1. **k6 v0.50+** on `$PATH`.

   ```bash
   # macOS
   brew install k6

   # Debian/Ubuntu
   sudo gpg -k && sudo gpg --no-default-keyring \
     --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
     --keyserver hkp://keyserver.ubuntu.com:80 \
     --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
   echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
     | sudo tee /etc/apt/sources.list.d/k6.list
   sudo apt-get update && sudo apt-get install -y k6

   # Docker (no install)
   docker run --rm --network=host -v $PWD:/scripts grafana/k6 run /scripts/scenarios/frontend-smoke.js
   ```

2. **FrankenPHP serving the dev sandbox**:

   ```bash
   cd ../../Build && frankenphp run -c Caddyfile -e .env
   ```

   Default endpoints: `http://localhost:8888`, `https://localhost:8885`.
   The scripts default to the HTTPS one with self-signed cert verification
   disabled (`insecureSkipTLSVerify: true`).

3. **TYPO3 sandbox set up** (admin user `admin` / `Password.1`). If
   missing, run `scripts/setup-typo3.sh` from the repo root.

---

## Quick start

From `Tests/`:

```bash
npm run load:smoke           # 30 s sanity — start here
npm run load:frontend        # 2 min steady-state anonymous traffic
npm run load:stress          # 5 min ramp 0→100 VUs — finds saturation
npm run load:spike           # 90 s 0→200→0 — burst recovery
npm run load:soak            # 10 min sustained — memory-leak detection
npm run load:backend         # 2 min authenticated nav
npm run load:backend:soak    # 10 min — WorkerStateResetter regression detector
MAX_REQUESTS=500 frankenphp run -c Caddyfile --envfile .env   # in Build/, the scenario expects 500
npm run load:backend:recycle # ~2 min — MAX_REQUESTS boundary
npm run load:backend:multiuser # 3 min — admin + editor interleaved; catches cross-user leaks
npm run load:install-tool    # 2 min — `?__typo3_install` failsafe path
npm run load:mixed           # 5 min — 80% frontend + 20% backend
```

Direct invocation (any scenario file):

```bash
k6 run load/scenarios/frontend-load.js
BASE_URL=https://typo3.example.com k6 run load/scenarios/frontend-load.js
```

Override admin credentials via `TYPO3_SETUP_ADMIN_USERNAME` /
`TYPO3_SETUP_ADMIN_PASSWORD` (same names as `scripts/setup-typo3.sh`
uses, so one env export covers both).

---

## Scenario index

| Script                  | Shape  | VUs  | Duration | What it measures                                                 |
|-------------------------|--------|------|----------|------------------------------------------------------------------|
| `frontend-smoke.js`     | smoke  | 1    | 30 s     | All Camino routes respond cleanly                                |
| `frontend-load.js`      | load   | 20   | 2 min    | Steady-state anonymous throughput                                |
| `frontend-stress.js`    | stress | →100 | ~5 min   | Worker-pool saturation inflection point                          |
| `frontend-spike.js`     | spike  | →200 | 90 s     | Recovery from sudden burst                                       |
| `frontend-soak.js`      | soak   | 10   | 10 min   | Memory-leak / opcache-bloat detection                            |
| `backend-smoke.js`      | smoke  | 1    | 30 s     | Login + Web>Layout sanity                                        |
| `backend-load.js`       | load   | 5    | 2 min    | Auth + nav throughput (lower VUs — session table serialises)     |
| `backend-soak.js`       | soak   | 5    | 10 min   | WorkerStateResetter stability over thousands of authed requests |
| `backend-recycle.js`    | recycle| 1    | ~2 min   | MAX_REQUESTS + 50 sequential authed GETs across a worker recycle |
| `backend-multiuser.js`  | load   | 2+2  | 3 min    | Admin and restricted editor interleaved on the same workers; role markers must never leak |
| `install-tool-smoke.js` | smoke  | 1    | 30 s     | `?__typo3_install` routes to failsafe                            |
| `install-tool-load.js`  | load   | 10   | 2 min    | Per-request `/index.php` path under load                         |
| `mixed-workload.js`     | load   | 8+2  | 5 min    | Realistic 80/20 mix in one run                                   |

---

## Interpreting the output

k6 prints a text summary at the end of each run. The numbers that
matter:

- **`http_req_duration`** — `avg`, `p(50)`, `p(90)`, `p(95)`, `p(99)`,
  `max`. The gates live in `lib/thresholds.js` and are deliberately loose
  (p95 < 1 s frontend, < 2 s backend, < 3 s under saturation) because
  they also run on noisy CI runners. On the dev sandbox (2 workers,
  SQLite, Apple Silicon) a healthy setup is an order of magnitude below
  the gates: frontend p95 ≈ 45 ms, backend p95 ≈ 40 ms. Anything near a
  gate is a real problem, not marginal.
- **`http_req_failed.rate`** — fraction of requests that returned a
  network error or 5xx. Should be `< 0.01` for smoke/load/soak.
- **`checks.rate`** — fraction of `check()` assertions that passed.
  Drops below 1.0 mean responses are wrong, not just slow.

### What "good" looks like

The left columns are the gates from `lib/thresholds.js` (what makes k6 exit
non-zero); the right column is what the dev sandbox actually measures, so a
run that passes the gate but sits far above the sandbox figure still deserves
a look.

| Scenario        | p95 gate          | failed-rate gate | checks gate | dev sandbox (2 workers) |
|-----------------|-------------------|------------------|-------------|-------------------------|
| frontend-smoke  | < 1 s             | < 0.01           | > 0.99      | p95 ≈ 20 ms             |
| frontend-load   | < 1 s             | < 0.01           | > 0.99      | p95 ≈ 45 ms             |
| frontend-soak   | < 1 s, flat       | < 0.01           | > 0.99      | see Documentation/WorkerMode.md |
| frontend-stress | < 3 s             | < 0.10           | > 0.90      | saturates by design     |
| frontend-spike  | < 3 s             | < 0.10           | > 0.90      | must recover in cool-down |
| backend-smoke   | < 2 s             | < 0.02           | > 0.98      | p95 ≈ 40 ms             |
| backend-load    | < 2 s             | < 0.02           | > 0.98      | p95 ≈ 40 ms             |
| backend-soak    | < 2 s, flat       | < 0.02           | > 0.98      | see Documentation/WorkerMode.md |
| backend-recycle | (none)            | == 0             | > 0.99      | 3-4 misses per boundary |
| backend-multiuser | < 2 s           | < 0.02           | > 0.98      | any check failure is a cross-user leak |
| install-tool    | < 3 s             | < 0.01           | > 0.99      | per-request PHP, slower by design |
| mixed-workload  | fe < 0.5 s, be < 1 s (untagged) | < 0.02 | > 0.98 | no warmup: first request counts |

CI runs only the three smoke scenarios (`.github/workflows/ci.yml`, "Load
testing" job); everything else is run by hand.

### Regression patterns (what to look for)

- **Soak: latency drifts upward** across the 10 min window → singleton
  state in the worker isn't resetting cleanly, memory pressure builds.
  Re-run with `--out json=soak.ndjson` and bucket by minute to confirm.
- **Soak: check rate slides under 1.0** → wrong content rendered. The
  `backend-soak.js` script cycles the Web>Layout page tree on
  purpose — this is exactly what catches the ContentFetcher /
  BackendLayoutView cache-leak class of bugs.
- **Spike: error rate stays > 0 after the cool-down** → worker pool
  didn't recover, some worker died and left a stuck request.
- **Backend smoke fails on iteration 1, succeeds on 2+** → login
  worked but the very first authenticated request crashed (typical
  WorkerStateResetter regression — see the file's existing comments
  for what fields it resets).

## Output formats

```bash
# JSON ndjson — one event per line, post-processable with jq / Datadog / etc.
k6 run --out json=run.ndjson load/scenarios/frontend-soak.js

# CSV
k6 run --out csv=run.csv load/scenarios/frontend-load.js

# Grafana Cloud k6 (requires K6_CLOUD_TOKEN env var)
k6 cloud load/scenarios/frontend-load.js

# Prometheus remote-write (experimental, k6 v0.51+)
K6_PROMETHEUS_RW_SERVER_URL=http://localhost:9090/api/v1/write \
  k6 run --out experimental-prometheus-rw load/scenarios/frontend-load.js
```

Full output options: https://grafana.com/docs/k6/latest/results-output/

---

## Comparing worker-mode vs per-request `/index.php`

To quantify the worker-mode win, run the same scenario twice with the
backend swapped:

1. **Worker mode (default)** — what we ship.
   ```bash
   npm run load:frontend     # measure A
   ```

2. **Per-request mode** — temporarily edit `Build/Caddyfile`:
   ```caddyfile
   php_server {
       index /index.php          # was /worker.php
       try_files {path} /index.php
   }
   ```
   Restart frankenphp, then:
   ```bash
   npm run load:frontend     # measure B
   ```

Compare `http_reqs` (req/s) and `http_req_duration.p(95)` between the
two runs. On a developer laptop, worker mode typically delivers 3–5×
the request rate with materially lower tail latency.

Revert the Caddyfile change (or `vendor/bin/typo3 frankenphp:init --force`)
when finished.

---

## Troubleshooting

- **TLS handshake errors** → scripts already set
  `insecureSkipTLSVerify: true`. If you're invoking k6 directly without
  that option (e.g. wrapper scripts), add `--insecure-skip-tls-verify`.
- **403 / "Install Tool session expired" surges in `backend-*`** →
  concurrent admin logins serialize at the SQLite be_sessions table.
  Lower the VU count or set `loginOncePerVU` (already used) so each VU
  authenticates exactly once.
- **`http_req_duration.p(95)` thresholds tripping in `frontend-load.js`**
  → likely either `FRANKENPHP_WORKER_COUNT` is too low in `Build/.env`,
  ImageMagick is hitting a path TYPO3 can't resolve, or the host is
  resource-constrained. Run `frontend-stress.js` to find the inflection
  point; raising the worker count past that has diminishing returns.
- **Soak scenarios show stable latency then a single slow request** →
  first suspect the MAX_REQUESTS recycle, not GC. With the dev profile
  (`MAX_REQUESTS=10000`, one worker per core) a 10 min soak crosses the boundary
  every ~1000 requests; the replacement worker's first request pays the
  boot cost (up to ~2 s on the backend, a few hundred ms on the
  frontend). A recycle shows up as one `max` outlier with p95 unchanged
  and `X-FrankenPHP-Discarded` dropping back to the post-boot value on
  the next sample. Sustained p95 drift across minutes is the leak
  signal; capture `--out json=soak.ndjson` and bucket per minute to tell
  the two apart.
- **Backend module checks pass although the module never rendered** →
  module routes need the per-session route token. A tokenless GET on
  `/typo3/module/...` is redirected to `/typo3/login`, k6 follows it,
  and a 200 login page satisfies `okStatus`. Use `moduleUrl()` from
  `lib/auth.js` (harvests the tokenised link from the module menu) and
  add `looksLikeModule` to the check set, as the backend scenarios do.
- **`BreadcrumbFactory: Page record array must contain uid` warnings in
  `var/log/typo3_*.log`** → Core's `BreadcrumbFactory::forPageArray()`
  logs this whenever a breadcrumb is built from a page array without a
  `uid`; it shows up with normal backend browser navigation as well and
  is unrelated to worker mode. Harmless noise; do not count it as a
  scenario failure.

---

## Files

```
load/
├── README.md                   this file
├── lib/
│   ├── config.js               BASE_URL, credentials, frontend route list
│   ├── auth.js                 TYPO3 login (CSRF token + cookie jar)
│   ├── thresholds.js           default / backend / saturation / install-tool thresholds
│   └── checks.js               okStatus, looksLikeCaminoPage, noPHPError, …
└── scenarios/
    ├── frontend-{smoke,load,stress,spike,soak}.js
    ├── backend-{smoke,load,soak}.js
    ├── install-tool-{smoke,load}.js
    └── mixed-workload.js
```

k6 documentation index: https://grafana.com/docs/k6/latest/
