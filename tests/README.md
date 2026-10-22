# SPTS automated tests

End-to-end tests that drive a running local copy of SPTS over HTTP and check the database directly.

**Run them only against a local development copy, never the live site.** Each suite changes data while it runs (marks, weights, passwords, settings) and puts everything back afterwards. `test_ew_email.py` also edits `config/env.php` for a moment to point email at a fake local mail server, then restores it.

## Requirements

- XAMPP running (Apache + MariaDB) with SPTS installed and all migrations applied.
- Python 3.10+ with `requests` (`pip install requests`).
- Some data: at least one student, one lecturer with an enrolled, marked subject in the active semester, and one admin. `php tools/seed_demo_history.php` adds two past "Demo" semesters, which the export and trend checks use.

Paths default to a standard Windows XAMPP install. Override them with the `SPTS_*` environment variables listed at the top of `helpers.py`.

## Running

```
python tests/run_all.py            # every suite, then a summary
python tests/test_weights.py       # one suite
```

## Suites

| File | Covers |
|---|---|
| `test_crawl.py` | Every page each role can reach loads with no PHP errors; custom 404 page |
| `test_support.py` | Contact Admin: public and signed-in forms, validation, honeypot, rate limits, identical replies for unknown accounts, admin filters and statuses, access control, identity-verified password reset, sign-in with the temporary password and the forced change |
| `test_weights.py` | Per-subject, per-semester assessment weights: validation, recalculated totals, untouched history, carry-over to a new semester, the what-if calculator and dashboards, admin-only editing, database CHECK constraints |
| `test_ew_email.py` | Early-warning emails through a local SMTP sink (`smtp_sink.py`): content and links, "email all" never repeats, roster-only targets, CSRF, mail server down, no mail settings |
| `test_exports.py` | CSV exports: contents match the database, UTF-8 BOM, role and ownership checks, formula-injection protection, audit log |

A suite exits with code 0 when every check passes.
