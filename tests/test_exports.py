"""CSV export tests (local XAMPP)."""
import csv, io, re, subprocess, requests
from helpers import *

B = BASE

def parse(r):
    text = r.content.decode('utf-8')
    return text, list(csv.reader(io.StringIO(text.lstrip('\ufeff'))))

SEM = int(sql("SELECT id FROM semesters WHERE is_active=1"))
LEC, SUB = sql(f"SELECT ls.user_id, ls.subject_id FROM lecturer_subjects ls JOIN student_enrollments e ON e.subject_id=ls.subject_id AND e.semester_id=ls.semester_id WHERE ls.semester_id={SEM} GROUP BY 1,2 ORDER BY COUNT(*) DESC LIMIT 1").split('\t')
OTHER_SUB = sql(f"SELECT id FROM subjects WHERE id NOT IN (SELECT subject_id FROM lecturer_subjects WHERE user_id={LEC} AND semester_id={SEM}) LIMIT 1")
audit_max = int(sql("SELECT COALESCE(MAX(id),0) FROM audit_logs"))
n_enrolled = int(sql(f"SELECT COUNT(*) FROM student_enrollments WHERE subject_id={SUB} AND semester_id={SEM}"))
code = sql(f"SELECT subject_code FROM subjects WHERE id={SUB}")
lec, adm = fake_session(LEC, 'lecturer'), fake_session(1, 'admin')
stu = fake_session(sql("SELECT user_id FROM students LIMIT 1"), 'student')
try:
    r = lec.get(f'{B}/lecturer/enter_marks.php?subject_id={SUB}')
    check('mark entry page links to Results CSV', f'export.php?subject_id={SUB}' in r.text)
    r = lec.get(f'{B}/lecturer/export.php?subject_id={SUB}')
    text, rows = parse(r)
    check('lecturer CSV downloads', r.status_code == 200 and 'text/csv' in r.headers['Content-Type'] and 'attachment' in r.headers['Content-Disposition'])
    check('CSV starts with UTF-8 BOM for Excel', text.startswith('\ufeff'))
    check('header row', rows[0][:4] == ['Subject Code', 'Subject', 'Student ID', 'Name'] and 'Weights' in rows[0])
    check(f'one row per enrolled student ({n_enrolled})', len(rows) - 1 == n_enrolled and all(row[0] == code for row in rows[1:]), len(rows) - 1)
    totals = {row[2]: row[10] for row in rows[1:]}
    db = dict(l.split('\t') for l in sql(f"SELECT s.student_id, COALESCE(pr.total_score,'') FROM student_enrollments e JOIN students s ON s.id=e.student_id LEFT JOIN predictions pr ON pr.student_id=e.student_id AND pr.subject_id=e.subject_id AND pr.semester_id=e.semester_id WHERE e.subject_id={SUB} AND e.semester_id={SEM}").splitlines())
    check('totals match the predictions view', totals == db, (totals, db))
    r = lec.get(f'{B}/lecturer/export.php?subject_id={OTHER_SUB}', allow_redirects=False)
    check("lecturer can't export a subject they don't teach", r.status_code == 302 and 'text/csv' not in r.headers.get('Content-Type', ''))

    r = adm.get(f'{B}/admin/export.php?type=results')
    text, rows = parse(r)
    check('admin results CSV: every enrolment this semester', len(rows) - 1 == int(sql(f"SELECT COUNT(*) FROM student_enrollments WHERE semester_id={SEM}")))
    past = sql(f"SELECT id FROM semesters WHERE id<>{SEM} AND id IN (SELECT semester_id FROM student_enrollments) LIMIT 1")
    if past:
        text, rows = parse(adm.get(f'{B}/admin/export.php?type=results&semester_id={past}'))
        check('admin results CSV for a past semester', len(rows) - 1 == int(sql(f"SELECT COUNT(*) FROM student_enrollments WHERE semester_id={past}")))
        text, rows = parse(adm.get(f'{B}/admin/export.php?type=at_risk&semester_id={past}'))
        n_bad = int(sql(f"SELECT COUNT(*) FROM predictions WHERE semester_id={past} AND predicted_status IN ('At Risk','Fail')"))
        check(f'at-risk CSV lists At Risk/Fail results ({n_bad})', len(rows) - 1 == n_bad and rows[0][4] == 'Stage', len(rows) - 1)
    r = adm.get(f'{B}/admin/export.php?type=results&semester_id=999999', allow_redirects=False)
    check('unknown semester redirects, no file', r.status_code == 302)
    check('exports recorded in audit log', int(sql(f"SELECT COUNT(*) FROM audit_logs WHERE id>{audit_max} AND action IN ('Results exported','At-risk list exported')")) >= 3)
    check('dashboard + semesters link to exports', 'export.php?type=at_risk' in adm.get(f'{B}/admin/admin_dashboard.php').text and 'export.php?type=results&amp;semester_id=' in adm.get(f'{B}/admin/manage_semesters.php').text)

    for who, sess in (('student', stu), ('anonymous', requests.Session())):
        for path in ('admin/export.php?type=results', f'lecturer/export.php?subject_id={SUB}'):
            r = sess.get(f'{B}/{path}', allow_redirects=False)
            check(f'{who} blocked from {path.split("?")[0]}', r.status_code == 302 and 'text/csv' not in r.headers.get('Content-Type', ''))
    r = lec.get(f'{B}/admin/export.php?type=results', allow_redirects=False)
    check('lecturer blocked from admin export', r.status_code == 302)

    out = subprocess.run([PHP, '-r', 'require "' + APP_DIR.replace('\\', '/') + '/includes/export.php"; foreach (["=HYPERLINK(1)", "+1+1", "-2+3", "@SUM(A1)", "-5", "12.5", "Normal", null] as $v) echo json_encode(csv_safe_cell($v)), "\n";'],
                         capture_output=True, text=True).stdout.split()
    check('formula cells neutralised, numbers kept', out == ['"\'=HYPERLINK(1)"', '"\'+1+1"', '"\'-2+3"', '"\'@SUM(A1)"', '"-5"', '"12.5"', '"Normal"', '""'], out)
finally:
    sql(f"DELETE FROM audit_logs WHERE id > {audit_max}")
    remove_sessions()

finish()
