<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/csrf.php';
require '../includes/semester.php';
require '../includes/audit.php';
require '../includes/marks.php';
require '../includes/obe.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// ── One subject's learning outcomes (CLO1–CLO4) ────────────────────────
// The admin words each CLO, tags its Bloom's level and PLO, and chooses
// which CLO each mark component assesses. Attainment is then calculated
// from the marks lecturers already enter (includes/obe.php).

$error   = "";
require_once '../includes/flash.php';
$flash   = flash_take();
$success = $flash['success'] ?? "";

$subject_id = (int)($_GET['id'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT id, subject_code, subject_name, credit_hours FROM subjects WHERE id = ? AND is_archived = 0");
mysqli_stmt_bind_param($stmt, "i", $subject_id);
mysqli_stmt_execute($stmt);
$subject = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$subject) {
    header("Location: manage_subjects.php");
    exit();
}

// Subjects added before migration 009's seeding (or by other tools) get
// the defaults the first time they're opened here.
if (!obe_subject_outcomes($conn, [$subject_id])) {
    obe_seed_subject($conn, $subject_id);
}
$plos = obe_plos($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $error = "Your session expired or the request could not be verified. Please try again.";

} elseif (isset($_POST['save_outcomes'])) {
    $clos   = obe_subject_outcomes($conn, [$subject_id])[$subject_id];
    $by_code = array_column($clos, null, 'clo_code');
    $posted = is_array($_POST['clo'] ?? null) ? $_POST['clo'] : [];
    $map    = is_array($_POST['map'] ?? null) ? $_POST['map'] : [];

    $updates = [];
    foreach ($by_code as $code => $clo) {
        $in    = is_array($posted[$code] ?? null) ? $posted[$code] : [];
        $desc  = trim(is_string($in['description'] ?? null) ? $in['description'] : '');
        $bloom = is_string($in['bloom'] ?? null) ? $in['bloom'] : '';
        $plo   = (int)($in['plo'] ?? 0);
        if ($desc === '' || mb_strlen($desc) > 255) {
            $error = "$code needs a description (max 255 characters).";
        } elseif (!isset(BLOOM_LEVELS[$bloom])) {
            $error = "Choose a Bloom's level for $code.";
        } elseif (!isset($plos[$plo])) {
            $error = "Choose a PLO for $code.";
        }
        if ($error) break;
        $updates[] = [$desc, $bloom, $plo, $clo['id']];
    }
    $mapping = [];
    if (!$error) {
        foreach (MARK_FIELDS as $component => $label) {
            $code = is_string($map[$component] ?? null) ? $map[$component] : '';
            if (!isset($by_code[$code])) {
                $error = "Choose the CLO that $label assesses.";
                break;
            }
            $mapping[$component] = $by_code[$code]['id'];
        }
    }

    if (!$error) {
        mysqli_begin_transaction($conn);
        foreach ($updates as [$desc, $bloom, $plo, $clo_id]) {
            $stmt = mysqli_prepare($conn, "UPDATE subject_clos SET clo_description = ?, bloom_level = ?, plo_id = ? WHERE id = ? AND subject_id = ?");
            mysqli_stmt_bind_param($stmt, "ssiii", $desc, $bloom, $plo, $clo_id, $subject_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        foreach ($mapping as $component => $clo_id) {
            $stmt = mysqli_prepare($conn, "INSERT INTO clo_assessments (subject_id, component, clo_id) VALUES (?, ?, ?)
                                           ON DUPLICATE KEY UPDATE clo_id = VALUES(clo_id)");
            mysqli_stmt_bind_param($stmt, "isi", $subject_id, $component, $clo_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
        $summary = implode('; ', array_map(fn($c, $id) => MARK_FIELDS[$c] . ' → ' . array_column($clos, 'clo_code', 'id')[$id], array_keys($mapping), $mapping));
        audit_log($conn, 'Subject outcomes edited', "Subject: {$subject['subject_code']} | $summary");
        mysqli_commit($conn);
        $success = "Learning outcomes for {$subject['subject_code']} saved.";
    }
}

redirect_after_success($success, $error);

$semester_id = get_active_semester_id($conn);
$clos        = obe_cohort_clo($conn, $subject_id, $semester_id);
$component_clo = [];
foreach ($clos as $clo) {
    foreach ($clo['components'] as $c) $component_clo[$c] = $clo['clo_code'];
}
?>
<?php
$page_title = 'Learning Outcomes — ' . $subject['subject_code'];
$page_styles = <<<'CSS'
        .container { position: relative; z-index: 100; }
        .form-control, .form-select { background: #F8FAFC; border: 1px solid #E2E8F0; font-size: 0.88rem; border-radius: 8px; }
        .form-control:focus, .form-select:focus { box-shadow: 0 0 0 3px rgba(13,115,119,0.15); border-color: #0D7377; background: #fff; }
CSS;
require '../includes/page_start.php';
?>
    <div class="container mb-5" style="max-width:100%; margin-left:0;">
    <div class="page-header">
        <div>
            <h2><?php echo h($subject['subject_code']); ?> Learning Outcomes</h2>
            <p><?php echo h($subject['subject_name']); ?> · <?php echo h(number_format((float)$subject['credit_hours'], 1)); ?> credit hours</p>
        </div>
        <a href="manage_subjects.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Subjects</a>
    </div>

    <form method="POST">
        <?php echo csrf_field(); ?>
        <div class="dashboard-card">
            <div class="card-header-title"><i class="bi bi-list-check text-primary"></i>Course Learning Outcomes (CLOs)</div>
            <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-custom-header">
                    <tr>
                        <th class="ps-3" style="width:70px;">CLO</th>
                        <th>Description</th>
                        <th style="width:210px;">Bloom's Level</th>
                        <th style="width:250px;">Programme Outcome</th>
                        <th class="text-center" style="width:140px;">Attained this semester</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clos as $clo): $code = $clo['clo_code']; ?>
                    <tr>
                        <td class="ps-3 fw-bold" style="color:#1A3A5C;"><?php echo h($code); ?></td>
                        <td>
                            <input type="text" name="clo[<?php echo h($code); ?>][description]" class="form-control" required maxlength="255"
                                   value="<?php echo h($clo['clo_description']); ?>" aria-label="<?php echo h($code); ?> description">
                            <?php if (!$clo['components']): ?>
                                <small class="text-danger d-block mt-1" style="font-size:0.75rem;">No mark component assesses this CLO, so it can't be measured.</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select name="clo[<?php echo h($code); ?>][bloom]" class="form-select" aria-label="<?php echo h($code); ?> Bloom's level">
                                <?php foreach (['Cognitive' => 'C', 'Psychomotor' => 'P', 'Affective' => 'A'] as $domain => $prefix): ?>
                                <optgroup label="<?php echo $domain; ?>">
                                    <?php foreach (BLOOM_LEVELS as $lvl => $name): if ($lvl[0] !== $prefix) continue; ?>
                                        <option value="<?php echo $lvl; ?>" <?php echo $clo['bloom_level'] === $lvl ? 'selected' : ''; ?>><?php echo "$lvl — $name"; ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="clo[<?php echo h($code); ?>][plo]" class="form-select" aria-label="<?php echo h($code); ?> programme outcome">
                                <?php foreach ($plos as $plo): ?>
                                    <option value="<?php echo (int)$plo['id']; ?>" <?php echo $clo['plo_id'] === (int)$plo['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($plo['plo_code'] . ' — ' . $plo['plo_title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="text-center">
                            <?php if ($clo['pct'] === null): ?>
                                <span class="<?php echo status_badge_class(null); ?>">Not assessed</span>
                            <?php else: ?>
                                <span class="<?php echo status_badge_class($clo['cqi_required'] ? 'Fail' : 'Excellent'); ?>">
                                    <?php echo number_format($clo['pct'], 0); ?>% <span class="fw-normal">(<?php echo $clo['attained']; ?>/<?php echo $clo['assessed']; ?>)</span>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="dashboard-card">
                    <div class="card-header-title"><i class="bi bi-diagram-3 text-primary"></i>What Each Mark Assesses</div>
                    <p class="text-muted" style="font-size:0.82rem;">A CLO assessed by more than one component scores their weighted average.</p>
                    <?php foreach (MARK_FIELDS as $component => $label): ?>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-6 form-label mb-0" for="map_<?php echo $component; ?>">
                            <?php echo h($label); ?> <span class="text-muted fw-normal">(<?php echo MARK_WEIGHTS[$component] * 100; ?>%)</span>
                        </label>
                        <div class="col-6">
                            <select name="map[<?php echo $component; ?>]" id="map_<?php echo $component; ?>" class="form-select">
                                <?php foreach ($clos as $clo): ?>
                                    <option value="<?php echo h($clo['clo_code']); ?>" <?php echo ($component_clo[$component] ?? '') === $clo['clo_code'] ? 'selected' : ''; ?>><?php echo h($clo['clo_code']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" name="save_outcomes" class="btn btn-primary-custom w-100 mt-3"><i class="bi bi-check-lg me-1"></i>Save Outcomes</button>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="dashboard-card">
                    <div class="card-header-title"><i class="bi bi-mortarboard text-primary"></i>Programme Learning Outcomes</div>
                    <table class="table mb-0" style="font-size:0.84rem;">
                        <thead><tr><th>PLO</th><th>Outcome</th><th class="text-center">Target</th><th class="text-center">Class KPI</th></tr></thead>
                        <tbody>
                        <?php foreach ($plos as $plo): ?>
                            <tr title="<?php echo h($plo['description']); ?>">
                                <td class="fw-bold"><?php echo h($plo['plo_code']); ?></td>
                                <td><?php echo h($plo['plo_title']); ?></td>
                                <td class="text-center"><?php echo number_format((float)$plo['target_threshold'], 0); ?>%</td>
                                <td class="text-center"><?php echo number_format((float)$plo['cohort_kpi_pct'], 0); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="text-muted mt-3 mb-0" style="font-size:0.78rem;">
                        A student attains a CLO when their score on it reaches its PLO's target. When fewer than the KPI share
                        of the class attain a CLO, the lecturer is asked to record a CQI action.
                    </p>
                </div>
            </div>
        </div>
    </form>
    </div>
<?php require '../includes/page_end.php'; ?>
