"""Data helpers for the perf harness: median, summarise, compare.

The original harness did this in gawk (arrays of arrays, asort); macOS ships BSD awk, so
the same rules live here. Formats are unchanged so RESULTS.md and perfcmp output read the
same as in the TAE harness this was ported from.
"""
import csv
import statistics
import sys
from pathlib import Path


def read_tsv(path):
    with open(path, newline="") as handle:
        return list(csv.DictReader(handle, delimiter="\t"))


def read_urls(urlfile):
    rows = []
    with open(urlfile) as handle:
        for line in handle:
            line = line.rstrip("\n")
            if not line or line.startswith("#"):
                continue
            fields = line.split("\t")
            if len(fields) < 6:
                continue
            rows.append(dict(zip(["id", "site", "lang", "category", "kind", "url"], fields)))
    return rows


def median_ms(values):
    """Median with one decimal: cached pages are 3-5 ms, integer milliseconds hide the change."""
    values = sorted(float(v) for v in values)
    n = len(values)
    if n == 0:
        return None
    mid = values[n // 2] if n % 2 else (values[n // 2 - 1] + values[n // 2]) / 2
    return round(mid, 1)


def cmd_median(raw, ident):
    rows = [r for r in read_tsv(raw) if r["id"] == ident]
    out = []
    for call in ("1", "2", "3"):
        med = median_ms(r["ttfb_ms"] for r in rows if r["call"] == call)
        out.append("     -" if med is None else f"{med:7.1f}")
    print(" / ".join(out))


def summarise(label, urlfile, perf_dir):
    out = perf_dir / "data" / label
    raw = read_tsv(out / "raw.tsv")
    urls = read_urls(urlfile)
    header = ["id", "site", "lang", "code", "size", "c1_ms", "c2_ms", "c3_ms", "c1_min", "c1_max", "category", "kind", "url"]
    lines = []
    totals = [0, 0, 0]
    for u in urls:
        mine = [r for r in raw if r["id"] == u["id"]]
        if not mine:
            continue
        meds = [median_ms(r["ttfb_ms"] for r in mine if r["call"] == c) for c in ("1", "2", "3")]
        cold = [float(r["ttfb_ms"]) for r in mine if r["call"] == "1"]
        last = mine[-1]
        row = [u["id"], u["site"], u["lang"], last["code"], last["size"], *[str(m) for m in meds],
               f"{min(cold):.1f}", f"{max(cold):.1f}", u["category"], u["kind"], u["url"]]
        lines.append(row)
        for i, m in enumerate(meds):
            totals[i] += m or 0
    with open(out / "summary.tsv", "w", newline="") as handle:
        writer = csv.writer(handle, delimiter="\t", lineterminator="\n")
        writer.writerow(header)
        writer.writerows(lines)
    print(f"{'ID':<14} {'SITE':<6} {'LNG':<3} {'CODE':<4} {'C1':>7} {'C2':>7} {'C3':>7}   {'C1-MIN-MAX':<15}  {'CATEGORY':<18} KIND")
    for r in lines:
        print(f"{r[0]:<14} {r[1]:<6} {r[2]:<3} {r[3]:<4} {float(r[5]):7.1f} {float(r[6]):7.1f} {float(r[7]):7.1f}   {r[8] + '-' + r[9]:<15}  {r[10]:<18} {r[11]}")
    print(f"{'TOTAL':<14} {'':<6} {'':<3} {'':<4} {totals[0]:7.1f} {totals[1]:7.1f} {totals[2]:7.1f}   ({len(lines)} urls)")

    meta = {}
    for line in (out / "meta.txt").read_text().splitlines():
        key, _, value = line.partition(" ")
        meta[key] = value.strip()
    index = perf_dir / "data" / "index.tsv"
    rows = []
    if index.exists():
        rows = [r for r in read_tsv(index) if r["label"] != label]
    rows.append({"label": label, "date": meta.get("date", ""), "mode": meta.get("mode", ""),
                 "reps": meta.get("repeats", ""), "commit": meta.get("commit", ""), "dirty": meta.get("dirty", ""),
                 "warmimages": meta.get("warmimages", ""), "sum_c1_ms": f"{totals[0]:.1f}", "sum_c2_ms": f"{totals[1]:.1f}",
                 "sum_c3_ms": f"{totals[2]:.1f}", "note": meta.get("note", "")})
    with open(index, "w", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(rows[0].keys()), delimiter="\t", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def compare(a, b, perf_dir):
    ra = {r["id"]: r for r in read_tsv(perf_dir / "data" / a / "summary.tsv")}
    rb = {r["id"]: r for r in read_tsv(perf_dir / "data" / b / "summary.tsv")}
    for label in (a, b):
        meta = (perf_dir / "data" / label / "meta.txt").read_text().splitlines()
        picked = " ".join(l for l in meta if l.split(" ")[0] in ("date", "mode", "commit", "note"))
        print(f"{label:<10} {picked}")
    print()
    for ident in rb:
        if ident not in ra:
            print(f"only in {b}: {ident}")
    for ident in ra:
        if ident not in rb:
            print(f"only in {a}: {ident}")
    lines = []
    s1a = s1b = s2a = s2b = 0
    for ident, x in ra.items():
        y = rb.get(ident)
        if not y:
            continue
        c1a, c1b, c2a, c2b = float(x["c1_ms"]), float(y["c1_ms"]), float(x["c2_ms"]), float(y["c2_ms"])
        d1, d2 = c1b - c1a, c2b - c2a
        p1 = d1 * 100.0 / c1a if c1a else 0
        warn = ""
        sza, szb = int(x["size"]), int(y["size"])
        drift = abs(szb - sza) * 100.0 / sza if sza else 0
        if drift > 1:
            warn += f" SIZE {sza}->{szb}"
        if x["code"] != y["code"]:
            warn += f" CODE {x['code']}->{y['code']}"
        lines.append((abs(d1), f"{ident:<14} {c1a:7.1f} {c1b:7.1f} {d1:+7.1f} {p1:+6.1f}%   {c2a:6.1f} {c2b:6.1f} {d2:+6.1f}   {x['kind']}{warn}"))
        s1a += c1a; s1b += c1b; s2a += c2a; s2b += c2b
    print(f"{'ID':<14} {a + '-c1':>7} {b + '-c1':>7} {'diff':>7} {'pct':>7}   {a + '-c2':>6} {b + '-c2':>6} {'diff':>6}   KIND")
    for _, line in sorted(lines, key=lambda t: -t[0]):
        print(line)
    pct = (s1b - s1a) * 100.0 / s1a if s1a else 0
    print(f"{'TOTAL':<14} {s1a:7.1f} {s1b:7.1f} {s1b - s1a:+7.1f} {pct:+6.1f}%   {s2a:6.1f} {s2b:6.1f} {s2b - s2a:+6.1f}   TOTAL")


if __name__ == "__main__":
    perf_dir = Path(__file__).resolve().parent
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "median" and len(sys.argv) == 4:
        cmd_median(sys.argv[2], sys.argv[3])
    elif cmd == "summarise" and len(sys.argv) == 4:
        summarise(sys.argv[2], sys.argv[3], perf_dir)
    elif cmd == "compare" and len(sys.argv) == 4:
        compare(sys.argv[2], sys.argv[3], perf_dir)
    else:
        sys.exit("usage: perfdata.py median <raw.tsv> <id> | summarise <label> <urlfile> | compare <a> <b>")
