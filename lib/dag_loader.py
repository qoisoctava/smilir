#!/usr/bin/env python3
"""smilir DAG loader.

Executes a DAG module (dags/<name>.py) in a subprocess and prints the JSON
contract consumed by app/src/DagLoader.php:

    {"ok": true,  "dag": {...}, "warnings": [...]}
    {"ok": false, "error": "...", "traceback": "..."}

stdout is reserved for that single JSON document; anything the DAG module
prints at import time is redirected to stderr.
"""
import contextlib
import importlib.util
import io
import json
import os
import sys
import traceback


def fail(error, tb="", code=1):
    print(json.dumps({"ok": False, "error": error, "traceback": tb}))
    sys.exit(code)


def load_module(path):
    name = "pn_dag_" + str(abs(hash(path)))
    spec = importlib.util.spec_from_file_location(name, path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot create an import spec for %s" % path)
    module = importlib.util.module_from_spec(spec)
    captured = io.StringIO()
    with contextlib.redirect_stdout(captured):
        spec.loader.exec_module(module)
    noise = captured.getvalue()
    if noise:
        sys.stderr.write(noise)
    return module


def check_callable(dotted, warnings):
    module, _, func = dotted.rpartition(".")
    if not module:
        warnings.append("func %r is not module.function" % dotted)
        return
    try:
        if importlib.util.find_spec(module) is None:
            warnings.append("module not importable yet: %s" % module)
    except (ImportError, ValueError):
        warnings.append("module not importable yet: %s" % module)


def get_dag(module):
    dag = getattr(module, "DAG", None)
    if dag is None:
        obj = getattr(module, "dag", None)
        dag = obj.to_dict() if hasattr(obj, "to_dict") else obj
    return dag


def validate(dag):
    if not isinstance(dag, dict):
        raise TypeError("DAG must be a dict (or expose .to_dict())")
    tasks = dag.get("tasks")
    if not isinstance(tasks, list) or not tasks:
        raise ValueError("DAG['tasks'] must be a non-empty list")
    seen = set()
    for i, task in enumerate(tasks):
        if not isinstance(task, dict):
            raise TypeError("tasks[%d] must be a dict" % i)
        name = str(task.get("name") or "").strip()
        if not name:
            raise ValueError("tasks[%d] is missing a name" % i)
        if name in seen:
            raise ValueError("duplicate task name %r" % name)
        seen.add(name)
        if not task.get("func") and not task.get("command"):
            raise ValueError("task %r needs a func or command" % name)


def main():
    if len(sys.argv) < 2:
        fail("usage: dag_loader.py <path-to-dag.py>")
    path = os.path.abspath(sys.argv[1])
    if not os.path.isfile(path):
        fail("file not found: %s" % path)

    try:
        module = load_module(path)
        dag = get_dag(module)
        validate(dag)

        warnings = []
        for task in dag.get("tasks", []):
            if task.get("func"):
                check_callable(str(task["func"]), warnings)

        print(json.dumps({"ok": True, "dag": dag, "warnings": warnings}))
        return 0
    except SystemExit:
        raise
    except Exception as exc:  # noqa: BLE001 - the contract wants a JSON error
        fail("%s: %s" % (type(exc).__name__, exc), traceback.format_exc())


if __name__ == "__main__":
    sys.exit(main())
