<?php
// ── A student's progress across semesters ───────────────────────────────
// GPA per semester and cumulative GPA (CGPA), the same rules as the
// transcript, plus the average total and pass/fail counts. Shown as a
// trend on the student dashboard and on the admin's View Student page.
//
// GPA = Σ(grade point × credit hours) ÷ Σ(credit hours of graded subjects).
// A subject without all four marks is "Grades Pending": it is left out of
// the GPA, never counted as 0.

require_once __DIR__ . '/grading.php';

// Letter grade and grade point for a total (transcript scale).
function grade_from_score($score) {
    if ($score >= 90) return ['A+', 4.00];
    if ($score >= 80) return ['A',  4.00];
    if ($score >= 75) return ['A-', 3.70];
    if ($score >= 70) return ['B+', 3.50];
    if ($score >= 65) return ['B',  3.00];
    if ($score >= 60) return ['B-', 2.75];
    if ($score >= 55) return ['C+', 2.50];
    if ($score >= 50) return ['C',  2.00];
    if ($score >= 45) return ['D+', 1.50];
    if ($score >= 40) return ['D',  1.00];
    return ['F', 0.00];
}

// One entry per semester the student was enrolled in, oldest first:
// ['semester_id', 'name', 'enrolled', 'graded', 'passed', 'failed',
//  'credits', 'gpa', 'cgpa', 'avg_score'] (gpa/cgpa/avg_score null until
// something is graded).
function student_semester_history($conn, $student_db_id) {
    $stmt = mysqli_prepare($conn, "SELECT sem.id AS semester_id, sem.semester_name, sub.credit_hours, pr.total_score, pr.result_status
                                   FROM student_enrollments e
                                   JOIN semesters sem ON sem.id = e.semester_id
                                   JOIN subjects sub ON sub.id = e.subject_id
                                   LEFT JOIN predictions pr ON pr.student_id = e.student_id
                                        AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id
                                   WHERE e.student_id = ?
                                   ORDER BY sem.start_date, sem.id");
    mysqli_stmt_bind_param($stmt, "i", $student_db_id);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $history = [];
    foreach ($rows as $r) {
        $sid = (int)$r['semester_id'];
        $history[$sid] ??= ['semester_id' => $sid, 'name' => $r['semester_name'], 'enrolled' => 0, 'graded' => 0,
                            'passed' => 0, 'failed' => 0, 'credits' => 0.0, 'points' => 0.0, 'score_sum' => 0.0];
        $h = &$history[$sid];
        $h['enrolled']++;
        if ($r['total_score'] !== null) {
            $credits = (float)$r['credit_hours'];
            $h['graded']++;
            $h['credits']   += $credits;
            $h['points']    += grade_from_score((float)$r['total_score'])[1] * $credits;
            $h['score_sum'] += (float)$r['total_score'];
            $h[$r['result_status'] === 'Fail' ? 'failed' : 'passed']++;
        }
        unset($h);
    }

    $cum_credits = $cum_points = 0.0;
    foreach ($history as &$h) {
        $cum_credits += $h['credits'];
        $cum_points  += $h['points'];
        $h['gpa']       = $h['credits'] > 0 ? round($h['points'] / $h['credits'], 2) : null;
        $h['cgpa']      = $cum_credits > 0 ? round($cum_points / $cum_credits, 2) : null;
        $h['avg_score'] = $h['graded'] > 0 ? round($h['score_sum'] / $h['graded'], 2) : null;
        unset($h['points'], $h['score_sum']);
    }
    unset($h);
    return array_values($history);
}

// "▲ 0.25" / "▼ 0.10" / "—" against the previous graded semester.
function progress_change_html($current, $previous) {
    if ($current === null || $previous === null) {
        return '<span class="text-muted">—</span>';
    }
    $diff = round($current - $previous, 2);
    if ($diff == 0) {
        return '<span class="text-muted">no change</span>';
    }
    return $diff > 0
        ? '<span class="text-success fw-semibold"><i class="bi bi-arrow-up-short"></i>' . number_format($diff, 2) . '</span>'
        : '<span class="text-danger fw-semibold"><i class="bi bi-arrow-down-short"></i>' . number_format(abs($diff), 2) . '</span>';
}

// The progress card: a GPA/CGPA trend chart (once two semesters have
// grades) and a table of every semester. $canvas_id must be unique on
// the page; call progress_chart_script() later with the same id.
function render_progress_card(array $history, $canvas_id, $title = 'Progress Across Semesters') {
    $graded = array_values(array_filter($history, fn($h) => $h['gpa'] !== null));
    ?>
    <div class="dashboard-card">
        <div class="card-header-title"><i class="bi bi-graph-up-arrow text-primary"></i><?php echo h($title); ?></div>
        <?php if (!$history): ?>
            <div class="empty-state"><i class="bi bi-graph-up"></i>No enrolments yet.</div>
        <?php else: ?>
        <div class="row g-4">
            <div class="<?php echo count($graded) >= 2 ? 'col-lg-7' : 'd-none'; ?>">
                <div style="position:relative; height:260px;">
                    <canvas id="<?php echo h($canvas_id); ?>" role="img" aria-label="GPA and cumulative GPA for each semester"></canvas>
                </div>
            </div>
            <div class="<?php echo count($graded) >= 2 ? 'col-lg-5' : 'col-12'; ?>">
                <div class="table-responsive">
                    <table class="table table-sm mb-0" style="font-size:0.84rem;">
                        <thead><tr><th>Semester</th><th class="text-center">Graded</th><th class="text-end">Average</th><th class="text-end">GPA</th><th class="text-end">CGPA</th></tr></thead>
                        <tbody>
                        <?php $prev = null; foreach ($history as $h): ?>
                            <tr>
                                <td><?php echo h($h['name']); ?><?php if ($h['failed']): ?> <span class="badge-status badge-status-fail ms-1" title="Subjects failed"><?php echo (int)$h['failed']; ?> failed</span><?php endif; ?></td>
                                <td class="text-center"><?php echo (int)$h['graded']; ?>/<?php echo (int)$h['enrolled']; ?></td>
                                <td class="text-end"><?php echo $h['avg_score'] === null ? '—' : number_format($h['avg_score'], 1) . '%'; ?></td>
                                <td class="text-end"><strong><?php echo $h['gpa'] === null ? '—' : number_format($h['gpa'], 2); ?></strong>
                                    <div class="text-nowrap" style="font-size:0.72rem;"><?php echo progress_change_html($h['gpa'], $prev); ?></div></td>
                                <td class="text-end"><?php echo $h['cgpa'] === null ? '—' : number_format($h['cgpa'], 2); ?></td>
                            </tr>
                        <?php if ($h['gpa'] !== null) $prev = $h['gpa']; endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted mb-0 mt-2" style="font-size:0.75rem;">
                    <?php echo count($graded) >= 2
                        ? 'GPA uses graded subjects only, weighted by credit hours; CGPA is cumulative.'
                        : 'A trend chart appears once two semesters have grades. GPA uses graded subjects only, weighted by credit hours.'; ?>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

// Chart.js line chart for render_progress_card() (Chart.js must be loaded).
function progress_chart_script(array $history, $canvas_id) {
    $graded = array_values(array_filter($history, fn($h) => $h['gpa'] !== null));
    if (count($graded) < 2) {
        return '';
    }
    $data = json_encode([
        'labels' => array_column($graded, 'name'),
        'gpa'    => array_column($graded, 'gpa'),
        'cgpa'   => array_column($graded, 'cgpa'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $id = json_encode($canvas_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return <<<HTML
<script>
(function () {
    const d = $data;
    const dark  = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    const muted = dark ? '#94A3B8' : '#64748B';
    const grid  = dark ? '#334155' : '#E2E8F0';
    const teal  = dark ? '#3CC8CD' : '#0D7377';
    const navy  = dark ? '#CBD5E1' : '#1A3A5C';
    new Chart(document.getElementById($id), {
        type: 'line',
        data: { labels: d.labels, datasets: [
            { label: 'GPA', data: d.gpa, borderColor: teal, backgroundColor: teal + '22', fill: true, tension: 0.25, pointRadius: 4, borderWidth: 2 },
            { label: 'CGPA', data: d.cgpa, borderColor: navy, borderDash: [5, 4], tension: 0.25, pointRadius: 3, borderWidth: 2, fill: false }
        ] },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                y: { min: 0, max: 4, ticks: { stepSize: 1, color: muted }, grid: { color: grid } },
                x: { ticks: { color: muted }, grid: { display: false } }
            },
            plugins: {
                legend: { position: 'bottom', labels: { color: muted, usePointStyle: true, font: { size: 11 } } },
                tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + c.raw.toFixed(2) } }
            }
        }
    });
})();
</script>
HTML;
}
?>
