"""Example smilir task code — plain Python, no smilir import needed.

Replace these functions with your own logic. Anything printed goes to the
task log; a returned dict is stored in pn_task_run.result_json.
"""
import os
import sys
import time


def _log(message):
    print(message, flush=True)


def _context():
    return {
        "dag": os.environ.get("PN_DAG", "?"),
        "task": os.environ.get("PN_TASK", "?"),
        "run": os.environ.get("PN_RUN_ID", "?"),
        "attempt": os.environ.get("PN_ATTEMPT", "1"),
    }


def extract(ds):
    """Pull today's rows (demo: no external deps)."""
    ctx = _context()
    _log("extract start ds=%s run=%s" % (ds, ctx["run"]))
    time.sleep(0.3)
    rows = 1200
    _log("extract done rows=%d" % rows)
    return {"rows": rows}


def transform(ds):
    """Clean and enrich the staged rows."""
    _log("transform start ds=%s" % ds)
    time.sleep(0.2)
    _log("transform done")
    return {"prepared": True}


def load(ds):
    """Load into the warehouse."""
    _log("load start ds=%s" % ds)
    time.sleep(0.2)
    _log("load done")
    return {"loaded": True}


def publish(date):
    """CLI entry used by the `publish` task's shell command."""
    _log("publish start date=%s" % date)
    _log("publish done")
    return 0


if __name__ == "__main__":
    command = sys.argv[1] if len(sys.argv) > 1 else ""
    if command == "publish":
        ds = None
        for i, arg in enumerate(sys.argv):
            if arg in ("--date", "-d") and i + 1 < len(sys.argv):
                ds = sys.argv[i + 1]
        sys.exit(publish(ds or ""))
    print("usage: python -m jobs.reporting publish --date YYYY-MM-DD", file=sys.stderr)
    sys.exit(2)
