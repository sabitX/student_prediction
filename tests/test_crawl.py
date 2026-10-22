"""Crawls every page each role can reach (GET only) and flags errors.

Follows every internal link from each role's dashboard and reports any
page that isn't HTTP 200, shows PHP warnings or the error page, or signs
the user out unexpectedly. Safe to run: no page changes data on a GET.
"""
import re, requests
from urllib.parse import urljoin, urlparse
from helpers import *

ROOT = BASE.rstrip('/') + '/'
APP_PATH = urlparse(ROOT).path


def crawl(role, sess, start):
    seen, queue, problems = set(), [urljoin(ROOT, p) for p in start], []
    while queue and len(seen) < 400:
        url = queue.pop(0)
        key = re.sub(r'([?&])(page|q)=[^&]*', r'\1', url)
        if key in seen:
            continue
        seen.add(key)
        r = sess.get(url, timeout=30)
        m = PHP_ERROR.search(r.text)
        if r.status_code != 200 or m:
            problems.append((r.status_code, url, m.group(0)[:120] if m else ''))
        if role != 'public' and 'login.php' in r.url and 'login.php' not in url:
            problems.append(('signed out', url, ''))
        if 'text/html' not in r.headers.get('Content-Type', ''):
            continue
        for href in re.findall(r'href="([^"#]+)"', r.text):
            u = urljoin(r.url, href.replace('&amp;', '&'))
            p = urlparse(u)
            if p.netloc != urlparse(ROOT).netloc or not p.path.startswith(APP_PATH):
                continue
            if re.search(r'\.(css|js|png|ico|jpg|svg|woff2?)$', p.path) or 'logout' in p.path or 'export' in p.path or 'download' in p.query:
                continue
            queue.append(u)
    check(f'{role}: {len(seen)} pages, no errors', not problems, problems)


crawl('public', requests.Session(), ['index.php', 'auth/login.php', 'auth/login.php?portal=staff'])
r = requests.get(ROOT + 'no-such-page.php')
check('missing page shows the custom 404', r.status_code == 404 and 'SPTS' in r.text)
crawl('student', fake_session(sql("SELECT s.user_id FROM students s JOIN student_enrollments e ON e.student_id = s.id LIMIT 1"), 'student'), ['student/student_dashboard.php'])
crawl('lecturer', fake_session(sql("SELECT user_id FROM lecturer_subjects LIMIT 1"), 'lecturer'), ['lecturer/lecturer_dashboard.php'])
crawl('admin', fake_session(sql("SELECT id FROM users WHERE role = 'admin' LIMIT 1"), 'admin'), ['admin/admin_dashboard.php'])
remove_sessions()
finish()
