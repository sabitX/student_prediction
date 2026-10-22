"""Shared helpers for the SPTS end-to-end tests.

The tests drive a running local copy over HTTP and check the database
directly, so they need XAMPP (Apache + MariaDB) with the app installed.
Paths default to a standard Windows XAMPP install; override them with
environment variables:

  SPTS_BASE      app URL               (http://localhost/student_prediction)
  SPTS_MYSQL     mysql client          (C:/xampp/mysql/bin/mysql)
  SPTS_DB        database name         (student_prediction)
  SPTS_DB_USER   a user that can read and write every table (root)
  SPTS_PHP       php CLI               (C:/xampp/php/php)
  SPTS_SESSIONS  PHP session folder    (C:/xampp/tmp)

Every test puts the database back the way it found it. Never point them
at a live database.
"""
import os, re, subprocess, sys, requests

BASE = os.environ.get('SPTS_BASE', 'http://localhost/student_prediction')
MYSQL = [os.environ.get('SPTS_MYSQL', 'C:/xampp/mysql/bin/mysql'), '-u', os.environ.get('SPTS_DB_USER', 'root'),
         '--default-character-set=utf8mb4', os.environ.get('SPTS_DB', 'student_prediction'), '-N', '-e']
PHP = os.environ.get('SPTS_PHP', 'C:/xampp/php/php')
SESSIONS = os.environ.get('SPTS_SESSIONS', 'C:/xampp/tmp')
APP_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

_results = []


def sql(query):
    """Runs SQL as the test user; returns the output, tab-separated, without headers."""
    return subprocess.run(MYSQL + [query], capture_output=True, text=True, encoding='utf-8', check=True).stdout.strip()


def sql_may_fail(query):
    return subprocess.run(MYSQL + [query], capture_output=True, text=True, encoding='utf-8')


def check(name, cond, extra=''):
    _results.append((name, bool(cond)))
    print(('PASS ' if cond else 'FAIL ') + name + ((' -- ' + str(extra)) if extra and not cond else ''), flush=True)


def csrf(html):
    m = re.search(r'name="csrf_token" value="([^"]+)"', html)
    return m.group(1) if m else ''


def fake_session(user_id, role, name='Tester', prefix='spttest'):
    """A signed-in requests.Session for that user, made by writing a PHP session file directly."""
    sid = f'{prefix}{user_id}{role}'
    code = ('ini_set("session.save_path", $argv[5]); session_id($argv[1]); session_start();'
            '$_SESSION=["user_id"=>(int)$argv[2],"role"=>$argv[3],"full_name"=>$argv[4],"last_activity"=>time()]; session_write_close();')
    subprocess.run([PHP, '-r', code, sid, str(user_id), role, name, SESSIONS], check=True)
    s = requests.Session()
    s.cookies.set('PHPSESSID', sid)
    return s


def remove_sessions(prefix='spttest'):
    for f in os.listdir(SESSIONS):
        if f.startswith('sess_' + prefix):
            os.remove(os.path.join(SESSIONS, f))


PHP_ERROR = re.compile(r'(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)\b.*? in [A-Za-z]:|Something went wrong|mysqli_sql_exception', re.S)


def finish():
    failed = [n for n, ok in _results if not ok]
    print(f'\n{len(_results) - len(failed)}/{len(_results)} passed', flush=True)
    sys.exit(1 if failed else 0)
