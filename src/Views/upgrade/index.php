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
        <div class="alert alert-success mb-3">
            <i class="bi bi-check-circle-fill me-1"></i> Upgrade completed successfully.
        </div>
        <?php elseif ($result === 'failed'): ?>
        <div class="alert alert-danger mb-3">
            <i class="bi bi-x-circle-fill me-1"></i> Upgrade failed. Check the log below for details.
        </div>
        <?php endif; ?>

        <!-- Progress bar -->
        <div class="mb-2">
            <div class="d-flex justify-content-between small text-muted mb-1">
                <span id="upgrade-step"><?= $inProgress ? htmlspecialchars($lastLine) : ($result === 'success' ? 'Complete' : ($result === 'failed' ? 'Failed' : '')) ?></span>
                <span id="upgrade-elapsed"><?= $fmtElapsed($elapsed) ?></span>
            </div>
            <div class="progress" style="height: 24px;">
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

        <!-- Log output -->
        <div id="upgrade-log" class="bg-dark text-light p-3 rounded font-monospace small mb-3" style="height: 350px; overflow-y: auto; white-space: pre-wrap; font-size: 0.8rem;"><?= htmlspecialchars($log) ?></div>

        <?php if (!$inProgress && $result): ?>
        <div class="text-center">
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
    var logEl = document.getElementById('upgrade-log');
    var progressEl = document.getElementById('upgrade-progress');
    var stepEl = document.getElementById('upgrade-step');
    var elapsedEl = document.getElementById('upgrade-elapsed');
    var pollInterval = null;

    function formatElapsed(seconds) {
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function scrollToBottom() {
        logEl.scrollTop = logEl.scrollHeight;
    }

    function poll() {
        fetch('/upgrade/status', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                // Update log
                if (data.log) {
                    logEl.textContent = data.log;
                    scrollToBottom();
                }

                // Update progress bar
                var pct = data.progress || 0;
                progressEl.style.width = pct + '%';
                progressEl.textContent = pct + '%';
                progressEl.setAttribute('aria-valuenow', pct);

                // Update step text
                if (data.last_line) {
                    stepEl.textContent = data.last_line;
                }

                // Update elapsed
                if (data.elapsed !== undefined) {
                    elapsedEl.textContent = formatElapsed(data.elapsed);
                }

                // Check if done
                if (!data.in_progress && data.result) {
                    clearInterval(pollInterval);
                    progressEl.classList.remove('progress-bar-striped', 'progress-bar-animated');
                    if (data.result === 'success') {
                        progressEl.classList.add('bg-success');
                        progressEl.style.width = '100%';
                        progressEl.textContent = '100%';
                        stepEl.textContent = 'Complete';
                    } else {
                        progressEl.classList.add('bg-danger');
                        stepEl.textContent = 'Failed';
                    }
                    // Reload to show completion UI with dismiss button
                    setTimeout(function() { location.reload(); }, 500);
                }
            })
            .catch(function() {
                // Network error — keep polling, may be mid-restart
            });
    }

    scrollToBottom();
    pollInterval = setInterval(poll, 2000);
})();
</script>
<?php endif; ?>
