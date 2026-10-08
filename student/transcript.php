<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';

$user_id = $_SESSION['user_id'];

// ── SECURE QUERY: Fetch Student Profile ───────────────────────────────
$stmt_student = mysqli_prepare($conn, "SELECT s.*, u.full_name, u.email
                                       FROM students s
                                       JOIN users u ON s.user_id = u.id
                                       WHERE s.user_id = ?");
mysqli_stmt_bind_param($stmt_student, "i", $user_id);
mysqli_stmt_execute($stmt_student);
$student       = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_student));
if (!$student) {
    require '../includes/no_student_profile.php';
    exit();
}
$student_db_id = $student['id'];
mysqli_stmt_close($stmt_student);

// ── SECURE QUERY: Every enrolment, grouped by semester ─────────────────
// A subject appears on the transcript once the student is enrolled in it;
// without a final score it shows "GP" (Grades Pending) and is excluded
// from GPA — it is never treated as a score of 0.
$stmt_perf = mysqli_prepare($conn, "SELECT sem.id AS semester_id, sem.semester_name, sem.start_date,
                                            sub.subject_name, sub.subject_code,
                                            pr.total_score, pr.predicted_status
                                     FROM student_enrollments e
                                     JOIN semesters sem ON sem.id = e.semester_id
                                     JOIN subjects sub ON sub.id = e.subject_id
                                     LEFT JOIN predictions pr ON pr.student_id = e.student_id
                                          AND pr.subject_id = e.subject_id AND pr.semester_id = e.semester_id
                                     WHERE e.student_id = ?
                                     ORDER BY sem.start_date, sub.subject_code");
mysqli_stmt_bind_param($stmt_perf, "i", $student_db_id);
mysqli_stmt_execute($stmt_perf);
$performance = mysqli_stmt_get_result($stmt_perf);

function gradeFromScore($score) {
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

// Credit hours per subject; everything not listed is 3.
$credit_map = ['EC2320' => 4.00];

// Build one block per semester with its own GPA plus the running
// (cumulative) totals up to and including that semester.
// BR-30: GPA = Σ(GP × Credit Hours) ÷ Σ(Credit Hours of graded subjects)
$semesters = [];
while ($row = mysqli_fetch_assoc($performance)) {
    $sid = $row['semester_id'];
    if (!isset($semesters[$sid])) {
        $semesters[$sid] = ['name' => $row['semester_name'], 'rows' => [], 'credits' => 0.0, 'points' => 0.0];
    }
    $wt = $credit_map[$row['subject_code']] ?? 3.00;
    if ($row['total_score'] !== null) {
        [$grade, $gp] = gradeFromScore((float)$row['total_score']);
        $semesters[$sid]['credits'] += $wt;
        $semesters[$sid]['points']  += $gp * $wt;
        $passed = ($row['predicted_status'] !== 'Fail');
        $remark = ($row['predicted_status'] === 'At Risk') ? 'At Risk' : '';
    } else {
        [$grade, $gp, $passed, $remark] = ['GP', null, null, 'Pending'];
    }
    $semesters[$sid]['rows'][] = $row + ['grade' => $grade, 'gp' => $gp, 'wt' => $wt, 'passed' => $passed, 'remark' => $remark];
}
mysqli_stmt_close($stmt_perf);

$cum_credits = 0.0;
$cum_points  = 0.0;
foreach ($semesters as &$sem) {
    $cum_credits += $sem['credits'];
    $cum_points  += $sem['points'];
    $sem['gpa']         = $sem['credits'] > 0 ? $sem['points'] / $sem['credits'] : null;
    $sem['cum_credits'] = $cum_credits;
    $sem['cum_points']  = $cum_points;
    $sem['cgpa']        = $cum_credits > 0 ? $cum_points / $cum_credits : null;
}
unset($sem);

$intake          = $semesters ? reset($semesters)['name'] : '—';
$semester_number = count($semesters);

function gpa_text($v) {
    return $v === null ? '—' : number_format($v, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Academic Transcript — <?php echo h($student['full_name']); ?></title>
    <script>(function(){try{if(localStorage.getItem('spts-theme')==='dark')document.documentElement.setAttribute('data-bs-theme','dark');}catch(e){}})();</script>
    <link href="../assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/dark-mode.css" rel="stylesheet">
    <link href="../assets/css/app.css" rel="stylesheet">
    <link rel="icon" href="../assets/images/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="../assets/images/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png">
    <style>
        /* ── SCREEN STYLES ── */
        body { background: #F0F4F8; font-family: Arial, sans-serif; }

        .action-bar {
            background: #1A3A5C;
            border-bottom: 3px solid #0D7377;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .btn-nav-primary {
            background: #0D7377;
            color: #fff;
            border: 1px solid transparent;
            font-weight: 500;
        }
        .btn-nav-primary:hover { background: #0a5e62; color: #fff; }

        .transcript-wrapper {
            max-width: 794px;
            margin: 40px auto;
            background: white;
            padding: 40px 50px;
            box-shadow: 0 10px 36px rgba(26,58,92,0.1);
            border-radius: 8px;
        }

        /* ── HEADER ── */
        .header-branding {
            text-align: center;
            margin-bottom: 8px;
        }

        .header-branding img {
            height: 65px; /* Increased size for better visual balance */
            width: auto;
            display: block;
            margin: 0 auto 6px auto;
        }

        .uni-name {
            font-size: 16pt;
            font-weight: bold;
            color: #1A3A5C;
        }

        .doc-title {
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 12px;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        hr.thick { border: 1.5px solid #1A3A5C; margin: 8px 0; opacity: 1; }
        hr.thin  { border: 0.5px solid #CBD5E1; margin: 12px 0; opacity: 1; }

        /* ── INFO TABLE ── */
        .info-table { width: 100%; font-size: 9pt; margin-bottom: 8px; }
        .info-table td { padding: 2px 0; vertical-align: top; color: #1e293b; }
        .info-label { font-weight: bold; width: 120px; color: #475569; }
        .print-date { text-align: right; font-size: 9pt; color: #64748B; }

        /* ── ADDRESS ── */
        .address-block { font-size: 9pt; margin-bottom: 12px; color: #1e293b; line-height: 1.4; }
        .address-block .name { font-weight: bold; font-size: 9.5pt; color: #1A3A5C; }

        /* ── PROG INFO ── */
        .prog-table { width: 100%; font-size: 9pt; margin-bottom: 15px; }
        .prog-table td { padding: 3px 0; color: #1e293b; }
        .prog-label { font-weight: bold; width: 180px; color: #475569; }

        /* ── COURSES TABLE ── */
        .courses-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin-bottom: 15px;
            border: 1px solid #E2E8F0;
        }

        .courses-table thead th {
            background: #1A3A5C;
            color: white;
            padding: 8px 6px;
            text-align: center;
            font-size: 8.5pt;
            border: 1px solid #1A3A5C;
        }

        .courses-table thead th.left { text-align: left; }

        .courses-table tbody tr:nth-child(even) { background: #F8FAFC; }

        .courses-table tbody td {
            padding: 8px 6px;
            border: 1px solid #E2E8F0;
            text-align: center;
            font-size: 8.5pt;
            color: #334155;
        }

        .courses-table tbody td.left { text-align: left; font-weight: 500; }

        .pass  { color: #15803d; font-weight: bold; }
        .fail  { color: #dc2626; font-weight: bold; }

        /* ── SUMMARY ── */
        .summary-table { width: 60%; font-size: 9pt; margin-left: auto; margin-bottom: 15px; color: #1e293b; }
        .summary-table td { padding: 4px; }
        .summary-label { font-weight: bold; color: #475569; }
        .summary-val   { text-align: right; font-weight: bold; }

        /* ── LEGEND ── */
        .legend { font-size: 7.5pt; color: #64748B; line-height: 1.6; margin-top: 10px; }

        /* ── FOOTER ── */
        .transcript-footer {
            font-size: 7.5pt;
            text-align: center;
            color: #64748B;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #CBD5E1;
            line-height: 1.4;
        }

        /* ── PRINT STYLES ── */
        @media print {
            @page {
                size: A4;
                margin: 0;
            }
            body {
                background: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .action-bar, .no-print { display: none !important; }

            .transcript-wrapper {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 2cm !important;
                border-radius: 0 !important;
                max-width: 100% !important;
            }

            .courses-table, .courses-table tbody td, .courses-table thead th {
                border: 1px solid #000 !important;
            }
            .courses-table thead th {
                color: #000 !important;
                background: #e2e8f0 !important;
            }
            .uni-name { color: #000 !important; }
        }
    </style>
</head>
<body>

<!-- Action Bar -->
<div class="action-bar no-print">
    <div>
        <a href="student_dashboard.php" class="btn btn-outline-light btn-sm me-2">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>
    <div>
        <button type="button" class="theme-toggle me-2" onclick="toggleTheme()" title="Toggle dark mode">
            <i class="bi bi-moon-stars-fill theme-toggle-icon"></i>
        </button>
        <button onclick="window.print()" class="btn btn-nav-primary px-4 py-2 rounded-2">
            <i class="bi bi-printer-fill me-2"></i>Print Official PDF
        </button>
    </div>
</div>

<!-- Transcript -->
<div class="transcript-wrapper">

    <!-- Header -->
    <div class="header-branding">
        <img src="../assets/images/niai1.png" alt="Nilai University Logo">
    </div>
    <div class="doc-title">Student Semester Academic Results</div>
    <hr class="thick">

    <!-- Student Info + Date -->
    <table class="info-table">
        <tr>
            <td>
                <table style="font-size:9pt;">
                    <tr>
                        <td class="info-label">Student ID</td>
                        <td>: <?php echo h($student['student_id']); ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Student Name</td>
                        <td>: <?php echo h(strtoupper($student['full_name'])); ?></td>
                    </tr>
                    <tr>
                        <td class="info-label">Email</td>
                        <td>: <?php echo h($student['email']); ?></td>
                    </tr>
                </table>
            </td>
            <td class="print-date">
                Print Date : <?php echo date('d F Y'); ?>
            </td>
        </tr>
    </table>

    <!-- Address Block -->
    <div class="address-block">
        <div class="name"><?php echo h(strtoupper($student['full_name'])); ?></div>
        <div>Nilai University, No 1, Persiaran Universiti, Putra Nilai, 71800 Nilai, Negeri Sembilan.</div>
    </div>

    <hr class="thin">

    <!-- Programme Info -->
    <table class="prog-table">
        <tr>
            <td class="prog-label">Faculty / School / Dept.</td>
            <td>: DEPARTMENT OF COMPUTING AND DATA SCIENCES</td>
        </tr>
        <tr>
            <td class="prog-label">Programme</td>
            <td>: <?php echo h(strtoupper($student['course'])); ?></td>
        </tr>
        <tr>
            <td class="prog-label">Intake</td>
            <td>: <?php echo h($intake); ?></td>
        </tr>
        <tr>
            <td class="prog-label">Semester Number</td>
            <td>: <?php echo $semester_number; ?></td>
        </tr>
    </table>

    <?php if (!$semesters): ?>
    <p style="text-align:center; color:#888; margin:20px 0;">No subjects recorded yet.</p>
    <?php endif; ?>

    <?php foreach ($semesters as $sem): ?>
    <div class="semester-heading" style="font-weight:700; font-size:10pt; margin:14px 0 4px;">Semester: <?php echo h($sem['name']); ?></div>

    <!-- Courses Table -->
    <table class="courses-table">
        <thead>
            <tr>
                <th style="width:50px;">Code</th>
                <th class="left" style="width:auto;">Course Description</th>
                <th style="width:60px;">Weightage</th>
                <th style="width:40px;">Grade</th>
                <th style="width:40px;">GP</th>
                <th style="width:55px;">Pass / Fail</th>
                <th style="width:55px;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sem['rows'] as $row): ?>
            <tr>
                <td><?php echo h($row['subject_code']); ?></td>
                <td class="left"><?php echo h(strtoupper($row['subject_name'])); ?></td>
                <td><?php echo number_format($row['wt'], 2); ?></td>
                <td><strong><?php echo h($row['grade']); ?></strong></td>
                <td><?php echo $row['gp'] === null ? '—' : number_format($row['gp'], 2); ?></td>
                <?php if ($row['passed'] === null): ?>
                    <td>—</td>
                <?php else: ?>
                    <td class="<?php echo $row['passed'] ? 'pass' : 'fail'; ?>"><?php echo $row['passed'] ? 'Pass' : 'Fail'; ?></td>
                <?php endif; ?>
                <td><?php echo h($row['remark']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Summary -->
    <table class="summary-table">
        <tr>
            <td class="summary-label">Total Weightage for This Semester (graded)</td>
            <td>:</td>
            <td class="summary-val"><?php echo number_format($sem['credits'], 2); ?></td>
        </tr>
        <tr>
            <td class="summary-label">Cumulative Weightage</td>
            <td>:</td>
            <td class="summary-val"><?php echo number_format($sem['cum_credits'], 2); ?></td>
        </tr>
        <tr>
            <td class="summary-label">Total Grade Point for This Semester</td>
            <td>:</td>
            <td class="summary-val"><?php echo number_format($sem['points'], 2); ?></td>
        </tr>
        <tr>
            <td class="summary-label">Cumulative Grade Point</td>
            <td>:</td>
            <td class="summary-val"><?php echo number_format($sem['cum_points'], 2); ?></td>
        </tr>
        <tr style="border-top: 1px solid #1e293b;">
            <td class="summary-label" style="padding-top: 8px;">Grade Point Average (GPA)</td>
            <td style="padding-top: 8px;">:</td>
            <td class="summary-val" style="padding-top: 8px;"><?php echo gpa_text($sem['gpa']); ?></td>
        </tr>
        <tr>
            <td class="summary-label">Cumulative Grade Point Average (CGPA)</td>
            <td>:</td>
            <td class="summary-val"><?php echo gpa_text($sem['cgpa']); ?></td>
        </tr>
    </table>
    <?php endforeach; ?>

    <hr class="thin">

    <!-- Grade Legend -->
    <div class="legend">
        <strong>A+:</strong> 90–100 &nbsp;
        <strong>A:</strong> 80–89 &nbsp;
        <strong>A-:</strong> 75–79 &nbsp;
        <strong>B+:</strong> 70–74 &nbsp;
        <strong>B:</strong> 65–69 &nbsp;
        <strong>B-:</strong> 60–64 &nbsp;
        <strong>C+:</strong> 55–59 &nbsp;
        <strong>C:</strong> 50–54 &nbsp;
        <strong>D+:</strong> 45–49 &nbsp;
        <strong>D:</strong> 40–44 &nbsp;
        <strong>F:</strong> 0–39
        <br>
        RE: Resit &nbsp; NC: Not Completed &nbsp; SC: Satisfactorily Completed &nbsp;
        GP: Grades Pending &nbsp; CR: Credit Transfer &nbsp; NS: Not Satisfactory &nbsp;
        BE: Barred from Examination &nbsp; EX: Exemption &nbsp; R: Retake &nbsp;
        P: Resit Pass &nbsp; X: Absent &nbsp; AP: Advance Placement &nbsp; AU: Audit
    </div>

    <!-- Footer -->
    <div class="transcript-footer">
        This transcript is generated by the Student Performance Tracking Web System Using Weighted Scoring Model. This is an unofficial transcript and is for reference purposes only. For official transcripts, please contact the Registrar's Office at Nilai University.<br>
        <br>
        Student Performance Tracking Web System Using Weighted Scoring Model — EC2320 Summative Project <?php echo date('Y'); ?>
    </div>

</div>

<script src="../assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="../assets/js/theme-toggle.js"></script>
<script src="../assets/js/app.js?v=2"></script>
</body>
</html>