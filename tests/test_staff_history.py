"""Deleting a lecturer keeps their teaching record (migration 017), and a
semester with such history-only rows still activates (local XAMPP)."""
from helpers import *

B = BASE

SEM = int(sql("SELECT id FROM semesters WHERE is_active=1"))
audit_max = int(sql("SELECT COALESCE(MAX(id),0) FROM audit_logs"))
adm = fake_session(1, 'admin')
past = nxt = sub = lec = 0
try:
    sql("INSERT INTO semesters (semester_name, start_date, end_date, is_active) VALUES ('ZZ History Past', '2020-01-06', '2020-05-01', 0)")
    past = int(sql("SELECT id FROM semesters WHERE semester_name='ZZ History Past'"))
    sql("INSERT INTO subjects (subject_code, subject_name) VALUES ('ZZHIST01', 'ZZ History Test')")
    sub = int(sql("SELECT id FROM subjects WHERE subject_code='ZZHIST01'"))
    sql("INSERT INTO users (full_name, email, password_hash, role) VALUES ('ZZ History Lecturer', 'zz.history@test.local', 'x', 'lecturer')")
    lec = int(sql("SELECT id FROM users WHERE email='zz.history@test.local'"))
    sql(f"INSERT INTO lecturer_subjects (user_id, subject_id, semester_id) VALUES ({lec}, {sub}, {past}), ({lec}, {sub}, {SEM})")

    page = adm.get(f'{B}/admin/manage_lecturers.php')
    r = adm.post(f'{B}/admin/manage_lecturers.php', data={'csrf_token': csrf(page.text), 'delete_lecturer': '1', 'delete_id': lec})
    check('lecturer deleted through Staff page', sql(f"SELECT COUNT(*) FROM users WHERE id={lec}") == '0' and 'teaching' in r.text)
    rows = sql(f"SELECT semester_id, IFNULL(user_id,'NULL'), former_lecturer_name FROM lecturer_subjects WHERE subject_id={sub} ORDER BY semester_id").splitlines()
    check('teaching record kept for every semester, with the name', sorted(rows) == sorted([f'{past}\tNULL\tZZ History Lecturer', f'{SEM}\tNULL\tZZ History Lecturer']), rows)
    r = adm.get(f'{B}/admin/manage_subjects.php')
    check('Subjects page loads; the subject shows no lecturer', r.status_code == 200 and not PHP_ERROR.search(r.text) and 'ZZ History Lecturer' not in r.text)
    check('deletion recorded in audit log', 'ZZ History Lecturer' in sql(f"SELECT details FROM audit_logs WHERE id>{audit_max} AND action='Staff account deleted'"))

    bad = sql_may_fail(f"INSERT INTO lecturer_subjects (user_id, subject_id, semester_id) VALUES (NULL, {sub}, {past + 0})")
    check('a new assignment without a lecturer is refused', bad.returncode != 0)

    # Activating the next semester copies real assignments only.
    real = int(sql(f"SELECT COUNT(*) FROM lecturer_subjects WHERE semester_id={SEM} AND user_id IS NOT NULL"))
    sql("INSERT INTO semesters (semester_name, start_date, end_date, is_active) VALUES ('ZZ History Next', '2031-06-02', '2031-10-03', 0)")
    nxt = int(sql("SELECT id FROM semesters WHERE semester_name='ZZ History Next'"))
    page = adm.get(f'{B}/admin/manage_semesters.php')
    r = adm.post(f'{B}/admin/manage_semesters.php', data={'csrf_token': csrf(page.text), 'activate_id': nxt})
    check('semester with history-only rows still activates', int(sql("SELECT id FROM semesters WHERE is_active=1")) == nxt and not PHP_ERROR.search(r.text))
    check(f'only real assignments carried over ({real})', int(sql(f"SELECT COUNT(*) FROM lecturer_subjects WHERE semester_id={nxt}")) == real
          and sql(f"SELECT COUNT(*) FROM lecturer_subjects WHERE semester_id={nxt} AND user_id IS NULL") == '0')
    page = adm.get(f'{B}/admin/manage_semesters.php')
    adm.post(f'{B}/admin/manage_semesters.php', data={'csrf_token': csrf(page.text), 'activate_id': SEM})
    check('original semester active again', int(sql("SELECT id FROM semesters WHERE is_active=1")) == SEM)
finally:
    sql(f"UPDATE semesters SET is_active = (id = {SEM})")
    if nxt:
        sql(f"DELETE FROM lecturer_subjects WHERE semester_id={nxt}; DELETE FROM assessment_weights WHERE semester_id={nxt}; DELETE FROM semesters WHERE id={nxt}")
    if sub:
        sql(f"DELETE FROM subjects WHERE id={sub}")
    if past:
        sql(f"DELETE FROM lecturer_subjects WHERE semester_id={past}; DELETE FROM semesters WHERE id={past}")
    sql("DELETE FROM users WHERE email='zz.history@test.local'")
    sql(f"DELETE FROM audit_logs WHERE id > {audit_max}")
    remove_sessions()

finish()
