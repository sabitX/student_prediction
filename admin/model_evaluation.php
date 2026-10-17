<?php
require '../includes/session_check.php';
require '../config/config.php';
require '../includes/semester.php';
require '../includes/early_warning.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// ── How accurate is the early warning? ─────────────────────────────────
// Replays every finished result as if the final exam weren't marked yet
// and compares the warning with the real outcome (ew_backtest() in
// includes/early_warning.php). Read-only.
$results = ew_backtest($conn);

function pct_text($v) {
    return $v === null ? '—' : number_format($v * 100, 0) . '%';
}

$page_title  = 'Early-Warning Accuracy';
$page_styles = <<<'CSS'
        .container { position: relative; z-index: 100; }
        .metric { text-align: center; padding: 14px 8px; border-radius: 10px; background: #F8FAFC; }
        .metric .v { font-size: 1.5rem; font-weight: 800; color: #1A3A5C; line-height: 1.1; }
        .metric .l { font-size: 0.75rem; color: #64748B; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
        .metric .d { font-size: 0.72rem; color: #64748B; margin-top: 4px; }
        [data-bs-theme="dark"] .metric .v { color: #E2E8F0; }
        .confusion td, .confusion th { text-align: center; font-size: 0.85rem; padding: 8px !important; }
CSS;
require '../includes/page_start.php';
?>
    <div class="container mb-5" style="max-width:100%; margin-left:0;">
    <div class="page-header">
        <div>
            <h2>Early-Warning Accuracy</h2>
            <p>Every finished result, replayed as if the final exam weren't marked yet. Would the early warning have flagged the students who ended below <?php echo PASS_MARK; ?>%?</p>
        </div>
    </div>

    <?php $n_total = reset($results)['n']; ?>
    <?php if ($n_total === 0): ?>
        <div class="dashboard-card"><div class="empty-state"><i class="bi bi-clipboard-data"></i><p class="mb-0">No finished results yet — accuracy can be measured once students have all four marks.</p></div></div>
    <?php else: ?>
        <?php if ($n_total < 30): ?>
        <div class="alert py-2 mb-4" style="background:#FBF0DC; border:1px solid #F0DDB4; color:#8A5508; font-size:0.88rem; border-radius:8px;">
            <i class="bi bi-info-circle-fill me-1"></i>
            Only <?php echo $n_total; ?> finished results so far. The figures below are shown for completeness, but are too few to draw conclusions from.
        </div>
        <?php endif; ?>

        <?php foreach ($results as $key => $m): ?>
        <div class="dashboard-card">
            <div class="card-header-title">
                <i class="bi bi-clock-history text-primary"></i><?php echo h($m['label']); ?>
                <span class="text-muted fw-normal" style="font-size:0.85rem;">
                    (known: <?php echo h(implode(', ', array_map(fn($c) => str_replace('_', ' ', $c), BACKTEST_CHECKPOINTS[$key]['known']))); ?> · <?php echo $m['n']; ?> results)
                </span>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3"><div class="metric"><div class="v"><?php echo pct_text($m['recall']); ?></div><div class="l">Caught</div><div class="d">of students who ended below <?php echo PASS_MARK; ?>% were flagged (recall)</div></div></div>
                <div class="col-6 col-md-3"><div class="metric"><div class="v"><?php echo pct_text($m['precision']); ?></div><div class="l">Right when flagged</div><div class="d">of flagged students did end below <?php echo PASS_MARK; ?>% (precision)</div></div></div>
                <div class="col-6 col-md-3"><div class="metric"><div class="v"><?php echo pct_text($m['accuracy']); ?></div><div class="l">Accuracy</div><div class="d">flag / no flag matched the outcome</div></div></div>
                <div class="col-6 col-md-3"><div class="metric"><div class="v"><?php echo number_format($m['mae'], 1); ?></div><div class="l">Avg. error (points)</div>
                    <div class="d">projected vs final total · coursework only: <?php echo number_format($m['mae_no_prior'], 1); ?></div></div></div>
            </div>
            <div class="table-responsive" style="max-width:520px;">
            <table class="table table-bordered confusion mb-0">
                <thead><tr><th></th><th>Ended below <?php echo PASS_MARK; ?>%</th><th>Ended <?php echo PASS_MARK; ?>% or above</th></tr></thead>
                <tbody>
                    <tr><th class="text-start">Flagged</th><td><strong><?php echo $m['tp']; ?></strong> caught</td><td><?php echo $m['fp']; ?> false alarm<?php echo $m['fp'] == 1 ? '' : 's'; ?></td></tr>
                    <tr><th class="text-start">Not flagged</th><td><?php echo $m['fn']; ?> missed</td><td><strong><?php echo $m['tn']; ?></strong> correctly clear</td></tr>
                </tbody>
            </table>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="dashboard-card">
            <div class="card-header-title"><i class="bi bi-info-circle text-primary"></i>How this is measured</div>
            <ul class="mb-0" style="font-size:0.86rem; color:#475569;">
                <li>Each result with all four marks is replayed with the later marks hidden, and the same rules the dashboards use decide whether it would have been flagged (any warning: attendance below <?php echo ATTENDANCE_BAR; ?>%, final-exam target above <?php echo HIGH_FINAL_TARGET; ?>%, projection below <?php echo PASS_MARK; ?>%, or <?php echo abs(Z_FLAG); ?>+ standard deviations below the class).</li>
                <li>Class averages are taken from the same replayed marks, so nothing from the final exam leaks into the prediction.</li>
                <li>"Coursework only" is the error without blending in the student's earlier results or the class average. Lower is better: if the blended error is lower, the prior helps.</li>
                <li>A missed student is costlier than a false alarm (a false alarm costs one mentorship offer), so "Caught" is the figure to watch.</li>
            </ul>
        </div>
    <?php endif; ?>
    </div>
<?php require '../includes/page_end.php'; ?>
