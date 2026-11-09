#!/usr/bin/env python3
"""Turn one pytest JUnit XML report into public proof files.

Stdlib only, so any system's CI can run it with no install step.

    python tools/publish.py --system qtharness --junit junit.xml \
        --commit "$GITHUB_SHA" --run-id "$GITHUB_RUN_ID" --source github-actions

Writes, relative to the repo root:
    data/<system>/latest.json    full result, including every test name
    data/<system>/history.jsonl  one summary line per run (append-only)
    data/latest.json             every system's latest summary + totals
    README.md                    human-readable status table

Failures are published exactly like passes. That is the point of this repo.
"""
from __future__ import annotations

import argparse
import json
import xml.etree.ElementTree as ET
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "data"

# Display metadata. A system appears in the totals only once it has a run here.
SYSTEMS = {
    "qtharness": {
        "name": "qtharness",
        "summary": "Systematic trading research harness for Indian markets "
                   "(leakage gate, cost model, walk-forward, deflated Sharpe).",
        "code": "private (client work)",
    },
    "verifier": {
        "name": "EchoNerve Verifier",
        "summary": "Append-only credit ledger.",
        "code": "private",
    },
}


def parse_junit(path: Path) -> dict:
    root = ET.parse(path).getroot()
    suites = [root] if root.tag == "testsuite" else list(root.iter("testsuite"))
    tests, by_file = [], defaultdict(lambda: defaultdict(int))
    duration = 0.0
    for suite in suites:
        duration += float(suite.get("time") or 0)
        for case in suite.iter("testcase"):
            cls = case.get("classname") or ""
            file = cls.split(".")[0] + ".py" if cls else "unknown"
            if cls.startswith("tests."):
                file = "tests/" + cls.split(".")[1] + ".py"
            name = case.get("name") or "?"
            if case.find("failure") is not None:
                outcome = "failed"
            elif case.find("error") is not None:
                outcome = "error"
            elif case.find("skipped") is not None:
                outcome = "skipped"
            else:
                outcome = "passed"
            node = f"{file}::{'.'.join(cls.split('.')[2:]) + '::' if cls.count('.') > 1 else ''}{name}"
            tests.append({"id": node, "outcome": outcome})
            by_file[file][outcome] += 1
    counts = defaultdict(int)
    for t in tests:
        counts[t["outcome"]] += 1
    return {
        "counts": {k: counts[k] for k in ("passed", "failed", "error", "skipped")},
        "total": len(tests),
        "duration_s": round(duration, 2),
        "files": {f: dict(c) for f, c in sorted(by_file.items())},
        "tests": sorted(tests, key=lambda t: t["id"]),
    }


def build_summary(system: str, result: dict, args) -> dict:
    c = result["counts"]
    status = "green" if c["failed"] == 0 and c["error"] == 0 and result["total"] else "red"
    return {
        "system": system,
        "status": status,
        "passed": c["passed"],
        "failed": c["failed"] + c["error"],
        "skipped": c["skipped"],
        "total": result["total"],
        "duration_s": result["duration_s"],
        "commit": (args.commit or "")[:12],
        "run_id": args.run_id or "",
        "source": args.source,
        "run_at": args.run_at or datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }


def rebuild_index() -> dict:
    systems = {}
    for d in sorted(p for p in DATA.iterdir() if p.is_dir()):
        latest = d / "latest.json"
        if latest.exists():
            full = json.loads(latest.read_text())
            s = full["summary"]
            meta = SYSTEMS.get(d.name, {"name": d.name, "summary": "", "code": "private"})
            systems[d.name] = {**s, "name": meta["name"], "about": meta["summary"],
                               "code": meta["code"]}
    totals = {
        "passed": sum(s["passed"] for s in systems.values()),
        "failed": sum(s["failed"] for s in systems.values()),
        "skipped": sum(s["skipped"] for s in systems.values()),
        "systems": len(systems),
    }
    index = {
        "updated": max((s["run_at"] for s in systems.values()), default=""),
        "totals": totals,
        "systems": systems,
    }
    (DATA / "latest.json").write_text(json.dumps(index, indent=2) + "\n")
    return index


def render_readme(index: dict) -> None:
    t = index["totals"]
    lines = [
        "# EchoNerve test proof",
        "",
        "Every test run of the EchoNerve systems listed here is published by CI, "
        "pass or fail. The code is private; the results are not.",
        "",
        f"**{t['passed']} passed · {t['failed']} failed · {t['skipped']} skipped** "
        f"across {t['systems']} system(s). Last run: {index['updated'] or 'none yet'}.",
        "",
        "| System | Status | Passed | Failed | Skipped | Commit | Run (UTC) | Source |",
        "|---|---|---|---|---|---|---|---|",
    ]
    for key, s in index["systems"].items():
        badge = "🟢 green" if s["status"] == "green" else "🔴 red"
        lines.append(
            f"| [{s['name']}](data/{key}/latest.json) | {badge} | {s['passed']} | "
            f"{s['failed']} | {s['skipped']} | `{s['commit'] or '-'}` | {s['run_at']} | {s['source']} |")
    lines += [
        "",
        "## How to read this",
        "",
        "- `data/<system>/latest.json` lists every test by name with its outcome.",
        "- `data/<system>/history.jsonl` is append-only: one line per run, never rewritten.",
        "- This repo's own git history timestamps every run. Nothing here is edited by hand.",
        "- `source: github-actions` means GitHub's runner produced the result on a clean machine.",
        "",
        "Shown on [echonerve.com/proof](https://echonerve.com/proof/).",
        "",
    ]
    (ROOT / "README.md").write_text("\n".join(lines))


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--system", required=True)
    ap.add_argument("--junit", required=True, type=Path)
    ap.add_argument("--commit", default="")
    ap.add_argument("--run-id", default="")
    ap.add_argument("--source", default="github-actions")
    ap.add_argument("--run-at", default="")
    args = ap.parse_args()

    result = parse_junit(args.junit)
    summary = build_summary(args.system, result, args)
    out = DATA / args.system
    out.mkdir(parents=True, exist_ok=True)
    (out / "latest.json").write_text(json.dumps({"summary": summary, **result}, indent=2) + "\n")
    with (out / "history.jsonl").open("a") as fh:
        fh.write(json.dumps(summary) + "\n")
    index = rebuild_index()
    render_readme(index)
    print(json.dumps(summary))
    # Last line: short human summary, used as the commit message by CI.
    print(f"{summary['passed']} passed, {summary['failed']} failed, {summary['skipped']} skipped")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
