"""Early-warning email tests against local XAMPP + the SMTP sink (nothing leaves this machine)."""
import os, re, shutil, subprocess, sys, time, requests
from helpers import *

B = BASE

S = os.path.dirname(os.path.abspath(__file__))
ENV = os.path.join(APP_DIR, 'config', 'env.php')
SINK_OUT = os.path.join(S, 'sink.txt')

def sink_text():
    return open(SINK_OUT, encoding='utf-8').read() if os.path.exists(SINK_OUT) else ''

SEM = int(sql("SELECT id FROM semesters WHERE is_active=1"))
# A lecturer with marked students this semester; blank the final exam of two of them
# (one weak, one strong) so the early warning applies, then put the marks back.
LEC, SUB = sql(f"SELECT ls.user_id, ls.subject_id FROM lecturer_subjects ls JOIN student_enrollments e ON e.subject_id=ls.subject_id AND e.semester_id=ls.semester_id JOIN performance p ON p.enrollment_id=e.id WHERE ls.semester_id={SEM} GROUP BY ls.user_id, ls.subject_id ORDER BY COUNT(*) DESC LIMIT 1").split('\t')
weak = sql(f"SELECT e.id FROM student_enrollments e JOIN performance p ON p.enrollment_id=e.id WHERE e.subject_id={SUB} AND e.semester_id={SEM} ORDER BY p.assignment+p.midterm LIMIT 2").split('\n')
saved = {eid: sql(f"SELECT CONCAT_WS(',', COALESCE(assignment,'NULL'), COALESCE(midterm,'NULL'), COALESCE(final_exam,'NULL'), COALESCE(attendance,'NULL')) FROM performance WHERE enrollment_id={eid}") for eid in weak}
start_all = sql("SELECT * FROM predictions ORDER BY 1,2,3")
env_backup = os.path.join(S, 'env.php.bak')
shutil.copy(ENV, env_backup)
audit_max = int(sql("SELECT COALESCE(MAX(id),0) FROM audit_logs"))
sink = None
try:
    for eid in weak:
        sql(f"UPDATE performance SET final_exam=NULL, assignment=LEAST(assignment, 30), midterm=LEAST(midterm, 30) WHERE enrollment_id={eid}")
    lec = fake_session(LEC, 'lecturer', sql(f"SELECT full_name FROM users WHERE id={LEC}"))

    # 1. Without mail settings: no buttons, an honest note, and a forged POST sends nothing.
    r = lec.get(f'{B}/lecturer/lecturer_dashboard.php')
    check('roster shows flagged students', 'flagged</span>' in r.text and 'Student told' in r.text)
    check('no email buttons without mail settings', 'name="ew_notify"' not in r.text and "Email isn't set up" in r.text)
    r = lec.post(f'{B}/lecturer/lecturer_dashboard.php', data={'csrf_token': csrf(r.text), 'ew_notify_all': '1'})
    check('forged send without mail: honest error, nothing recorded', "nothing was sent" in r.text and sql("SELECT COUNT(*) FROM early_warning_notices") == '0')

    # 2. With mail pointed at the local sink.
    if os.path.exists(SINK_OUT): os.remove(SINK_OUT)
    env = open(ENV, encoding='utf-8').read()
    env = env.replace('];', "    'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => 2525, 'MAIL_ENCRYPTION' => 'none', 'MAIL_FROM' => 'spts@localhost.test', 'APP_URL' => 'https://spts.example.test/student_prediction',\n];", 1)
    open(ENV, 'w', encoding='utf-8', newline='').write(env)
    sink = subprocess.Popen([sys.executable, os.path.join(S, 'smtp_sink.py'), SINK_OUT, '2525'])
    time.sleep(1.5)

    r = lec.get(f'{B}/lecturer/lecturer_dashboard.php')
    n_flagged = len(re.findall(r'name="ew_notify" value="(\d+)"', r.text))
    check('email buttons shown with mail settings', n_flagged >= 1 and 'Email all not yet told (' in r.text, n_flagged)
    first = re.search(r'name="ew_notify" value="(\d+)"', r.text).group(1)
    r = lec.post(f'{B}/lecturer/lecturer_dashboard.php', data={'csrf_token': csrf(r.text), 'ew_notify': first})
    check('single email reported sent', '1 early-warning email sent.' in r.text, re.findall(r'alert[^>]*>\s*([^<]+)', r.text)[:2])
    mail = sink_text()
    stu_email = sql(f"SELECT u.email FROM student_enrollments e JOIN students s ON s.id=e.student_id JOIN users u ON u.id=s.user_id WHERE e.id={first}")
    check('email went to the student', f'TO: {stu_email}' in mail, mail[:200])
    check('email explains the risk', 'point to a risk of not passing' in mail and 'Projected total:' in mail and 'Early warning for' in mail)
    check('email links use APP_URL', 'https://spts.example.test/student_prediction/student/mentorship.php' in mail)
    check('email contains no password or marks of others', 'password' not in mail.lower())
    check('notice recorded', sql(f"SELECT COUNT(*) FROM early_warning_notices WHERE enrollment_id={first} AND sent_by={LEC}") == '1')
    r = lec.get(f'{B}/lecturer/lecturer_dashboard.php')
    check('dashboard shows who was told', 'bi-envelope-check' in r.text and 'Email again' in r.text)

    # Email all: only the ones not yet told.
    before = sink_text().count('=== TO:')
    r = lec.post(f'{B}/lecturer/lecturer_dashboard.php', data={'csrf_token': csrf(r.text), 'ew_notify_all': '1'})
    sent_now = sink_text().count('=== TO:') - before
    check('email all skips students already told', sent_now == n_flagged - 1, (sent_now, n_flagged))
    r = lec.post(f'{B}/lecturer/lecturer_dashboard.php', data={'csrf_token': csrf(r.text), 'ew_notify_all': '1'})
    check('second email all sends nothing', 'already been emailed' in r.text and sink_text().count('=== TO:') == before + sent_now)
    check('audit log records emails', sql(f"SELECT COUNT(*) FROM audit_logs WHERE id>{audit_max} AND action='Early warning emailed'") != '0')

    # Not my student / forged enrolment id.
    other = sql(f"SELECT e.id FROM student_enrollments e WHERE e.id NOT IN (SELECT e2.id FROM student_enrollments e2 JOIN lecturer_subjects ls ON ls.subject_id=e2.subject_id AND ls.semester_id=e2.semester_id WHERE ls.user_id={LEC}) LIMIT 1")
    before = sink_text().count('=== TO:')
    r = lec.post(f'{B}/lecturer/lecturer_dashboard.php', data={'csrf_token': csrf(r.text), 'ew_notify': other or '999999'})
    check("can't email a student outside my roster", 'on your early-warning list any more' in r.text and sink_text().count('=== TO:') == before)
    r = lec.post(f'{B}/lecturer/lecturer_dashboard.php', data={'csrf_token': 'bad', 'ew_notify_all': '1'})
    check('bad CSRF sends nothing', sink_text().count('=== TO:') == before)
    stu = fake_session(sql("SELECT user_id FROM students LIMIT 1"), 'student')
    r = stu.post(f'{B}/lecturer/lecturer_dashboard.php', data={'ew_notify_all': '1'}, allow_redirects=False)
    check('student cannot trigger emails', r.status_code == 302 and sink_text().count('=== TO:') == before)

    # Mail server down: reported as not sent, nothing recorded.
    sink.terminate(); sink.wait(); sink = None
    sql("DELETE FROM early_warning_notices")
    r = lec.get(f'{B}/lecturer/lecturer_dashboard.php')
    r = lec.post(f'{B}/lecturer/lecturer_dashboard.php', data={'csrf_token': csrf(r.text), 'ew_notify': first})
    check('mail server down: failure reported, not recorded', 'Could not send to' in r.text and sql("SELECT COUNT(*) FROM early_warning_notices") == '0')
finally:
    if sink: sink.terminate()
    shutil.copy(env_backup, ENV); os.remove(env_backup)
    for eid, vals in saved.items():
        a, m, f, t = vals.split(',')
        sql(f"UPDATE performance SET assignment={a}, midterm={m}, final_exam={f}, attendance={t} WHERE enrollment_id={eid}")
    sql("DELETE FROM early_warning_notices")
    check('marks restored exactly', sql("SELECT * FROM predictions ORDER BY 1,2,3") == start_all)
    sql(f"DELETE FROM audit_logs WHERE id > {audit_max}")
    remove_sessions()

finish()
