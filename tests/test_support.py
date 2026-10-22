"""End-to-end tests for Contact Admin / support requests against local XAMPP."""
import os, re, subprocess, requests
from helpers import *

B = BASE

STU, LEC, ADM = 23, 20, 1

stu_email, stu_id, stu_name = sql(f"SELECT u.email, s.student_id, u.full_name FROM users u JOIN students s ON s.user_id=u.id WHERE u.id={STU}").split('\t')
lec_email, lec_name = sql(f"SELECT email, full_name FROM users WHERE id={LEC}").split('\t')
saved = {uid: sql(f"SELECT password_hash, must_change_password FROM users WHERE id={uid}").split('\t') for uid in (STU, LEC)}
max_id = int(sql("SELECT COALESCE(MAX(id),0) FROM support_requests"))
audit_max = int(sql("SELECT COALESCE(MAX(id),0) FROM audit_logs"))
sql("DELETE FROM login_attempts")

def count_new():
    return int(sql(f"SELECT COUNT(*) FROM support_requests WHERE id > {max_id}"))

try:
    # ── Public page & entry points ─────────────────────────────────────
    anon = requests.Session()
    r = anon.get(f'{B}/auth/login.php')
    check('student login links to contact_admin (forgot)', 'contact_admin.php?topic=forgot_password"' in r.text and 'Forgot password?' in r.text)
    check('student login has Contact the admin link', 'contact_admin.php?topic=cannot_login"' in r.text)
    r = anon.get(f'{B}/auth/login.php?portal=staff')
    check('staff login links to contact_admin with portal=staff', 'contact_admin.php?topic=forgot_password&amp;portal=staff' in r.text)
    r = anon.get(f'{B}/auth/forgot_password.php?portal=staff', allow_redirects=False)
    check('old forgot_password redirects', r.status_code == 301 and r.headers['Location'] == 'contact_admin.php?topic=forgot_password&portal=staff')
    r = anon.get(f'{B}/auth/contact_admin.php?topic=forgot_password&portal=staff')
    check('public page loads without sign-in', r.status_code == 200 and 'id="supportForm"' in r.text)
    check('staff portal preselects Lecturer', re.search(r'id="roleLecturer" value="lecturer" autocomplete="off" checked', r.text) is not None)
    check('page never asks for a password', 'type="password"' not in r.text)
    for cat in ('Forgot password', 'Cannot log in', 'Other'):
        check(f'category option: {cat}', f'>{cat}</option>' in r.text)

    # ── Validation ──────────────────────────────────────────────────────
    r = anon.get(f'{B}/auth/contact_admin.php')
    tok = csrf(r.text)
    r = anon.post(f'{B}/auth/contact_admin.php', data={'csrf_token': tok, 'role': 'student', 'full_name': '', 'id_number': 'x12', 'email': 'not-an-email', 'category': 'other', 'message': 'short'})
    check('invalid form: error shown', 'Please correct the highlighted fields.' in r.text)
    check('invalid form: fields flagged', all(f'name="{f}" id="{f}" class="form-control is-invalid' in r.text for f in ('full_name', 'id_number', 'email')))
    check('invalid form: Student ID rule', 'Student ID must be the letter n' in r.text)
    check('invalid form: message too short for Other', 'at least 10 characters' in r.text)
    check('invalid form: input kept', 'value="x12"' in r.text)
    r = anon.post(f'{B}/auth/contact_admin.php', data={'csrf_token': tok, 'role': 'admin', 'full_name': 'X', 'id_number': 'n0002111', 'email': 'a@b.co', 'category': 'hack', 'message': ''})
    check('invalid role/category rejected', 'Choose whether you are a student or a lecturer.' in r.text and 'Choose what you need help with.' in r.text)
    r = anon.post(f'{B}/auth/contact_admin.php', data={'csrf_token': tok, 'role': 'student', 'full_name': 'A' * 50, 'id_number': 'n00021963', 'email': 'a@b.co', 'category': 'other', 'message': 'x' * 1001})
    check('message over 1000 chars rejected', 'at most 1000 characters' in r.text)
    r = anon.post(f'{B}/auth/contact_admin.php', data={'csrf_token': 'bad', 'role': 'student', 'full_name': 'A B', 'id_number': 'n00021963', 'email': 'a@b.co', 'category': 'forgot_password'})
    check('bad CSRF rejected', 'Your session expired' in r.text)
    r = anon.post(f'{B}/auth/contact_admin.php', data={'csrf_token': tok, 'leave_blank': 'http://spam', 'role': 'student', 'full_name': 'A B', 'id_number': 'n00021963', 'email': 'a@b.co', 'category': 'forgot_password'})
    check('honeypot rejected without success', 'could not be sent' in r.text and 'Your reference number' not in r.text)
    check('nothing saved by invalid posts', count_new() == 0)

    # ── Valid public requests ──────────────────────────────────────────
    def public_submit(sess, data, portal=''):
        page = sess.get(f'{B}/auth/contact_admin.php{portal}')
        data = dict(data, csrf_token=csrf(page.text))
        return sess.post(f'{B}/auth/contact_admin.php{portal}', data=data, allow_redirects=False)

    r = public_submit(anon, {'role': 'student', 'full_name': stu_name.upper(), 'id_number': stu_id.upper(), 'email': stu_email, 'category': 'forgot_password', 'message': ''})
    check('valid student request redirects (PRG)', r.status_code == 303 and 'sent=1' in r.headers.get('Location', ''))
    page = anon.get(f'{B}/auth/' + r.headers['Location'])
    ref1 = re.search(r'(SR-[A-Z0-9]{8})', page.text)
    check('confirmation shows reference number', ref1 is not None and 'Your request has been sent' in page.text)
    ref1 = ref1.group(1) if ref1 else ''
    row = sql(f"SELECT role, id_number, email, category, status, user_id IS NULL FROM support_requests WHERE reference_no='{ref1}'").split('\t')
    check('student request saved (ID normalised, Open, no user_id)', row == ['student', stu_id.lower(), stu_email, 'forgot_password', 'Open', '1'], str(row))
    check('refresh does not resend', anon.get(f'{B}/auth/contact_admin.php?sent=1').text.count('SR-') == 0 and count_new() == 1)

    other = requests.Session()
    r = public_submit(other, {'role': 'student', 'full_name': 'Nobody Here', 'id_number': 'n00099999', 'email': 'nobody-xyz@example.com', 'category': 'forgot_password', 'message': ''})
    page2 = other.get(f'{B}/auth/' + r.headers['Location']).text
    strip = lambda t: re.sub(r'SR-[A-Z0-9]{8}', 'SR-X', re.sub(r'name="csrf_token" value="[^"]+"', '', t))
    check('unknown account gets identical response', r.status_code == 303 and strip(page2) == strip(page.text))
    ref_unknown = re.search(r'(SR-[A-Z0-9]{8})', page2).group(1)

    lecs = requests.Session()
    r = public_submit(lecs, {'role': 'lecturer', 'full_name': lec_name, 'id_number': 'NU-1234', 'email': lec_email, 'category': 'cannot_login', 'message': 'My account says the password is wrong.'}, '?portal=staff')
    check('valid lecturer request saved', r.status_code == 303 and 'portal=staff' in r.headers['Location'])
    ref_lec = re.search(r'(SR-[A-Z0-9]{8})', lecs.get(f'{B}/auth/' + r.headers['Location']).text).group(1)

    # Throttle: 3 per email+network per window.
    spam = requests.Session()
    def spam_submit():
        r = public_submit(spam, {'role': 'student', 'full_name': 'Spam Bot', 'id_number': 'n00011111', 'email': 'spam@example.com', 'category': 'other', 'message': 'spam spam spam'})
        if r.status_code == 303: spam.get(f'{B}/auth/' + r.headers['Location'])   # shows (and clears) the confirmation
        return r.status_code
    codes = [spam_submit() for _ in range(4)]
    check('rate limit: 4th request from same email blocked', codes[:3] == [303, 303, 303] and codes[3] == 200, str(codes))
    last = spam.get(f'{B}/auth/contact_admin.php')
    tok = csrf(last.text)
    blocked = spam.post(f'{B}/auth/contact_admin.php', data={'csrf_token': tok, 'role': 'student', 'full_name': 'Spam Bot', 'id_number': 'n00011111', 'email': 'spam@example.com', 'category': 'other', 'message': 'spam spam spam'})
    check('rate limit message shown, not success', 'Too many requests' in blocked.text and 'Your reference number' not in blocked.text)
    sql("DELETE FROM support_requests WHERE email='spam@example.com'")

    # ── Signed-in Contact Admin ────────────────────────────────────────
    s_stu = fake_session(STU, 'student', stu_name)
    s_lec = fake_session(LEC, 'lecturer', lec_name)
    s_adm = fake_session(ADM, 'admin', 'Admin')

    r = s_stu.get(f'{B}/student/student_dashboard.php')
    check('student sidebar has Contact Admin', 'href="contact_admin.php"' in r.text and 'Contact Admin' in r.text)
    r = s_lec.get(f'{B}/lecturer/lecturer_dashboard.php')
    check('lecturer sidebar has Contact Admin', 'href="contact_admin.php"' in r.text)
    r = s_stu.get(f'{B}/auth/contact_admin.php', allow_redirects=False)
    check('signed-in user sent to own Contact Admin', r.status_code == 302 and r.headers['Location'] == '../student/contact_admin.php')

    r = s_stu.get(f'{B}/student/contact_admin.php')
    check('student contact page shows account details', r.status_code == 200 and stu_email in r.text and stu_id in r.text)
    tok = csrf(r.text)
    r = s_stu.post(f'{B}/student/contact_admin.php', data={'csrf_token': tok, 'category': 'other', 'message': 'hi'})
    check('signed-in short message rejected', 'at least 10 characters' in r.text and count_new() == 3)
    r = s_stu.post(f'{B}/student/contact_admin.php', data={'csrf_token': tok, 'category': 'other', 'message': 'My transcript is missing a subject.', 'email': 'evil@example.com', 'role': 'lecturer'}, allow_redirects=False)
    check('signed-in request redirects', r.status_code == 303)
    r = s_stu.get(f'{B}/student/contact_admin.php')
    ref_stu = re.search(r'Reference: (SR-[A-Z0-9]{8})', r.text)
    check('signed-in confirmation with reference', ref_stu is not None)
    ref_stu = ref_stu.group(1) if ref_stu else ''
    row = sql(f"SELECT user_id, email, role FROM support_requests WHERE reference_no='{ref_stu}'").split('\t')
    check('identity taken from account, not form', row == [str(STU), stu_email, 'student'], str(row))
    check('My Requests lists it', ref_stu in s_stu.get(f'{B}/student/contact_admin.php').text)

    r = s_lec.get(f'{B}/lecturer/contact_admin.php')
    tok = csrf(r.text)
    s_lec.post(f'{B}/lecturer/contact_admin.php', data={'csrf_token': tok, 'category': 'other', 'message': 'Please add me to WEB3013 for this semester.'})
    check('lecturer signed-in request saved', sql(f"SELECT COUNT(*) FROM support_requests WHERE user_id={LEC} AND id>{max_id}") == '1')
    check('lecturer does not see student requests', ref_stu not in s_lec.get(f'{B}/lecturer/contact_admin.php').text)

    # ── Access restrictions ────────────────────────────────────────────
    for who, sess in (('anonymous', requests.Session()), ('student', s_stu), ('lecturer', s_lec)):
        r = sess.get(f'{B}/admin/support_requests.php', allow_redirects=False)
        check(f'{who} cannot open admin Support Requests', r.status_code == 302 and 'support_requests' not in r.headers['Location'])
    r = s_stu.get(f'{B}/lecturer/contact_admin.php', allow_redirects=False)
    check('student cannot open lecturer contact page', r.status_code == 302)
    r = requests.get(f'{B}/student/contact_admin.php', allow_redirects=False)
    check('anonymous cannot open signed-in contact page', r.status_code == 302 and 'login.php' in r.headers['Location'])
    rid1 = sql(f"SELECT id FROM support_requests WHERE reference_no='{ref1}'")
    r = s_stu.post(f'{B}/admin/support_requests.php', data={'request_id': rid1, 'issue_password': '1', 'identity_verified': '1', 'verify_method': 'in_person', 'account_id': STU}, allow_redirects=False)
    check('student cannot POST a reset', r.status_code == 302 and sql(f"SELECT password_hash FROM users WHERE id={STU}") == saved[STU][0])

    # ── Admin ──────────────────────────────────────────────────────────
    r = s_adm.get(f'{B}/admin/admin_dashboard.php')
    check('admin dashboard has Support Requests section', 'Support Requests' in r.text and ref1 in r.text)
    check('sidebar badge for open requests', re.search(r'href="support_requests.php".*?badge[^>]*>(\d+)<', r.text, re.S) is not None)
    r = s_adm.get(f'{B}/admin/password_requests.php', allow_redirects=False)
    check('old Password Requests redirects', r.status_code == 301 and 'support_requests.php' in r.headers['Location'])
    r = s_adm.get(f'{B}/admin/support_requests.php')
    check('admin list shows requests', all(x in r.text for x in (ref1, ref_lec, ref_stu, ref_unknown)))
    r = s_adm.get(f'{B}/admin/support_requests.php?role=lecturer')
    check('role filter', ref_lec in r.text and ref1 not in r.text and ref_stu not in r.text)
    r = s_adm.get(f'{B}/admin/support_requests.php?category=forgot_password&role=student')
    check('category filter', ref1 in r.text and ref_stu not in r.text and ref_lec not in r.text)
    r = s_adm.get(f'{B}/admin/support_requests.php?q={ref_lec}')
    check('search by reference', ref_lec in r.text and ref1 not in r.text)

    # Detail + account check
    r = s_adm.get(f'{B}/admin/support_requests.php?id={rid1}')
    check('detail shows account check', 'Account Check' in r.text and 'Student ID matches' in r.text and 'Name matches' in r.text)
    check('reset form offered for matched forgot-password request', 'name="issue_password"' in r.text)
    rid_unknown = sql(f"SELECT id FROM support_requests WHERE reference_no='{ref_unknown}'")
    r = s_adm.get(f'{B}/admin/support_requests.php?id={rid_unknown}')
    check('no reset for unmatched email', 'No account uses' in r.text and 'name="issue_password"' not in r.text)
    tok = csrf(r.text)
    r = s_adm.post(f'{B}/admin/support_requests.php?id={rid_unknown}', data={'csrf_token': tok, 'request_id': rid_unknown, 'issue_password': '1', 'identity_verified': '1', 'verify_method': 'in_person', 'account_id': STU})
    check('forged reset on unmatched request refused', 'be used to reset an account' in r.text and sql(f"SELECT password_hash FROM users WHERE id={STU}") == saved[STU][0])
    rid_stu = sql(f"SELECT id FROM support_requests WHERE reference_no='{ref_stu}'")
    r = s_adm.post(f'{B}/admin/support_requests.php', data={'csrf_token': tok, 'request_id': rid_stu, 'issue_password': '1', 'identity_verified': '1', 'verify_method': 'in_person', 'account_id': STU})
    check("reset refused for 'Other' category", 'be used to reset an account' in r.text)

    r = s_adm.post(f'{B}/admin/support_requests.php?id={rid1}', data={'csrf_token': tok, 'request_id': rid1, 'issue_password': '1', 'verify_method': 'in_person', 'account_id': STU})
    check('reset without identity confirmation refused', 'Confirm that you verified' in r.text and sql(f"SELECT password_hash FROM users WHERE id={STU}") == saved[STU][0])
    r = s_adm.post(f'{B}/admin/support_requests.php?id={rid1}', data={'csrf_token': tok, 'request_id': rid1, 'issue_password': '1', 'identity_verified': '1', 'verify_method': 'made_up', 'account_id': STU})
    check('reset with invalid method refused', 'Confirm that you verified' in r.text)
    r = s_adm.post(f'{B}/admin/support_requests.php?id={rid1}', data={'csrf_token': tok, 'request_id': rid1, 'issue_password': '1', 'identity_verified': '1', 'verify_method': 'in_person', 'account_id': 999999})
    check('reset with wrong account id refused', 'matching account changed' in r.text)
    r = s_adm.post(f'{B}/admin/support_requests.php?id={rid1}', data={'csrf_token': 'bad', 'request_id': rid1, 'issue_password': '1', 'identity_verified': '1', 'verify_method': 'in_person', 'account_id': STU})
    check('reset with bad CSRF refused', 'session expired' in r.text and sql(f"SELECT password_hash FROM users WHERE id={STU}") == saved[STU][0])

    r = s_adm.post(f'{B}/admin/support_requests.php?id={rid1}', data={'csrf_token': tok, 'request_id': rid1, 'issue_password': '1', 'identity_verified': '1', 'verify_method': 'in_person', 'account_id': STU})
    temp = re.search(r'<code class="fs-5 ms-2 user-select-all">([^<]+)</code>', r.text)
    check('temporary password shown once to admin', temp is not None and 'shown only once' in r.text)
    temp = temp.group(1) if temp else ''
    check('temporary password not shown again on reload', temp and temp not in s_adm.get(f'{B}/admin/support_requests.php?id={rid1}').text)
    row = sql(f"SELECT status, handled_by, resolved_at IS NOT NULL FROM support_requests WHERE id={rid1}").split('\t')
    check('request resolved by this admin', row == ['Resolved', str(ADM), '1'], str(row))
    check('must_change_password set', sql(f"SELECT must_change_password FROM users WHERE id={STU}") == '1')
    check('audit log records reset and verification method', 'Identity verified: In person' in sql(f"SELECT details FROM audit_logs WHERE id>{audit_max} AND action='Password reset by admin'"))
    check('temp password not stored in plain text', temp not in sql(f"SELECT CONCAT_WS('|', password_hash) FROM users WHERE id={STU}") and temp not in sql(f"SELECT COALESCE(GROUP_CONCAT(details),'') FROM audit_logs WHERE id>{audit_max}") and temp not in sql(f"SELECT COALESCE(admin_note,'') FROM support_requests WHERE id={rid1}"))
    r = s_adm.post(f'{B}/admin/support_requests.php?id={rid1}', data={'csrf_token': tok, 'request_id': rid1, 'issue_password': '1', 'identity_verified': '1', 'verify_method': 'in_person', 'account_id': STU})
    check('resolved request cannot reset again', 'be used to reset an account' in r.text)

    # The old session of that student is signed out (password changed).
    r = s_stu.get(f'{B}/student/student_dashboard.php', allow_redirects=False)
    check("student's existing session ended after reset", r.status_code == 302 and 'login.php' in r.headers['Location'])

    # Recovery: sign in with the temporary password → forced change.
    rec = requests.Session()
    page = rec.get(f'{B}/auth/login.php')
    r = rec.post(f'{B}/auth/login.php', data={'csrf_token': csrf(page.text), 'portal': 'student', 'email': stu_email, 'password': temp}, allow_redirects=False)
    check('temporary password signs in', r.status_code in (302, 303), str(r.status_code))
    r = rec.get(f'{B}/student/student_dashboard.php', allow_redirects=False)
    check('must change password before using the system', r.status_code == 302 and 'change_password.php' in r.headers['Location'])
    page = rec.get(f'{B}/auth/change_password.php')
    form_fields = re.findall(r'name="([a-z_]+)"', page.text)
    new_pw = 'Recover3dPass'
    data = {'csrf_token': csrf(page.text)}
    for f in form_fields:
        if 'current' in f or f == 'old_password': data[f] = temp
        elif 'confirm' in f: data[f] = new_pw
        elif 'new' in f: data[f] = new_pw
    rec.post(f'{B}/auth/change_password.php', data=data)
    check('user sets own password, flag cleared', sql(f"SELECT must_change_password FROM users WHERE id={STU}") == '0', str(form_fields))
    r = rec.get(f'{B}/student/student_dashboard.php', allow_redirects=False)
    check('dashboard reachable after change', r.status_code == 200)

    # Status workflow + note visible to signed-in requester
    rid_lec_si = sql(f"SELECT id FROM support_requests WHERE user_id={LEC} AND id>{max_id}")
    r = s_adm.post(f'{B}/admin/support_requests.php?id={rid_lec_si}', data={'csrf_token': tok, 'request_id': rid_lec_si, 'update_status': '1', 'status': 'In Progress', 'admin_note': 'Checking with the programme office.'}, allow_redirects=False)
    check('status update redirects', r.status_code == 303)
    row = sql(f"SELECT status, admin_note, resolved_at IS NULL FROM support_requests WHERE id={rid_lec_si}").split('\t')
    check('status In Progress saved with note', row == ['In Progress', 'Checking with the programme office.', '1'], str(row))
    check('lecturer sees status and note', 'In Progress' in s_lec.get(f'{B}/lecturer/contact_admin.php').text and 'Checking with the programme office.' in s_lec.get(f'{B}/lecturer/contact_admin.php').text)
    s_adm.post(f'{B}/admin/support_requests.php?id={rid_lec_si}', data={'csrf_token': tok, 'request_id': rid_lec_si, 'update_status': '1', 'status': 'Resolved', 'admin_note': 'Done.'})
    check('Resolved sets resolved_at', sql(f"SELECT status, resolved_at IS NOT NULL FROM support_requests WHERE id={rid_lec_si}") == 'Resolved\t1')
    r = s_adm.post(f'{B}/admin/support_requests.php?id={rid_lec_si}', data={'csrf_token': tok, 'request_id': rid_lec_si, 'update_status': '1', 'status': 'Closed'})
    check('invalid status rejected', 'Choose a valid status' in r.text)
    r = s_adm.get(f'{B}/admin/support_requests.php?status=Resolved')
    check('status filter Resolved', ref1 in r.text and ref_lec not in r.text)
    r = s_adm.get(f'{B}/admin/support_requests.php')
    check('default list hides resolved', ref1 not in r.text and ref_lec in r.text)

    # Lecturer public request: role mismatch shows no reset
    rid_lec = sql(f"SELECT id FROM support_requests WHERE reference_no='{ref_lec}'")
    r = s_adm.get(f'{B}/admin/support_requests.php?id={rid_lec}')
    check("lecturer request: staff ID can't be checked, reset offered", "Staff ID can't be checked" in r.text and 'name="issue_password"' in r.text)
    sql(f"UPDATE support_requests SET role='student' WHERE id={rid_lec}")
    r = s_adm.get(f'{B}/admin/support_requests.php?id={rid_lec}')
    check('role mismatch: no reset form', 'name="issue_password"' not in r.text and 'Role does not match' in r.text)
finally:
    for uid, (h, m) in saved.items():
        sql(f"UPDATE users SET password_hash='{h}', must_change_password={m} WHERE id={uid}")
    sql(f"DELETE FROM support_requests WHERE id > {max_id}")
    sql(f"DELETE FROM audit_logs WHERE id > {audit_max}")
    sql("DELETE FROM login_attempts")
    remove_sessions()

finish()
