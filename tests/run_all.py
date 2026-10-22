"""Runs every SPTS test suite and prints a summary.  Usage: python tests/run_all.py"""
import os, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
SUITES = ['test_crawl.py', 'test_support.py', 'test_weights.py', 'test_ew_email.py', 'test_exports.py']

env = dict(os.environ, PYTHONIOENCODING='utf-8')
summary = []
for suite in SUITES:
    print(f'\n=== {suite}', flush=True)
    proc = subprocess.run([sys.executable, os.path.join(HERE, suite)], cwd=HERE, env=env,
                          capture_output=True, text=True, encoding='utf-8')
    sys.stdout.write(proc.stdout)
    if proc.stderr:
        sys.stdout.write(proc.stderr)
    last = [l for l in proc.stdout.splitlines() if l.strip()][-1:] or ['(no output)']
    summary.append((suite, proc.returncode == 0, last[0]))

print('\n=== Summary')
for suite, ok, last in summary:
    print(f"{'OK  ' if ok else 'FAIL'} {suite:20} {last}")
sys.exit(0 if all(ok for _, ok, _ in summary) else 1)
