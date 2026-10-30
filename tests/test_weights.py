"""End-to-end tests for per-subject, per-semester assessment weights (local XAMPP)."""
import re, subprocess, requests
from helpers import *

B = BASE

SEM = int(sql("SELECT id FROM semesters WHERE is_active = 1"))
SUB = int(sql(f"SELECT e.subject_id FROM performance p JOIN student_enrollments e ON e.id=p.enrollment_id WHERE e.semester_id={SEM} AND p.final_exam IS NOT NULL GROUP BY e.subject_id ORDER BY COUNT(*) DESC LIMIT 1"))
CODE = sql(f"SELECT subject_code FROM subjects WHERE id={SUB}")
saved_w = sql(f"SELECT weight_assignment, weight_midterm, weight_final_exam, weight_attendance FROM assessment_weights WHERE subject_id={SUB} AND semester_id={SEM}")
audit_max = int(sql("SELECT COALESCE(MAX(id),0) FROM audit_logs"))
stu_uid, stu_db = sql(f"SELECT s.user_id, s.id FROM students s JOIN student_enrollments e ON e.student_id=s.id JOIN performance p ON p.enrollment_id=e.id WHERE e.subject_id={SUB} AND e.semester_id={SEM} AND p.final_exam IS NOT NULL LIMIT 1").split('\t')
lec_uid = sql(f"SELECT user_id FROM lecturer_subjects WHERE subject_id={SUB} AND semester_id={SEM}")
adm, stu = fake_session(1, 'admin'), fake_session(stu_uid, 'student')
start_all = sql("SELECT * FROM predictions ORDER BY 1,2,3")
def wvals(sub, sem):
    v = sql(f"SELECT weight_assignment, weight_midterm, weight_final_exam, weight_attendance FROM assessment_weights WHERE subject_id={sub} AND semester_id={sem}")
    return tuple(float(x) for x in v.split('\t')) if v else None
before_other = sql(f"SELECT * FROM predictions WHERE NOT (subject_id={SUB} AND semester_id={SEM}) ORDER BY 1,2,3")
test_sem = None

def post_weights(a, m, f, t):
    page = adm.get(f'{B}/admin/subject_outcomes.php?id={SUB}')
    return adm.post(f'{B}/admin/subject_outcomes.php?id={SUB}', data={'csrf_token': csrf(page.text), 'save_weights': '1',
        'weight[assignment]': a, 'weight[midterm]': m, 'weight[final_exam]': f, 'weight[attendance]': t})

try:
    r = adm.get(f'{B}/admin/subject_outcomes.php?id={SUB}')
    check('subject page shows weights editor', 'Assessment Weights' in r.text and 'name="weight[final_exam]"' in r.text)
    check('subjects list links to Weights & Outcomes', 'Weights &amp; Outcomes' in adm.get(f'{B}/admin/manage_subjects.php').text)

    for vals, msg in [(('20', '30', '40', '9'), 'must add up to 100%'),
                      (('20', '30', '40', '9.99'), 'must add up to 100%'),
                      (('20', '30', '40', '10.01'), 'must add up to 100%'), (('5', '5', '85', '5'), 'between 5% and 80%'),
                      (('abc', '30', '40', '10'), 'Enter every weight as a number'), (('20.123', '29.877', '40', '10'), 'up to 2 decimal places'),
                      (('2', '38', '50', '10'), 'between 5% and 80%')]:
        r = post_weights(*vals)
        check(f'rejects {vals}', msg in r.text, re.findall(r'alert-bad[^>]*>.*?</div>', r.text, re.S)[:1])
    check('invalid weights not saved', sql(f"SELECT weight_assignment, weight_midterm, weight_final_exam, weight_attendance FROM assessment_weights WHERE subject_id={SUB} AND semester_id={SEM}") == saved_w)
    r = post_weights('20', '30', '40', '9')
    check('typed values kept after error', 'value="9"' in r.text)

    # Valid change
    r = post_weights('30', '20', '35', '15')
    check('valid weights saved', 'Weights for' in r.text and wvals(SUB, SEM) == (30, 20, 35, 15), wvals(SUB, SEM))
    audit = sql(f"SELECT details FROM audit_logs WHERE id>{audit_max} AND action='Assessment weights changed'")
    check('audit log records the change', 'Assignment 20% · Midterm 30% · Final 40% · Attendance 10% → Assignment 30% · Midterm 20% · Final 35% · Attendance 15%' in audit, audit)
    rows = sql(f"SELECT p.assignment, p.midterm, p.final_exam, p.attendance, pr.total_score, pr.result_status FROM performance p JOIN student_enrollments e ON e.id=p.enrollment_id JOIN predictions pr ON pr.student_id=e.student_id AND pr.subject_id=e.subject_id AND pr.semester_id=e.semester_id WHERE e.subject_id={SUB} AND e.semester_id={SEM}").splitlines()
    ok = True
    for line in rows:
        a, m, f, t, total, status = line.split('\t')
        expect = round(float(a) * .30 + float(m) * .20 + float(f) * .35 + float(t) * .15, 2)
        band = 'Excellent' if expect >= 80 else 'Pass' if expect >= 60 else 'At Risk' if expect >= 50 else 'Fail'
        ok &= abs(expect - float(total)) < 0.006 and band == status
    check(f'predictions view uses new weights ({len(rows)} results)', ok and rows)
    check('other subjects/semesters unchanged', sql(f"SELECT * FROM predictions WHERE NOT (subject_id={SUB} AND semester_id={SEM}) ORDER BY 1,2,3") == before_other)
    w = sql(f"SELECT w_assignment, w_midterm, w_final_exam, w_attendance FROM early_risk_predictions WHERE subject_id={SUB} AND semester_id={SEM} LIMIT 1")
    check('early_risk view exposes weights', tuple(float(x) for x in w.split('\t')) == (0.3, 0.2, 0.35, 0.15), w)

    j = stu.get(f'{B}/student/predict.php?get_marks=1&subject_id={SUB}').json()
    check('what-if calculator receives subject weights', j.get('weights') == {'assignment': 0.3, 'midterm': 0.2, 'final_exam': 0.35, 'attendance': 0.15}, j.get('weights'))
    r = stu.get(f'{B}/student/student_dashboard.php')
    check('student dashboard shows per-subject weights', '×35%' in r.text and '×15%' in r.text and '(40%)' not in r.text)
    if lec_uid:
        lec = fake_session(lec_uid, 'lecturer')
        r = lec.get(f'{B}/lecturer/enter_marks.php?subject_id={SUB}')
        check('lecturer sees weights on mark entry', 'Weights: Assignment 30% · Midterm 20% · Final 35% · Attendance 15%' in r.text)
        check('lecturer dashboard still loads', lec.get(f'{B}/lecturer/lecturer_dashboard.php').status_code == 200)
    for path in ('admin/admin_dashboard.php', 'admin/model_evaluation.php', f'admin/view_student.php?id={stu_db}'):
        r = adm.get(f'{B}/{path}')
        check(f'{path} loads cleanly', r.status_code == 200 and not re.search(r'(Warning|Fatal error|Deprecated|Notice)\b.*? in [A-Z]:|Something went wrong', r.text))
    for path in ('student/transcript.php', 'student/mentorship.php', 'student/predict.php'):
        r = stu.get(f'{B}/{path}')
        check(f'{path} loads cleanly', r.status_code == 200 and not re.search(r'(Warning|Fatal error|Deprecated|Notice)\b.*? in [A-Z]:|Something went wrong', r.text))

    # Lecturer / student can't change weights.
    for who, sess in (('student', stu),) + ((('lecturer', fake_session(lec_uid, 'lecturer')),) if lec_uid else ()):
        r = sess.post(f'{B}/admin/subject_outcomes.php?id={SUB}', data={'save_weights': '1', 'weight[assignment]': '25', 'weight[midterm]': '25', 'weight[final_exam]': '25', 'weight[attendance]': '25'}, allow_redirects=False)
        check(f'{who} cannot change weights', r.status_code == 302 and wvals(SUB, SEM) == (30, 20, 35, 15), (r.status_code, wvals(SUB, SEM)))

    # DB constraint as a backstop.
    bad = sql_may_fail(f"UPDATE assessment_weights SET weight_attendance = 20 WHERE subject_id={SUB} AND semester_id={SEM}")
    check('database CHECK rejects a total other than 100', bad.returncode != 0 and 'chk_weight_total' in bad.stderr)
    for attendance in ('14.99', '15.01'):
        bad = sql_may_fail(f"UPDATE assessment_weights SET weight_attendance = {attendance} WHERE subject_id={SUB} AND semester_id={SEM}")
        check(f'database CHECK rejects total {85 + float(attendance):.2f}', bad.returncode != 0 and 'chk_weight_total' in bad.stderr)

    # Carry-over: a new semester inherits these weights; going back changes nothing.
    sql("INSERT INTO semesters (semester_name, start_date, end_date, is_active) VALUES ('ZZ Weights Test', '2031-01-01', '2031-05-01', 0)")
    test_sem = int(sql("SELECT id FROM semesters WHERE semester_name='ZZ Weights Test'"))
    page = adm.get(f'{B}/admin/manage_semesters.php')
    adm.post(f'{B}/admin/manage_semesters.php', data={'csrf_token': csrf(page.text), 'activate_id': test_sem})
    check('new semester inherits weights', wvals(SUB, test_sem) == (30, 20, 35, 15), wvals(SUB, test_sem))
    check('activation audit mentions weights', 'Assessment weights carried over' in sql(f"SELECT details FROM audit_logs WHERE id>{audit_max} AND action='Active semester changed' ORDER BY id DESC LIMIT 1"))
    page = adm.get(f'{B}/admin/manage_semesters.php')
    adm.post(f'{B}/admin/manage_semesters.php', data={'csrf_token': csrf(page.text), 'activate_id': SEM})
    check('original semester active again', int(sql("SELECT id FROM semesters WHERE is_active=1")) == SEM)
    check('re-activating keeps weights', wvals(SUB, SEM) == (30, 20, 35, 15), wvals(SUB, SEM))
finally:
    if saved_w:
        a, m, f, t = saved_w.split('\t')
        sql(f"UPDATE assessment_weights SET weight_assignment={a}, weight_midterm={m}, weight_final_exam={f}, weight_attendance={t} WHERE subject_id={SUB} AND semester_id={SEM}")
    else:
        sql(f"DELETE FROM assessment_weights WHERE subject_id={SUB} AND semester_id={SEM}")
    sql(f"UPDATE semesters SET is_active = (id = {SEM})")
    if test_sem:
        sql(f"DELETE FROM lecturer_subjects WHERE semester_id={test_sem}; DELETE FROM assessment_weights WHERE semester_id={test_sem}; DELETE FROM semesters WHERE id={test_sem}")
    sql(f"DELETE FROM audit_logs WHERE id > {audit_max}")
    remove_sessions()
    check('restored: results identical to start', sql("SELECT * FROM predictions ORDER BY 1,2,3") == start_all)

finish()
