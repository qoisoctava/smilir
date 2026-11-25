"""Example smilir DAG — see DESIGN.md §6.

A DAG is a Python module exposing one `DAG` dict. Tasks are either:
  - `func`:    dotted callable path, called as func(ds=...)
  - `command`: shell string, `{ds}` is replaced with the logical date

No smilir import is required.
"""

DAG = {
    "name": "daily_report",
    "schedule": "0 3 * * *",          # cron: daily at 03:00
    # "schedule": "interval:60",      # or every 60 minutes
    # "venv": "reporting",            # pin a registered venv for all tasks
    "max_concurrency": 1,

    "tasks": [
        {
            "name": "extract",
            "func": "jobs.reporting.extract",
            "retries": 2,
            "retry_delay": 120,
            "timeout": 300,
        },
        {
            "name": "transform",
            "func": "jobs.reporting.transform",
            "depends_on": ["extract"],
            "timeout": 600,
        },
        {
            "name": "load",
            "func": "jobs.reporting.load",
            "depends_on": ["transform"],
            "email_on_failure": True,
        },
        {
            "name": "publish",
            "command": "python -m jobs.reporting publish --date {ds}",
            "depends_on": ["load"],
        },
    ],
}
