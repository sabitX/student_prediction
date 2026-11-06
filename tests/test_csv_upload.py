"""CSV mark upload tests (local XAMPP): blank cells keep saved marks, and
form fields sent as arrays get a normal reply instead of a server error."""
import requests
from helpers import *

B = BASE

SEM = int(sql("SELECT id FROM semesters WHERE is_active=1"))
# A lecturer's student with all four marks saved this semester.
LEC, SUB, STU, CODE, SCODE, ENR = sql(
    f"SELECT ls.user_id, ls.subject_id, s.id, s.student_id, sub.subject_code, e.id FROM lecturer_subjects ls "
    f"JOIN subjects sub ON sub.id=ls.subject_id AND sub.is_archived=0 "
    f"JOIN student_enrollments e ON e.subject_id=ls.subject_id AND e.semester_id=ls.semester_id "
    f"JOIN students s ON s.id=e.student_id JOIN performance p ON p.enrollment_id=e.id "
    f"WHERE ls.semester_id={SEM} AND p.assignment IS NOT NULL AND p.midterm IS NOT NULL "
    f"AND p.final_exam IS NOT NULL AND p.attendance IS NOT NULL LIMIT 1").split('\t')
saved = sql(f"SELECT assignment, midterm, final_exam, attendance FROM performance WHERE enrollment_id={ENR}").split('\t')
audit_max = int(sql("SELECT COALESCE(MAX(id),0) FROM audit_logs"))
new_assignment = '41.5' if saved[0] != '41.50' else '42.5'

def marks():
    return sql(f"SELECT assignment, midterm, final_exam, attendance FROM performance WHERE enrollment_id={ENR}").split('\t')

def upload(sess, body):
    tok = csrf(sess.get(f'{B}/lecturer/csv_upload.php').text)
    return sess.post(f'{B}/lecturer/csv_upload.php', data={'csrf_token': tok, 'upload': '1'},
                     files={'csv_file': ('marks.csv', body.encode('utf-8'), 'text/csv')})

lec = fake_session(LEC, 'lecturer')
adm = fake_session(1, 'admin')
try:
    header = 'student_id,subject_code,assignment,midterm,final_exam,attendance\n'
    r = upload(lec, header + f'{CODE},{SCODE},{new_assignment},,,\n')
    check('upload with only one column filled is accepted', r.status_code == 200 and not PHP_ERROR.search(r.text), r.status_code)
    after = marks()
    check('the filled-in mark is saved', float(after[0]) == float(new_assignment), after)
    check('blank cells keep the saved marks', after[1:] == saved[1:], (saved, after))
    log = sql(f"SELECT details FROM audit_logs WHERE id>{audit_max} AND action='CSV Upload'")
    check('audit entry lists only the real change', 'Assignment:' in log and 'Midterm:' not in log and 'Final Exam:' not in log, log)

    r = upload(lec, header + f'{CODE},{SCODE},,,,\n')
    check('an all-blank row changes nothing', marks() == after)

    # Fields sent as arrays (name[]=x) are treated as missing, not a crash.
    tok = csrf(adm.get(f'{B}/admin/manage_subjects.php').text)
    r = adm.post(f'{B}/admin/manage_subjects.php', data={'csrf_token': tok, 'add_subject': '1', 'subject_code[]': 'X', 'subject_name[]': 'Y', 'credit_hours[]': '3'})
    check('array fields on Add Subject: validation message, no 500', r.status_code == 200 and 'Enter a subject code' in r.text, r.status_code)
    tok = csrf(adm.get(f'{B}/admin/manage_semesters.php').text)
    r = adm.post(f'{B}/admin/manage_semesters.php', data={'csrf_token': tok, 'add_semester': '1', 'semester_name[]': 'X', 'start_date[]': '2026-01-01', 'end_date[]': '2026-02-01'})
    check('array fields on Add Semester: validation message, no 500', r.status_code == 200 and 'are required' in r.text, r.status_code)
    tok = csrf(lec.get(f'{B}/auth/change_password.php').text)
    r = lec.post(f'{B}/auth/change_password.php', data={'csrf_token': tok, 'current_password[]': 'x', 'new_password': 'abc12345', 'confirm_password': 'abc12345'})
    check('array field on Change Password: error message, no 500', r.status_code == 200 and 'current password is incorrect' in r.text, r.status_code)
    r = adm.get(f'{B}/admin/audit_logs.php?q=%25')
    check('audit log search treats % literally', r.status_code == 200 and not PHP_ERROR.search(r.text))
finally:
    vals = ', '.join(f"{c} = {'NULL' if v == 'NULL' else v}" for c, v in zip(['assignment', 'midterm', 'final_exam', 'attendance'], saved))
    sql(f"UPDATE performance SET {vals} WHERE enrollment_id={ENR}")
    sql(f"DELETE FROM audit_logs WHERE id > {audit_max}")
    remove_sessions()

finish()
