<?php
// ── Outcome-Based Education: CLO / PLO attainment and CQI ───────────────
// Tables from database/migrations/009_obe_and_early_warning.sql:
//   plos             Programme Learning Outcomes, with the score a student
//                    needs to attain one (target_threshold) and the share of
//                    the class that must attain it (cohort_kpi_pct)
//   subject_clos     CLO1–CLO4 per subject: description, Bloom's level, PLO
//   clo_assessments  which CLO each mark component assesses
//   cqi_actions      a lecturer's response to a CLO that missed its KPI
//
// A student's CLO score is the weighted average (MARK_WEIGHTS) of the
// components mapped to it; they attain the CLO when it reaches the PLO's
// target. A CLO is only scored once all its components are marked.

require_once __DIR__ . '/grading.php';

// Outcomes every new subject starts with (the migration seeds the same).
const CLO_DEFAULTS = [
    'CLO1' => ['Explain the fundamental concepts and theory of the subject.',         'C2', 'PLO1', ['midterm']],
    'CLO2' => ['Apply the subject\'s techniques to solve practical problems.',         'C3', 'PLO3', ['assignment']],
    'CLO3' => ['Analyse problems and justify suitable solutions.',                     'C4', 'PLO2', ['final_exam']],
    'CLO4' => ['Demonstrate professional commitment through consistent attendance.',   'A2', 'PLO4', ['attendance']],
];

// Bloom's taxonomy: cognitive (C), psychomotor (P), affective (A).
const BLOOM_LEVELS = [
    'C1' => 'Remember',  'C2' => 'Understand', 'C3' => 'Apply', 'C4' => 'Analyse', 'C5' => 'Evaluate', 'C6' => 'Create',
    'P1' => 'Perception', 'P2' => 'Set', 'P3' => 'Guided Response', 'P4' => 'Mechanism',
    'P5' => 'Complex Overt Response', 'P6' => 'Adaptation', 'P7' => 'Origination',
    'A1' => 'Receiving', 'A2' => 'Responding', 'A3' => 'Valuing', 'A4' => 'Organisation', 'A5' => 'Characterisation',
];

const CQI_TEXT_MAX = 500;

// Gives a subject the default CLOs and mapping (missing ones only).
function obe_seed_subject($conn, $subject_id) {
    $plo_ids = array_column(obe_plos($conn), 'id', 'plo_code');
    foreach (CLO_DEFAULTS as $code => [$desc, $bloom, $plo_code, $components]) {
        if (!isset($plo_ids[$plo_code])) {
            continue;
        }
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO subject_clos (subject_id, clo_code, clo_description, bloom_level, plo_id) VALUES (?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "isssi", $subject_id, $code, $desc, $bloom, $plo_ids[$plo_code]);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        foreach ($components as $component) {
            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO clo_assessments (subject_id, component, clo_id)
                                           SELECT subject_id, ?, id FROM subject_clos WHERE subject_id = ? AND clo_code = ?");
            mysqli_stmt_bind_param($stmt, "sis", $component, $subject_id, $code);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

// Every PLO, in code order, keyed by id.
function obe_plos($conn) {
    static $plos = null;
    if ($plos === null) {
        $plos = [];
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM plos ORDER BY plo_code"), MYSQLI_ASSOC) as $p) {
            $plos[(int)$p['id']] = $p;
        }
    }
    return $plos;
}

// CLOs of the given subjects with their PLO and mapped components:
// [subject_id => [clo_id => clo]]; each clo has 'components' (list) and
// 'weight' (their total weight).
function obe_subject_outcomes($conn, array $subject_ids) {
    $subject_ids = array_values(array_unique(array_map('intval', $subject_ids)));
    if (!$subject_ids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($subject_ids), '?'));
    $stmt = mysqli_prepare($conn,
        "SELECT c.*, p.plo_code, p.plo_title, p.target_threshold, p.cohort_kpi_pct, a.component
         FROM subject_clos c
         JOIN plos p ON p.id = c.plo_id
         LEFT JOIN clo_assessments a ON a.clo_id = c.id
         WHERE c.subject_id IN ($marks)
         ORDER BY c.subject_id, c.clo_code, FIELD(a.component, 'assignment', 'midterm', 'final_exam', 'attendance')");
    mysqli_stmt_bind_param($stmt, str_repeat('i', count($subject_ids)), ...$subject_ids);
    mysqli_stmt_execute($stmt);
    $out = [];
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $r) {
        $sid = (int)$r['subject_id'];
        $cid = (int)$r['id'];
        if (!isset($out[$sid][$cid])) {
            $out[$sid][$cid] = [
                'id' => $cid, 'subject_id' => $sid, 'clo_code' => $r['clo_code'],
                'clo_description' => $r['clo_description'], 'bloom_level' => $r['bloom_level'],
                'plo_id' => (int)$r['plo_id'], 'plo_code' => $r['plo_code'], 'plo_title' => $r['plo_title'],
                'threshold' => (float)$r['target_threshold'], 'kpi' => (float)$r['cohort_kpi_pct'],
                'components' => [], 'weight' => 0.0,
            ];
        }
        if ($r['component'] !== null) {
            $out[$sid][$cid]['components'][] = $r['component'];
            $out[$sid][$cid]['weight']      += MARK_WEIGHTS[$r['component']];
        }
    }
    mysqli_stmt_close($stmt);
    return $out;
}

// A student's score on one CLO from a performance row: the weighted
// average of its components, or null until all of them are marked (or
// when no component assesses it).
function obe_clo_score(array $marks, array $clo) {
    if (!$clo['components']) {
        return null;
    }
    $sum = 0.0;
    foreach ($clo['components'] as $component) {
        if (($marks[$component] ?? null) === null) {
            return null;
        }
        $sum += (float)$marks[$component] * MARK_WEIGHTS[$component];
    }
    return round($sum / $clo['weight'], 2);
}

// "CLO1 Understand (C2) · PLO1" style labels for tooltips.
function obe_clo_label(array $clo) {
    return $clo['clo_code'] . ' — ' . $clo['clo_description'] . ' [' . $clo['bloom_level'] . ' '
         . (BLOOM_LEVELS[$clo['bloom_level']] ?? '') . ' · ' . $clo['plo_code'] . ']';
}

// One student's cumulative attainment per PLO, over every semester:
// [plo_id => plo + ['score' => weighted average of their CLO scores
// (null if none yet), 'attained' => bool|null, 'clos' => count]].
function obe_student_plo($conn, $student_db_id) {
    $stmt = mysqli_prepare($conn, "SELECT subject_id, assignment, midterm, final_exam, attendance FROM performance WHERE student_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $student_db_id);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $outcomes = obe_subject_outcomes($conn, array_column($rows, 'subject_id'));
    $sum = $wt = $count = [];
    foreach ($rows as $row) {
        foreach ($outcomes[(int)$row['subject_id']] ?? [] as $clo) {
            $score = obe_clo_score($row, $clo);
            if ($score === null) continue;
            $sum[$clo['plo_id']]   = ($sum[$clo['plo_id']] ?? 0) + $score * $clo['weight'];
            $wt[$clo['plo_id']]    = ($wt[$clo['plo_id']] ?? 0) + $clo['weight'];
            $count[$clo['plo_id']] = ($count[$clo['plo_id']] ?? 0) + 1;
        }
    }
    $out = [];
    foreach (obe_plos($conn) as $id => $plo) {
        $score = isset($wt[$id]) ? round($sum[$id] / $wt[$id], 2) : null;
        $out[$id] = $plo + [
            'score'    => $score,
            'attained' => $score === null ? null : $score >= (float)$plo['target_threshold'],
            'clos'     => $count[$id] ?? 0,
        ];
    }
    return $out;
}

// Class attainment of each CLO of one subject in one semester:
// [clo_id => clo + ['assessed', 'attained', 'pct' (null if nobody
// assessed yet), 'cqi_required' (bool), 'cqi' (logged action or null)]].
function obe_cohort_clo($conn, $subject_id, $semester_id) {
    $clos = obe_subject_outcomes($conn, [$subject_id])[(int)$subject_id] ?? [];
    if (!$clos) {
        return [];
    }
    $stmt = mysqli_prepare($conn, "SELECT assignment, midterm, final_exam, attendance FROM performance WHERE subject_id = ? AND semester_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT q.*, u.full_name AS logged_by_name FROM cqi_actions q
                                   JOIN subject_clos c ON c.id = q.clo_id
                                   LEFT JOIN users u ON u.id = q.logged_by
                                   WHERE c.subject_id = ? AND q.semester_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $subject_id, $semester_id);
    mysqli_stmt_execute($stmt);
    $cqi = array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), null, 'clo_id');
    mysqli_stmt_close($stmt);

    foreach ($clos as $id => &$clo) {
        $assessed = $attained = 0;
        foreach ($rows as $row) {
            $score = obe_clo_score($row, $clo);
            if ($score === null) continue;
            $assessed++;
            if ($score >= $clo['threshold']) $attained++;
        }
        $pct = $assessed ? round($attained * 100 / $assessed, 2) : null;
        $clo += [
            'assessed'     => $assessed,
            'attained'     => $attained,
            'pct'          => $pct,
            'cqi_required' => $pct !== null && $pct < $clo['kpi'],
            'cqi'          => $cqi[$id] ?? null,
        ];
    }
    unset($clo);
    return $clos;
}

// Institution-wide, one semester: per PLO, the share of student CLO
// results (active subjects) that met the PLO's target.
// [plo_id => plo + ['results', 'attained', 'pct' (null if none)]].
function obe_institution_plo($conn, $semester_id) {
    $stmt = mysqli_prepare($conn, "SELECT p.subject_id, p.assignment, p.midterm, p.final_exam, p.attendance
                                   FROM performance p JOIN subjects sub ON sub.id = p.subject_id AND sub.is_archived = 0
                                   WHERE p.semester_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $semester_id);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $outcomes = obe_subject_outcomes($conn, array_column($rows, 'subject_id'));
    $results = $attained = [];
    foreach ($rows as $row) {
        foreach ($outcomes[(int)$row['subject_id']] ?? [] as $clo) {
            $score = obe_clo_score($row, $clo);
            if ($score === null) continue;
            $results[$clo['plo_id']]  = ($results[$clo['plo_id']] ?? 0) + 1;
            $attained[$clo['plo_id']] = ($attained[$clo['plo_id']] ?? 0) + ($score >= $clo['threshold'] ? 1 : 0);
        }
    }
    $out = [];
    foreach (obe_plos($conn) as $id => $plo) {
        $n = $results[$id] ?? 0;
        $out[$id] = $plo + [
            'results'  => $n,
            'attained' => $attained[$id] ?? 0,
            'pct'      => $n ? round(($attained[$id] ?? 0) * 100 / $n, 2) : null,
        ];
    }
    return $out;
}

// Records (or updates) a lecturer's CQI action for one CLO this semester.
// The lecturer must teach the CLO's subject. The attainment stored is
// recalculated here, never taken from the form. Returns [ok, message].
function obe_save_cqi($conn, $lecturer_id, $clo_id, $semester_id, $root_cause, $remedial_plan) {
    $root_cause    = trim((string)$root_cause);
    $remedial_plan = trim((string)$remedial_plan);
    if ($semester_id <= 0) {
        return [false, "No semester is active, so CQI actions can't be recorded."];
    }
    if ($root_cause === '' || $remedial_plan === '') {
        return [false, "Enter both a root cause and a remedial plan."];
    }
    if (mb_strlen($root_cause) > CQI_TEXT_MAX || mb_strlen($remedial_plan) > CQI_TEXT_MAX) {
        return [false, "Root cause and remedial plan can be at most " . CQI_TEXT_MAX . " characters each."];
    }

    $stmt = mysqli_prepare($conn, "SELECT c.subject_id, c.clo_code, sub.subject_code FROM subject_clos c
                                   JOIN subjects sub ON sub.id = c.subject_id AND sub.is_archived = 0
                                   JOIN lecturer_subjects ls ON ls.subject_id = c.subject_id AND ls.user_id = ?
                                   WHERE c.id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $lecturer_id, $clo_id);
    mysqli_stmt_execute($stmt);
    $clo = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$clo) {
        return [false, "That learning outcome isn't in one of your subjects."];
    }

    $pct = obe_cohort_clo($conn, (int)$clo['subject_id'], $semester_id)[$clo_id]['pct'] ?? null;
    if ($pct === null) {
        return [false, "No student has been assessed on {$clo['clo_code']} yet."];
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO cqi_actions (clo_id, semester_id, attainment_pct, root_cause, remedial_plan, logged_by)
                                   VALUES (?, ?, ?, ?, ?, ?)
                                   ON DUPLICATE KEY UPDATE attainment_pct = VALUES(attainment_pct), root_cause = VALUES(root_cause),
                                                           remedial_plan = VALUES(remedial_plan), logged_by = VALUES(logged_by)");
    mysqli_stmt_bind_param($stmt, "iidssi", $clo_id, $semester_id, $pct, $root_cause, $remedial_plan, $lecturer_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    require_once __DIR__ . '/audit.php';
    audit_log($conn, 'CQI action recorded', "Subject: {$clo['subject_code']} | {$clo['clo_code']} | Attainment: " . number_format($pct, 2) . "%");
    return [true, "CQI action saved for {$clo['subject_code']} {$clo['clo_code']}."];
}
?>
