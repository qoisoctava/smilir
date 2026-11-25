#!/usr/bin/env python3
"""smilir task runner — calls one `func` from a DAG task.

Invoked by app/src/Runner.php as:

    <venv>/bin/python lib/runner.py --func jobs.reporting.extract \
        --ds 2025-11-15 [--result /tmp/result.json] [--params '{"k":1}']

The callable receives only the context kwargs it actually declares, e.g.
`def extract(ds)`, `def load(ds, run_id)`, or `def publish(ds, params)`.
stdout/stderr are captured by the parent process into the task log.
"""
import argparse
import importlib
import inspect
import json
import os
import sys
import traceback


def resolve(dotted):
    module_name, _, func_name = dotted.rpartition(".")
    if not module_name or not func_name:
        raise ValueError('func must look like "module.function", got %r' % dotted)
    module = importlib.import_module(module_name)
    func = getattr(module, func_name, None)
    if not callable(func):
        raise AttributeError("%s.%s is not callable" % (module_name, func_name))
    return func


def main():
    parser = argparse.ArgumentParser(description="smilir task runner")
    parser.add_argument("--func", required=True)
    parser.add_argument("--ds", required=True)
    parser.add_argument("--result")
    parser.add_argument("--params", default=None)
    args = parser.parse_args()

    params = {}
    if args.params:
        try:
            params = json.loads(args.params)
        except json.JSONDecodeError as exc:
            print("invalid --params JSON: %s" % exc, file=sys.stderr)
            return 2

    context = {
        "ds": args.ds,
        "run_id": os.environ.get("PN_RUN_ID"),
        "attempt": int(os.environ.get("PN_ATTEMPT", "1") or 1),
        "params": params,
        "task": os.environ.get("PN_TASK"),
        "dag": os.environ.get("PN_DAG"),
    }

    try:
        func = resolve(args.func)
        accepted = {
            key: value
            for key, value in context.items()
            if key in inspect.signature(func).parameters
        }
        result = func(**accepted)

        if result is not None and args.result:
            try:
                payload = json.dumps(result)
            except TypeError:
                payload = json.dumps(str(result))
            with open(args.result, "w", encoding="utf-8") as handle:
                handle.write(payload)
        return 0
    except Exception:  # noqa: BLE001 - traceback goes to the task log
        traceback.print_exc()
        return 1


if __name__ == "__main__":
    sys.exit(main())
