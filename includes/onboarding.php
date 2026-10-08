<?php
// ── First-login welcome guide (lecturers and students) ──────────────────
// Included at the end of the lecturer and student dashboards, after the
// Bootstrap bundle. Opens automatically the first time an account reaches
// its dashboard, then users.onboarding_done is set so it never opens by
// itself again. "Getting Started" in the sidebar reopens it (?guide=1).
// Requires $conn and a signed-in session.

$onboarding_role = $_SESSION['role'] ?? '';
$onboarding_auto = false;

if (in_array($onboarding_role, ['lecturer', 'student'], true)) {
    try {
        $stmt = mysqli_prepare($conn, "SELECT onboarding_done FROM users WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $onboarding_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($onboarding_row && !$onboarding_row['onboarding_done']) {
            $onboarding_auto = true;
            // Marked as soon as it is shown, so it really is first-time only;
            // the sidebar link covers anyone who closed it too quickly.
            $stmt = mysqli_prepare($conn, "UPDATE users SET onboarding_done = 1 WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } catch (mysqli_sql_exception $e) {
        // Column missing (database/migrations/002_onboarding.sql not run yet):
        // skip the automatic guide rather than breaking the dashboard.
        $onboarding_auto = false;
    }
}

$onboarding_open = $onboarding_auto || isset($_GET['guide']);
if (!$onboarding_open || !in_array($onboarding_role, ['lecturer', 'student'], true)) {
    return;
}

$onboarding_name = explode(' ', trim($_SESSION['full_name'] ?? ''))[0];

// Each step: [icon, title, text, optional link => label]
$onboarding_steps = [
    'lecturer' => [
        ['bi-mortarboard-fill', 'Welcome, ' . $onboarding_name . '!',
            'SPTS helps you record marks and keep track of how your students are doing this semester. Here is a quick tour of what you can do.', null],
        ['bi-grid-1x2', 'Your Dashboard',
            'See how many of your students are passing, at risk or failing. Mentorship requests from students also appear here: approve, reschedule or decline them, and mark sessions complete afterwards.', null],
        ['bi-pencil-square', 'Enter Marks',
            'Pick a subject to see its enrolled students. Enter the four assessment marks for each one. Leave a mark blank if it hasn\'t been assessed yet. The total and status appear once all four are in.', ['enter_marks.php', 'Open Enter Marks']],
        ['bi-cloud-arrow-up', 'CSV Upload',
            'For a whole class at once, download the template (already filled with your students), add the marks in Excel, and upload it. You\'ll get a report of which rows were saved and which were rejected, with reasons.', ['csv_upload.php', 'Open CSV Upload']],
        ['bi-clock-history', 'Audit Trail',
            'Every change you make to marks is recorded with the old and new values, so you can always check what changed and when.', ['audit_trail.php', 'Open Audit Trail']],
    ],
    'student' => [
        ['bi-mortarboard-fill', 'Welcome, ' . $onboarding_name . '!',
            'SPTS lets you follow your results through the semester and get help early if you need it. Here is a quick tour of what you can do.', null],
        ['bi-grid-1x2', 'Your Dashboard',
            'See your results for this semester as your lecturers enter them. A subject shows "Pending" until all of its marks are in.', null],
        ['bi-calculator', 'What-If Calculator',
            'Try different final-exam scores to see what total you would get, and the minimum score you need to pass. It\'s only a calculator: nothing is saved.', ['predict.php', 'Open Calculator']],
        ['bi-people', 'Mentorship',
            'If a subject is going badly, book a session with your lecturer or a peer mentor who did well in it. Follow your bookings under "My Sessions".', ['mentorship.php', 'Open Mentorship']],
        ['bi-file-earmark-text', 'My Transcript',
            'See your grades, GPA and CGPA for every semester. You can print it or save it as a PDF.', ['transcript.php', 'Open Transcript']],
    ],
][$onboarding_role];

$onboarding_steps[] = ['bi-check2-circle', 'You\'re all set',
    'You can open this guide again at any time from "Getting Started" in the menu. Keep your account safe: use "Change Password" if you ever think someone else knows it.', null];
$onboarding_total = count($onboarding_steps);
?>
<style>
    .onboarding-modal .modal-content { border: none; border-radius: 16px; overflow: hidden; }
    .onboarding-step { display: none; text-align: center; padding: 8px 8px 0; }
    .onboarding-step.active { display: block; animation: onboarding-in 0.25s ease-out; }
    .onboarding-icon {
        width: 64px; height: 64px; margin: 0 auto 16px;
        border-radius: 50%; background: #E6F4F4; color: #0D7377;
        display: flex; align-items: center; justify-content: center; font-size: 1.8rem;
    }
    .onboarding-step h5 { font-weight: 700; color: #1A3A5C; margin-bottom: 10px; }
    .onboarding-step p { color: #475569; font-size: 0.92rem; line-height: 1.6; margin-bottom: 12px; }
    .onboarding-dots { display: flex; justify-content: center; gap: 6px; margin: 18px 0 4px; }
    .onboarding-dots span { width: 8px; height: 8px; border-radius: 50%; background: #CBD5E1; transition: all 0.2s; }
    .onboarding-dots span.active { background: #0D7377; width: 22px; border-radius: 4px; }
    .onboarding-link { color: #0D7377; font-weight: 600; font-size: 0.85rem; text-decoration: none; }
    .onboarding-link:hover { text-decoration: underline; color: #0a5e62; }
    @keyframes onboarding-in { from { opacity: 0; transform: translateX(12px); } to { opacity: 1; transform: none; } }
    [data-bs-theme="dark"] .onboarding-icon { background: rgba(13,115,119,0.2); color: #5EEAD4; }
    [data-bs-theme="dark"] .onboarding-step h5 { color: #E2E8F0; }
    [data-bs-theme="dark"] .onboarding-step p { color: #CBD5E1; }
    [data-bs-theme="dark"] .onboarding-dots span { background: #475569; }
    [data-bs-theme="dark"] .onboarding-link { color: #5EEAD4; }
</style>

<div class="modal fade onboarding-modal" id="onboardingModal" tabindex="-1" aria-labelledby="onboardingTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <span class="text-muted small" id="onboardingTitle">Getting Started · <span id="onboardingCount">1</span> of <?php echo $onboarding_total; ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close guide"></button>
            </div>
            <div class="modal-body px-4">
                <?php foreach ($onboarding_steps as $i => [$icon, $title, $text, $link]): ?>
                    <div class="onboarding-step<?php echo $i === 0 ? ' active' : ''; ?>">
                        <div class="onboarding-icon"><i class="bi <?php echo $icon; ?>" aria-hidden="true"></i></div>
                        <h5><?php echo h($title); ?></h5>
                        <p><?php echo h($text); ?></p>
                        <?php if ($link): ?>
                            <a href="<?php echo $link[0]; ?>" class="onboarding-link"><?php echo h($link[1]); ?> <i class="bi bi-arrow-right"></i></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="onboarding-dots" aria-hidden="true">
                    <?php for ($i = 0; $i < $onboarding_total; $i++): ?>
                        <span<?php echo $i === 0 ? ' class="active"' : ''; ?>></span>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-between">
                <button type="button" class="btn btn-link text-muted text-decoration-none" data-bs-dismiss="modal" id="onboardingSkip">Skip tour</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="onboardingBack" style="display:none;">Back</button>
                    <button type="button" class="btn btn-primary-custom px-4" id="onboardingNext">Next</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('onboardingModal');
    const steps   = modalEl.querySelectorAll('.onboarding-step');
    const dots    = modalEl.querySelectorAll('.onboarding-dots span');
    const back    = document.getElementById('onboardingBack');
    const next    = document.getElementById('onboardingNext');
    const skip    = document.getElementById('onboardingSkip');
    const count   = document.getElementById('onboardingCount');
    const modal   = new bootstrap.Modal(modalEl);
    let current = 0;

    function show(i) {
        current = i;
        steps.forEach((s, n) => s.classList.toggle('active', n === i));
        dots.forEach((d, n) => d.classList.toggle('active', n === i));
        count.textContent = i + 1;
        const last = i === steps.length - 1;
        back.style.display = i === 0 ? 'none' : '';
        skip.style.visibility = last ? 'hidden' : '';
        next.textContent = last ? 'Get started' : 'Next';
    }

    next.addEventListener('click', function () {
        if (current === steps.length - 1) modal.hide();
        else show(current + 1);
    });
    back.addEventListener('click', function () { show(current - 1); });

    // Opened from the sidebar link: tidy the address bar so a refresh
    // doesn't open the guide again.
    if (window.history.replaceState && location.search.indexOf('guide') !== -1) {
        history.replaceState(null, '', location.pathname);
    }

    modal.show();
})();
</script>
