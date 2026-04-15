<?php
// Group overlapping blocks per day so we can render them side-by-side
$blocksByDay = [0 => [], 1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => []];
foreach ($blocks as $b) {
    $blocksByDay[$b['day_idx']][] = $b;
}

// For each day, compute a "lane" for each block so overlapping blocks stack side-by-side
foreach ($blocksByDay as &$dayBlocks) {
    usort($dayBlocks, fn($a, $b) => $a['start_min'] <=> $b['start_min']);
    $lanes = []; // laneIdx => latest end_min seen
    foreach ($dayBlocks as &$blk) {
        $placed = false;
        foreach ($lanes as $laneIdx => $laneEnd) {
            if ($blk['start_min'] >= $laneEnd) {
                $blk['lane'] = $laneIdx;
                $lanes[$laneIdx] = $blk['start_min'] + $blk['duration_min'];
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            $blk['lane'] = count($lanes);
            $lanes[$blk['lane']] = $blk['start_min'] + $blk['duration_min'];
        }
    }
    unset($blk);
    // Stash lane count on every block in this day so the renderer can size them
    $laneCount = max(1, count($lanes));
    foreach ($dayBlocks as &$blk) {
        $blk['lane_count'] = $laneCount;
    }
    unset($blk);
}
unset($dayBlocks);

$dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$pxPerHour = 40; // 24h × 40 = 960px grid height
$gridHeight = 24 * $pxPerHour;

// Stable color per agent id (HSL hashed from id)
function bbs_agent_color(int $id): string
{
    $hue = ($id * 137) % 360;
    return "hsl({$hue}, 55%, 42%)";
}
?>

<style>
.sched-grid {
    display: grid;
    grid-template-columns: 48px repeat(7, 1fr);
    gap: 4px;
    background: var(--bs-body-bg);
    border-radius: 8px;
    padding: 8px;
}
.sched-col-header {
    text-align: center;
    font-weight: 600;
    padding: 8px 0;
    font-size: 0.85rem;
    color: var(--bs-body-color);
}
.sched-col-header.today {
    background: rgba(54, 162, 235, 0.15);
    border-radius: 4px;
}
.sched-hours {
    position: relative;
    font-size: 0.7rem;
    color: var(--bs-secondary-color);
}
.sched-hours .hour-label {
    position: absolute;
    right: 4px;
    transform: translateY(-50%);
}
.sched-day-col {
    position: relative;
    border-left: 1px solid var(--bs-border-color);
    background: var(--bs-body-bg);
}
.sched-day-col .hour-line {
    position: absolute;
    left: 0;
    right: 0;
    height: 1px;
    background: var(--bs-border-color);
    opacity: 0.3;
}
.sched-day-col.today {
    background: rgba(54, 162, 235, 0.05);
}
.sched-block {
    position: absolute;
    left: 2px;
    right: 2px;
    padding: 3px 6px;
    border-radius: 4px;
    color: #fff;
    font-size: 0.72rem;
    line-height: 1.2;
    overflow: hidden;
    cursor: pointer;
    border-left: 3px solid rgba(0, 0, 0, 0.35);
    transition: opacity 0.15s, transform 0.15s;
    text-decoration: none;
}
.sched-block:hover {
    transform: scale(1.02);
    z-index: 10;
    color: #fff;
}
.sched-block.estimated {
    background-image: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 6px,
        rgba(255, 255, 255, 0.12) 6px,
        rgba(255, 255, 255, 0.12) 12px
    );
}
.sched-block .sched-plan {
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sched-block .sched-meta {
    opacity: 0.85;
    font-size: 0.65rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sched-block.dim {
    opacity: 0.15;
    pointer-events: none;
}
</style>

<div class="container-fluid py-3">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-calendar-week me-2"></i>Weekly Schedule</h4>
            <div class="text-muted small">Times shown in <?= htmlspecialchars($userTz) ?></div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small text-muted">Filter:</label>
            <select id="agent-filter" class="form-select form-select-sm" style="width: auto;">
                <option value="">All clients</option>
                <?php foreach ($shownAgents as $aid => $aname): ?>
                <option value="<?= (int) $aid ?>"><?= htmlspecialchars($aname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (empty($blocks) && empty($continuous) && empty($otherSchedules)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
            No enabled schedules found. Create a backup plan with a schedule to see it here.
        </div>
    </div>
    <?php else: ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-2">
            <?php
            $todayIdx = ((int) (new \DateTime('now', new \DateTimeZone($userTz)))->format('N')) - 1;
            ?>
            <div class="sched-grid">
                <!-- Header row -->
                <div></div>
                <?php foreach ($dayLabels as $idx => $label): ?>
                <div class="sched-col-header <?= $idx === $todayIdx ? 'today' : '' ?>"><?= $label ?></div>
                <?php endforeach; ?>

                <!-- Hours column -->
                <div class="sched-hours" style="height: <?= $gridHeight ?>px;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <div class="hour-label" style="top: <?= $h * $pxPerHour ?>px;">
                        <?= ($h % 12 === 0 ? 12 : $h % 12) . ($h < 12 ? 'a' : 'p') ?>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- Day columns -->
                <?php foreach ($dayLabels as $idx => $label): ?>
                <div class="sched-day-col <?= $idx === $todayIdx ? 'today' : '' ?>" style="height: <?= $gridHeight ?>px;">
                    <?php for ($h = 1; $h < 24; $h++): ?>
                    <div class="hour-line" style="top: <?= $h * $pxPerHour ?>px;"></div>
                    <?php endfor; ?>

                    <?php foreach ($blocksByDay[$idx] as $b): ?>
                        <?php
                        $top = $b['start_min'] * ($pxPerHour / 60);
                        $height = max(22, $b['duration_min'] * ($pxPerHour / 60));
                        $laneWidth = 100 / $b['lane_count'];
                        $left = $b['lane'] * $laneWidth;
                        $color = bbs_agent_color($b['agent_id']);
                        $title = sprintf(
                            "%s\nClient: %s\nStarts: %s (%s)\nEstimated duration: %s%s",
                            $b['plan_name'],
                            $b['agent_name'],
                            $b['time_label'],
                            ucfirst($b['frequency']),
                            $b['duration_min'] >= 60 ? floor($b['duration_min'] / 60) . 'h ' . ($b['duration_min'] % 60) . 'm' : $b['duration_min'] . 'm',
                            $b['estimated'] ? ' (no history — default)' : ''
                        );
                        ?>
                    <a class="sched-block <?= $b['estimated'] ? 'estimated' : '' ?>"
                       data-agent-id="<?= $b['agent_id'] ?>"
                       data-plan-id="<?= $b['plan_id'] ?>"
                       href="/clients/<?= $b['agent_id'] ?>?tab=schedules"
                       style="top: <?= $top ?>px; height: <?= $height ?>px; left: calc(<?= $left ?>% + 2px); width: calc(<?= $laneWidth ?>% - 4px); background: <?= $color ?>;"
                       title="<?= htmlspecialchars($title) ?>">
                        <div class="sched-plan"><?= htmlspecialchars($b['plan_name']) ?></div>
                        <?php if ($height >= 32): ?>
                        <div class="sched-meta"><?= htmlspecialchars($b['agent_name']) ?> · <?= htmlspecialchars($b['time_label']) ?></div>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($continuous)): ?>
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-body fw-semibold">
            <i class="bi bi-arrow-repeat me-1"></i>Continuous schedules
        </div>
        <div class="card-body">
            <div class="row g-2 small">
                <?php foreach ($continuous as $c): ?>
                <?php $s = $c['schedule']; ?>
                <div class="col-md-6 col-lg-4" data-agent-id="<?= (int) $s['agent_id'] ?>">
                    <a href="/clients/<?= (int) $s['agent_id'] ?>?tab=schedules" class="d-block p-2 rounded text-decoration-none border"
                       style="border-left: 3px solid <?= bbs_agent_color((int) $s['agent_id']) ?> !important;">
                        <div class="fw-semibold"><?= htmlspecialchars($s['plan_name']) ?></div>
                        <div class="text-muted">Runs every <?= htmlspecialchars($c['interval_label']) ?> · <?= htmlspecialchars($s['agent_name']) ?></div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($otherSchedules)): ?>
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-body fw-semibold">
            <i class="bi bi-calendar-month me-1"></i>Monthly schedules
        </div>
        <div class="card-body">
            <div class="row g-2 small">
                <?php foreach ($otherSchedules as $s): ?>
                <div class="col-md-6 col-lg-4" data-agent-id="<?= (int) $s['agent_id'] ?>">
                    <a href="/clients/<?= (int) $s['agent_id'] ?>?tab=schedules" class="d-block p-2 rounded text-decoration-none border"
                       style="border-left: 3px solid <?= bbs_agent_color((int) $s['agent_id']) ?> !important;">
                        <div class="fw-semibold"><?= htmlspecialchars($s['plan_name']) ?></div>
                        <div class="text-muted">
                            <?= htmlspecialchars($s['agent_name']) ?>
                            <?php if (!empty($s['next_run'])): ?>
                            · Next run <?= \BBS\Core\TimeHelper::format($s['next_run'], 'M j, g:i A') ?>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
(function () {
    const filter = document.getElementById('agent-filter');
    if (!filter) return;
    filter.addEventListener('change', function () {
        const agentId = this.value;
        document.querySelectorAll('[data-agent-id]').forEach(function (el) {
            if (!agentId || el.dataset.agentId === agentId) {
                el.classList.remove('dim');
                el.style.display = '';
            } else {
                if (el.classList.contains('sched-block')) {
                    el.classList.add('dim');
                } else {
                    el.style.display = 'none';
                }
            }
        });
    });
})();
</script>
