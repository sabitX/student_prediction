<?php
// ── Mid-semester early warning ──────────────────────────────────────────
// The predictions view only has a result once all four marks are in, i.e.
// after the final exam — too late to help. This file works from whatever
// marks exist so far (the early_risk_predictions view, migration 009) and
// says, in plain words, why a student is at risk while there is still time
// to act. Used by the student dashboard, mentorship page and the lecturer
// dashboard.
//
// Projection before the final exam is marked:
//   current   = weighted average of the components marked so far (the view)
//   prior     = the student's average total in earlier semesters, or this
//               class's current average for a student with no history
//   alpha     = coursework weight marked so far ÷ all coursework weight
//               (everything but the final exam; 0.60 with default weights)
//   projected = alpha × current + (1 − alpha) × prior
// Early on (little marked) the projection leans on the prior; once all
// coursework is in it is the student's own coursework average. This is a
// simple shrinkage blend, not a fitted model — the thresholds below are
// policy choices, kept here so they're easy to find and change.

require_once __DIR__ . '/grading.php';

const ATTENDANCE_BAR    = 80;    // below this: barred from the final exam (BE)
const HIGH_FINAL_TARGET = 75;    // needing more than this in the final is flagged
const Z_FLAG            = -1.0;  // this many standard deviations below the class average is flagged
const COHORT_MIN_SIZE   = 5;     // fewer marked students: no class comparison

// Weight of all coursework (everything except the final exam) for a row's
// subject and semester: 0.60 with the default weights.
function coursework_weight_total(array $row) {
    return 1 - row_weights($row)['final_exam'];
}

// Class averages and spread for one subject in one semester (cached).
function ew_cohort_stats($conn, $subject_id, $semester_id) {
    static $cache = [];
    $key = "$subject_id:$semester_id";
    if (!isset($cache[$key])) {
        $stmt = mysqli_prepare($conn,
            "SELECT COUNT(assignment) AS n_assignment, AVG(assignment) AS avg_assignment, STDDEV_POP(assignment) AS sd_assignment,
                    COUNT(midterm)    AS n_midterm,    AVG(midterm)    AS avg_midterm,    STDDEV_POP(midterm)    AS sd_midterm,
                    AVG(current_score) AS avg_current
             FROM early_risk_predictions WHERE subject_id = ? AND semester_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
        mysqli_stmt_execute($stmt);
        $cache[$key] = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }
    return $cache[$key];
}

// Each student's average total in semesters that started before this one.
// [student_id => average]; students with no earlier results are left out.
function ew_prior_scores($conn, array $student_ids, $semester_id) {
    $student_ids = array_values(array_unique(array_map('intval', $student_ids)));
    if (!$student_ids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($student_ids), '?'));
    $stmt = mysqli_prepare($conn,
        "SELECT pr.student_id, AVG(pr.total_score) AS avg_total
         FROM predictions pr
         JOIN semesters sem ON sem.id = pr.semester_id
         WHERE pr.student_id IN ($marks)
           AND sem.start_date < (SELECT start_date FROM semesters WHERE id = ?)
         GROUP BY pr.student_id");
    $params = [...$student_ids, (int)$semester_id];
    mysqli_stmt_bind_param($stmt, str_repeat('i', count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    $prior = [];
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $r) {
        $prior[(int)$r['student_id']] = (float)$r['avg_total'];
    }
    mysqli_stmt_close($stmt);
    return $prior;
}

function ew_pct($v) {
    return rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.') . '%';
}

// Analyses one early_risk_predictions row. $prior: the student's earlier
// average, or null to use the class average. Returns the projection and a
// list of reasons, each ['level' => 'danger'|'warning', 'text' => ...].
function ew_analyse(array $row, $prior, array $cohort) {
    $final_marked = $row['final_exam'] !== null;
    $current      = (float)$row['current_score'];
    $prior_source = $prior !== null ? 'history' : 'class';
    if ($prior === null && $cohort['avg_current'] !== null) {
        $prior = (float)$cohort['avg_current'];
    }

    if ($final_marked) {
        $alpha     = 1.0;
        $projected = $current;   // = the official total once all four are in
    } else {
        $alpha     = min(1.0, (float)$row['assessed_weight'] / coursework_weight_total($row));
        $projected = $prior === null ? $current : $alpha * $current + (1 - $alpha) * $prior;
    }
    $projected = round($projected, 2);
    $min_final = $row['min_final_to_pass'] === null ? null : (float)$row['min_final_to_pass'];
    $barred    = $row['attendance'] !== null && (float)$row['attendance'] < ATTENDANCE_BAR;

    $reasons = [];
    if ($barred) {
        $reasons[] = ['level' => 'danger', 'text' => 'Attendance is ' . ew_pct($row['attendance']) . ' — below the ' . ATTENDANCE_BAR . '% needed to sit the final exam (BE).'];
    }
    if (!$final_marked && $min_final !== null) {
        if ($min_final > 100) {
            $reasons[] = ['level' => 'danger', 'text' => 'Cannot reach ' . PASS_MARK . '% even with 100% in the final exam.'];
        } elseif ($min_final > HIGH_FINAL_TARGET) {
            $reasons[] = ['level' => 'warning', 'text' => 'Needs at least ' . ew_pct($min_final) . ' in the final exam to pass.'];
        }
    }
    if ($projected < 50) {
        $reasons[] = ['level' => 'danger', 'text' => ($final_marked ? 'Total' : 'Projected total') . ' is ' . ew_pct($projected) . ' — in the Fail band.'];
    } elseif ($projected < PASS_MARK) {
        $reasons[] = ['level' => 'warning', 'text' => ($final_marked ? 'Total' : 'Projected total') . ' is ' . ew_pct($projected) . ' — below the ' . PASS_MARK . '% pass mark.'];
    }
    foreach (['assignment' => 'Assignment', 'midterm' => 'Midterm'] as $key => $label) {
        if ($row[$key] === null || (int)$cohort["n_$key"] < COHORT_MIN_SIZE || (float)$cohort["sd_$key"] <= 0) {
            continue;
        }
        $z = ((float)$row[$key] - (float)$cohort["avg_$key"]) / (float)$cohort["sd_$key"];
        if ($z <= Z_FLAG) {
            $reasons[] = ['level' => 'warning', 'text' => "$label (" . ew_pct($row[$key]) . ') is ' . number_format(abs($z), 1)
                . ' standard deviations below the class average of ' . ew_pct($cohort["avg_$key"]) . '.'];
        }
    }

    $levels = array_column($reasons, 'level');
    return [
        'final_marked'     => $final_marked,
        'current'          => $current,
        'projected'        => $projected,
        'projected_status' => result_status($projected),
        'prior'            => $prior,
        'prior_source'     => $prior_source,
        'alpha'            => $alpha,
        'min_final'        => $min_final,
        'barred'           => $barred,
        'reasons'          => $reasons,
        'level'            => in_array('danger', $levels, true) ? 'critical' : ($levels ? 'warning' : 'ok'),
    ];
}

// Early-warning rows for one semester, analysed, for one student
// ($by = 'student', $id = students.id), every student in one lecturer's
// subjects ($by = 'lecturer', $id = users.id) or everyone ($by = 'all', for
// admin exports; $id is ignored). Archived subjects are left out.
function early_warnings($conn, $semester_id, $by, $id) {
    $scope = match ($by) {
        'lecturer' => "JOIN lecturer_subjects ls ON ls.subject_id = r.subject_id AND ls.semester_id = r.semester_id AND ls.user_id = ?",
        'all'      => "",
        default    => "AND r.student_id = ?",
    };
    $sql = "SELECT r.*, sub.subject_code, sub.subject_name, s.student_id AS student_code, u.full_name, u.email,
                   en.id AS enrollment_id, n.risk_level AS notified_level, n.sent_at AS notified_at
            FROM early_risk_predictions r
            JOIN subjects sub ON sub.id = r.subject_id AND sub.is_archived = 0
            JOIN students s ON s.id = r.student_id
            JOIN users u ON u.id = s.user_id
            JOIN student_enrollments en ON en.student_id = r.student_id AND en.subject_id = r.subject_id AND en.semester_id = r.semester_id
            LEFT JOIN early_warning_notices n ON n.enrollment_id = en.id
            " . ($by === 'lecturer' ? $scope : '') . "
            WHERE r.semester_id = ? " . ($by === 'lecturer' ? '' : $scope) . "
            ORDER BY sub.subject_code, u.full_name";
    $stmt = mysqli_prepare($conn, $sql);
    if ($by === 'lecturer') {
        mysqli_stmt_bind_param($stmt, "ii", $id, $semester_id);
    } elseif ($by === 'all') {
        mysqli_stmt_bind_param($stmt, "i", $semester_id);
    } else {
        mysqli_stmt_bind_param($stmt, "ii", $semester_id, $id);
    }
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $prior = ew_prior_scores($conn, array_column($rows, 'student_id'), $semester_id);
    foreach ($rows as &$row) {
        $cohort = ew_cohort_stats($conn, (int)$row['subject_id'], (int)$semester_id);
        $row['analysis'] = ew_analyse($row, $prior[(int)$row['student_id']] ?? null, $cohort);
    }
    unset($row);
    return $rows;
}

// ── Emailing a flagged student ──────────────────────────────────────────
// A lecturer sends this from their dashboard (early_warning_notices,
// migration 013). It goes to the student's registered email and explains
// the warning in the same words as the dashboard. Returns true only when
// the mail server accepted it; only then is it recorded.
function ew_send_notice($conn, array $ew, $lecturer_id, $lecturer_name, $semester_name) {
    require_once __DIR__ . '/mailer.php';
    $a = $ew['analysis'];
    if ($a['level'] === 'ok' || $a['final_marked']) {
        return false;
    }
    $lines = [];
    foreach ($a['reasons'] as $r) {
        $lines[] = '  - ' . $r['text'];
    }
    $body = "Hello {$ew['full_name']},\n\n"
          . "Your lecturer, $lecturer_name, is letting you know that your marks so far in "
          . "{$ew['subject_code']} {$ew['subject_name']} ($semester_name) point to a risk of not passing:\n\n"
          . implode("\n", $lines) . "\n\n"
          . "Projected total: " . ew_pct($a['projected']) . " (pass mark " . PASS_MARK . "%).\n"
          . ($a['min_final'] !== null && $a['min_final'] <= 100 ? "Final exam score you need to pass: " . ew_pct($a['min_final']) . ".\n" : '')
          . ($a['min_final'] !== null && $a['min_final'] > 100
                ? "\nEven full marks in the final exam would not reach the pass mark, so please talk to your lecturer now about your options.\n"
                : "\nThis is an early warning, not a result: there is still time to change it.\n")
          . "  - Talk to your lecturer about the areas to work on.\n"
          . "  - Book a mentoring session: " . app_url('/student/mentorship.php') . "\n"
          . "  - See your outlook for every subject: " . app_url('/student/student_dashboard.php') . "\n\n"
          . "— SPTS, Nilai University";
    $level = $a['level'] === 'critical' ? 'critical' : 'warning';
    $subject = ($level === 'critical' ? 'Urgent: ' : '') . "Early warning for {$ew['subject_code']} — {$ew['subject_name']}";
    if (!send_mail($ew['email'], $ew['full_name'], $subject, $body)) {
        return false;
    }
    $stmt = mysqli_prepare($conn, "INSERT INTO early_warning_notices (enrollment_id, risk_level, sent_at, sent_by) VALUES (?, ?, NOW(), ?)
                                   ON DUPLICATE KEY UPDATE risk_level = VALUES(risk_level), sent_at = VALUES(sent_at), sent_by = VALUES(sent_by)");
    mysqli_stmt_bind_param($stmt, "isi", $ew['enrollment_id'], $level, $lecturer_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return true;
}

// Whether "email all" should include this flagged row: never emailed, or
// it has become more serious (warning → critical) since the last email.
function ew_needs_notice(array $ew) {
    return $ew['notified_level'] === null
        || ($ew['analysis']['level'] === 'critical' && $ew['notified_level'] !== 'critical');
}

// ── Backtest: how well would the early warning have done? ───────────────
// Replays every finished result (all four marks) as if the final exam
// hadn't been marked yet, runs ew_analyse() on it, and compares the
// warning with what actually happened (total below PASS_MARK).
// Two checkpoints:
//   'coursework'  assignment, midterm and attendance known (just before the final)
//   'early'       only assignment and attendance known (before the midterm),
//                 where the prior (earlier results / class average) carries weight
// Class statistics come from the same replayed checkpoint, so nothing from
// the future leaks in. For each checkpoint also reports the projection
// error without the prior, to show whether blending it in helps.
const BACKTEST_CHECKPOINTS = [
    'coursework' => ['label' => 'Before the final exam', 'known' => ['assignment', 'midterm', 'attendance']],
    'early'      => ['label' => 'Before the midterm',    'known' => ['assignment', 'attendance']],
];

function ew_backtest($conn) {
    $rows = mysqli_fetch_all(mysqli_query($conn,
        "SELECT e.student_id, e.subject_id, e.semester_id, p.assignment, p.midterm, p.final_exam, p.attendance, pr.total_score, " . WEIGHTS_COLS . "
         FROM performance p
         JOIN student_enrollments e ON e.id = p.enrollment_id
         " . WEIGHTS_JOIN . "
         JOIN predictions pr ON pr.student_id = e.student_id AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id"),
        MYSQLI_ASSOC);

    $prior = [];   // [semester_id => [student_id => avg]]
    foreach (array_unique(array_column($rows, 'semester_id')) as $sem) {
        $ids = array_column(array_filter($rows, fn($r) => $r['semester_id'] == $sem), 'student_id');
        $prior[$sem] = ew_prior_scores($conn, $ids, (int)$sem);
    }

    $out = [];
    foreach (BACKTEST_CHECKPOINTS as $key => $cp) {
        // Replayed rows: unknown components blanked, scores recomputed
        // with that subject's weights for that semester.
        $replay = [];
        foreach ($rows as $r) {
            $weights = row_weights($r);
            $weight  = array_sum(array_map(fn($c) => $weights[$c], $cp['known']));
            $x = $r;
            foreach ($weights as $c => $w) {
                if (!in_array($c, $cp['known'], true)) $x[$c] = null;
            }
            $points = 0.0;
            foreach ($cp['known'] as $c) $points += (float)$r[$c] * $weights[$c];
            $x['assessed_weight']   = $weight;
            $x['current_score']     = round($points / $weight, 2);
            $x['min_final_to_pass'] = count($cp['known']) === 3 ? max(0, round((PASS_MARK - $points) / $weights['final_exam'], 2)) : null;
            $replay[] = $x;
        }
        // Class statistics per subject and semester at this checkpoint.
        $groups = [];
        foreach ($replay as $x) $groups[$x['subject_id'] . ':' . $x['semester_id']][] = $x;
        $cohorts = [];
        foreach ($groups as $g => $xs) {
            $c = ['avg_current' => array_sum(array_column($xs, 'current_score')) / count($xs)];
            foreach (['assignment', 'midterm'] as $k) {
                $vals = array_map('floatval', array_filter(array_column($xs, $k), fn($v) => $v !== null));
                $n = count($vals);
                $avg = $n ? array_sum($vals) / $n : null;
                $c["n_$k"]   = $n;
                $c["avg_$k"] = $avg;
                $c["sd_$k"]  = $n ? sqrt(array_sum(array_map(fn($v) => ($v - $avg) ** 2, $vals)) / $n) : null;
            }
            $cohorts[$g] = $c;
        }

        $m = ['label' => $cp['label'], 'n' => 0, 'tp' => 0, 'fp' => 0, 'fn' => 0, 'tn' => 0, 'abs_err' => 0.0, 'abs_err_no_prior' => 0.0];
        foreach ($replay as $x) {
            $a = ew_analyse($x, $prior[$x['semester_id']][(int)$x['student_id']] ?? null,
                            $cohorts[$x['subject_id'] . ':' . $x['semester_id']]);
            $flagged = $a['level'] !== 'ok';
            $failed  = (float)$x['total_score'] < PASS_MARK;
            $m['n']++;
            $m[$flagged ? ($failed ? 'tp' : 'fp') : ($failed ? 'fn' : 'tn')]++;
            $m['abs_err']          += abs($a['projected'] - (float)$x['total_score']);
            $m['abs_err_no_prior'] += abs((float)$x['current_score'] - (float)$x['total_score']);
        }
        $n = $m['n'];
        $m['accuracy']      = $n ? ($m['tp'] + $m['tn']) / $n : null;
        $m['precision']     = ($m['tp'] + $m['fp']) ? $m['tp'] / ($m['tp'] + $m['fp']) : null;
        $m['recall']        = ($m['tp'] + $m['fn']) ? $m['tp'] / ($m['tp'] + $m['fn']) : null;
        $m['mae']           = $n ? $m['abs_err'] / $n : null;
        $m['mae_no_prior']  = $n ? $m['abs_err_no_prior'] / $n : null;
        $out[$key] = $m;
    }
    return $out;
}

// Badge classes (assets/css/app.css) for a risk level.
function risk_badge_class($level) {
    return status_badge_class(match ($level) {
        'critical' => 'Fail',
        'warning'  => 'At Risk',
        default    => 'Pass',
    });
}

function risk_label($level) {
    return match ($level) {
        'critical' => 'Critical',
        'warning'  => 'Early Warning',
        default    => 'On Track',
    };
}

// The reasons as a short list (styled by .risk-reasons in app.css).
function render_risk_reasons(array $reasons) {
    if (!$reasons) {
        return '';
    }
    $html = '<ul class="risk-reasons">';
    foreach ($reasons as $r) {
        $icon  = $r['level'] === 'danger' ? 'bi-exclamation-octagon-fill' : 'bi-exclamation-triangle-fill';
        $html .= '<li><i class="bi ' . $icon . '" aria-hidden="true"></i><span>' . h($r['text']) . '</span></li>';
    }
    return $html . '</ul>';
}
?>
