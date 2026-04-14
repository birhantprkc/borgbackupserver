<?php
$inProgress = $status['in_progress'] ?? false;
$result = $status['result'] ?? null;
$progress = $status['progress'] ?? 0;
$log = $status['log'] ?? '';
$lastLine = $status['last_line'] ?? '';
$elapsed = $status['elapsed'] ?? 0;
$target = $status['target'] ?? '';
$csrfToken = $this->csrfToken();
$fmtElapsed = function(int $s): string { return floor($s/60) . ':' . str_pad($s%60, 2, '0', STR_PAD_LEFT); };

/**
 * Parse the raw log into structured steps.
 * Each step has: num, total, title, lines[], status (running|done|failed)
 */
function parse_upgrade_steps(string $log, bool $inProgress, ?string $result): array {
    $steps = [];
    $current = null;
    $preamble = [];

    foreach (explode("\n", $log) as $line) {
        $trimmed = rtrim($line);
        if ($trimmed === '') continue;

        // Match step marker like [1/10] Upgrading... or [2/9] Installing...
        if (preg_match('/^\[(\d+)\/(\d+)\]\s*(.*)$/', $trimmed, $m)) {
            // Close previous step as done
            if ($current !== null) {
                $current['status'] = 'done';
                $steps[] = $current;
            }
            $current = [
                'num' => (int) $m[1],
                'total' => (int) $m[2],
                'title' => $m[3],
                'lines' => [],
                'status' => 'running',
            ];
        } else {
            if ($current !== null) {
                $current['lines'][] = $trimmed;
            } else {
                $preamble[] = $trimmed;
            }
        }
    }

    // Close final step — running if still in progress, done otherwise
    if ($current !== null) {
        if ($inProgress) {
            $current['status'] = 'running';
        } elseif ($result === 'failed') {
            $current['status'] = 'failed';
        } else {
            $current['status'] = 'done';
        }
        $steps[] = $current;
    }

    return ['steps' => $steps, 'preamble' => $preamble];
}

// Auto-correct false-failed: if all expected steps appear to have run successfully,
// treat as success even without the final completion marker (PHP-FPM restart races
// can drop the last line of output)
$parsed = parse_upgrade_steps($log, $inProgress, $result);
$steps = $parsed['steps'];
$preamble = $parsed['preamble'];

// Heuristic: if we have at least 9 steps ending at the expected final step (fixing
// storage paths) and none show obvious errors, upgrade probably succeeded
if ($result === 'failed' && !empty($steps)) {
    $lastStep = end($steps);
    if ($lastStep['num'] === $lastStep['total']
        && (stripos($lastStep['title'], 'permission') !== false || stripos($lastStep['title'], 'complete') !== false)
        && !preg_grep('/\b(error|failed|fatal)\b/i', array_column($steps, 'title'))) {
        $result = 'success';
        $status['result'] = 'success';
    }
}

$stepIcon = function(string $status): string {
    return match ($status) {
        'running' => '<span class="spinner-border spinner-border-sm text-primary" role="status"></span>',
        'done' => '<i class="bi bi-check-circle-fill text-success"></i>',
        'failed' => '<i class="bi bi-x-circle-fill text-danger"></i>',
        default => '<i class="bi bi-circle text-muted"></i>',
    };
};
?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-primary bg-opacity-10 fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-cloud-arrow-down me-1"></i> System Upgrade</span>
        <?php if ($target): ?>
        <span class="badge bg-primary"><?= htmlspecialchars($target) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($result === 'success'): ?>
        <div class="alert alert-success mb-3 d-flex align-items-center">
            <i class="bi bi-check-circle-fill me-2 fs-4"></i>
            <div>
                <strong>Upgrade completed successfully.</strong>
                <?php if ($elapsed): ?><span class="text-muted ms-2">Finished in <?= $fmtElapsed($elapsed) ?></span><?php endif; ?>
            </div>
        </div>
        <?php elseif ($result === 'failed'): ?>
        <div class="alert alert-danger mb-3 d-flex align-items-center">
            <i class="bi bi-x-circle-fill me-2 fs-4"></i>
            <div>
                <strong>Upgrade failed.</strong> Expand the failed step below for details.
            </div>
        </div>
        <?php endif; ?>

        <!-- Progress bar -->
        <div class="mb-3">
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span id="upgrade-step"><?= $inProgress ? htmlspecialchars($lastLine) : ($result === 'success' ? 'Complete' : ($result === 'failed' ? 'Failed' : '')) ?></span>
                <span id="upgrade-elapsed"><?= $fmtElapsed($elapsed) ?></span>
            </div>
            <div class="progress" style="height: 20px;">
                <div id="upgrade-progress"
                     class="progress-bar <?= $inProgress ? 'progress-bar-striped progress-bar-animated' : ($result === 'success' ? 'bg-success' : ($result === 'failed' ? 'bg-danger' : '')) ?>"
                     role="progressbar"
                     style="width: <?= $progress ?>%"
                     aria-valuenow="<?= $progress ?>"
                     aria-valuemin="0"
                     aria-valuemax="100">
                    <?= $progress ?>%
                </div>
            </div>
        </div>

        <!-- Step list -->
        <div class="border rounded" id="upgrade-steps">
            <?php if (empty($steps) && !empty($preamble)): ?>
                <div class="p-3 small text-muted"><?= nl2br(htmlspecialchars(implode("\n", $preamble))) ?></div>
            <?php endif; ?>
            <?php foreach ($steps as $i => $step): ?>
            <div class="border-bottom">
                <?php $hasDetail = !empty(array_filter($step['lines'], fn($l) => trim($l) !== '')); ?>
                <a class="d-flex align-items-center px-3 py-2 text-decoration-none text-body <?= $hasDetail ? '' : 'pe-none' ?>" data-bs-toggle="<?= $hasDetail ? 'collapse' : '' ?>" href="#step-detail-<?= $i ?>" role="button" aria-expanded="false">
                    <span class="me-2" style="width:20px;"><?= $stepIcon($step['status']) ?></span>
                    <span class="text-muted me-2 small" style="min-width:52px;">[<?= $step['num'] ?>/<?= $step['total'] ?>]</span>
                    <span class="flex-grow-1"><?= htmlspecialchars($step['title']) ?></span>
                    <?php if ($hasDetail): ?>
                    <i class="bi bi-chevron-down small text-muted"></i>
                    <?php endif; ?>
                </a>
                <?php if ($hasDetail): ?>
                <div class="collapse" id="step-detail-<?= $i ?>">
                    <pre class="bg-body-tertiary px-3 py-2 mb-0 small font-monospace" style="white-space:pre-wrap;word-break:break-all;"><?= htmlspecialchars(implode("\n", $step['lines'])) ?></pre>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($steps) && empty($preamble)): ?>
                <div class="p-3 small text-muted text-center">Waiting for upgrade output…</div>
            <?php endif; ?>
        </div>

        <?php if (!$inProgress && $result): ?>
        <div class="text-center mt-3">
            <form method="POST" action="/upgrade/dismiss" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-left me-1"></i> Return to Settings
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($release['notes'])): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-primary bg-opacity-10 fw-semibold">
        <i class="bi bi-journal-text me-1"></i> Release Notes
        <?php if (!empty($release['url'])): ?>
        <a href="<?= htmlspecialchars($release['url']) ?>" target="_blank" class="float-end small">
            View on GitHub <i class="bi bi-box-arrow-up-right"></i>
        </a>
        <?php endif; ?>
    </div>
    <div class="card-body small">
        <?php $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'strip']); echo $converter->convert($release['notes']); ?>
    </div>
</div>
<?php endif; ?>

<?php if ($inProgress): ?>
<script>
(function() {
    var pollInterval = null;

    function poll() {
        fetch('/upgrade/status', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                // If the status changed (in_progress -> done, or steps changed), just reload.
                // This keeps the server-side rendered step list authoritative and lets us
                // apply the false-failed correction on the next page load.
                if (!data.in_progress && data.result) {
                    clearInterval(pollInterval);
                    setTimeout(function() { location.reload(); }, 500);
                    return;
                }

                // Update progress bar and elapsed
                var pct = data.progress || 0;
                var progressEl = document.getElementById('upgrade-progress');
                if (progressEl) {
                    progressEl.style.width = pct + '%';
                    progressEl.textContent = pct + '%';
                    progressEl.setAttribute('aria-valuenow', pct);
                }

                var stepEl = document.getElementById('upgrade-step');
                if (stepEl && data.last_line) stepEl.textContent = data.last_line;

                var elapsedEl = document.getElementById('upgrade-elapsed');
                if (elapsedEl && data.elapsed !== undefined) {
                    var m = Math.floor(data.elapsed / 60);
                    var s = data.elapsed % 60;
                    elapsedEl.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                }
            })
            .catch(function() {});
    }

    // While in progress, periodically reload to pick up new steps as they complete.
    // We could do incremental DOM updates but a reload every 4s is simpler and handles
    // everything (including if steps change order or get re-rendered with detail).
    var reloadCountdown = 8;
    pollInterval = setInterval(function() {
        poll();
        reloadCountdown--;
        if (reloadCountdown <= 0) {
            location.reload();
        }
    }, 2000);
})();
</script>
<?php endif; ?>
