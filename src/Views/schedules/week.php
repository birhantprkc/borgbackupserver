<?php
$dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$dayLabelsLong = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$pxPerHour = 72;
$gridHeight = 24 * $pxPerHour;
// A block is at least $minBlockPx tall so its text is readable. For the
// lane algorithm we need to reserve at least this many minutes of vertical
// space so short back-to-back blocks don't visually overlap.
$minBlockPx = 28;
$minBlockMin = max(1, (int) ceil($minBlockPx * 60 / $pxPerHour));

// Group blocks by day so we can render just one day at a time, and compute
// per-day lane layout for overlapping blocks.
$blocksByDay = [0 => [], 1 => [], 2 => [], 3 => [], 4 => [], 5 => [], 6 => []];
foreach ($blocks as $b) {
    $blocksByDay[$b['day_idx']][] = $b;
}
foreach ($blocksByDay as &$dayBlocks) {
    usort($dayBlocks, fn($a, $b) => $a['start_min'] <=> $b['start_min']);
    $lanes = [];
    foreach ($dayBlocks as &$blk) {
        // Use the RENDERED height (in minutes) for lane packing so that short
        // blocks we've inflated to the min-height don't get another block
        // drawn on top of them.
        $renderedMin = max($blk['duration_min'], $minBlockMin);
        $placed = false;
        foreach ($lanes as $laneIdx => $laneEnd) {
            if ($blk['start_min'] >= $laneEnd) {
                $blk['lane'] = $laneIdx;
                $lanes[$laneIdx] = $blk['start_min'] + $renderedMin;
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            $blk['lane'] = count($lanes);
            $lanes[$blk['lane']] = $blk['start_min'] + $renderedMin;
        }
    }
    unset($blk);
    $laneCount = max(1, count($lanes));
    foreach ($dayBlocks as &$blk) {
        $blk['lane_count'] = $laneCount;
    }
    unset($blk);
}
unset($dayBlocks);

$todayIdx = ((int) (new \DateTime('now', new \DateTimeZone($userTz)))->format('N')) - 1;

function bbs_agent_color(int $id): string
{
    $hue = ($id * 137) % 360;
    return "hsl({$hue}, 55%, 45%)";
}

$maxHistCount = 0;
foreach ($histogram as $h) {
    if ($h['total'] > $maxHistCount) $maxHistCount = $h['total'];
}
?>

<style>
.hist-container {
    position: relative;
    height: 120px;
    display: grid;
    grid-template-columns: 48px repeat(24, 1fr);
    column-gap: 2px;
    align-items: end;
}
.hist-bar-wrap {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    height: 100%;
    padding-bottom: 18px;
}
.hist-bar {
    width: 100%;
    display: flex;
    flex-direction: column-reverse;
    border-radius: 3px 3px 0 0;
    overflow: hidden;
    min-height: 1px;
}
.hist-seg {
    width: 100%;
    border-top: 1px solid rgba(0, 0, 0, 0.2);
}
.hist-hour-label {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 0.65rem;
    color: var(--bs-secondary-color);
}
.hist-hour-label.major { font-weight: 600; color: var(--bs-body-color); }
.hist-count-label {
    position: absolute;
    bottom: 20px;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--bs-body-color);
    pointer-events: none;
}
.hist-yaxis {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    font-size: 0.65rem;
    color: var(--bs-secondary-color);
    padding: 0 6px 18px 0;
    text-align: right;
}

.day-pills {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.day-pill {
    padding: 4px 14px;
    border-radius: 999px;
    border: 1px solid var(--bs-border-color);
    background: var(--bs-body-bg);
    color: var(--bs-body-color);
    text-decoration: none;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.12s;
}
.day-pill:hover {
    border-color: var(--bs-primary);
    color: var(--bs-primary);
}
.day-pill.active {
    background: var(--bs-primary);
    color: #fff;
    border-color: var(--bs-primary);
}
.day-pill.today {
    border-color: rgba(54, 162, 235, 0.7);
}
.day-pill .pill-count {
    opacity: 0.7;
    font-size: 0.75rem;
    margin-left: 4px;
}

.day-timeline {
    display: grid;
    grid-template-columns: 56px 1fr;
    gap: 8px;
    padding: 8px;
    background: var(--bs-body-bg);
    border-radius: 8px;
}
.day-hours {
    position: relative;
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
}
.day-hours .hour-label {
    position: absolute;
    right: 6px;
    transform: translateY(-50%);
    padding: 2px 0;
    background: var(--bs-body-bg);
}
.day-col {
    position: relative;
    border-left: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
    border-radius: 4px;
    overflow: hidden;
}
.day-col .hour-line {
    position: absolute;
    left: 0;
    right: 0;
    height: 1px;
    background: var(--bs-border-color);
    opacity: 0.35;
}
.day-col .hour-line.major { opacity: 0.6; }
.day-block {
    position: absolute;
    padding: 5px 10px;
    border-radius: 5px;
    color: #fff;
    font-size: 0.85rem;
    line-height: 1.25;
    overflow: hidden;
    cursor: pointer;
    border-left: 4px solid rgba(0, 0, 0, 0.35);
    transition: opacity 0.15s, transform 0.15s;
    text-decoration: none;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
.day-block:hover {
    transform: scale(1.01);
    z-index: 10;
    color: #fff;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
}
.day-block.estimated {
    background-image: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 8px,
        rgba(255, 255, 255, 0.12) 8px,
        rgba(255, 255, 255, 0.12) 16px
    );
}
.day-block .plan {
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.day-block .meta {
    opacity: 0.9;
    font-size: 0.72rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dim {
    opacity: 0.12 !important;
    pointer-events: none;
}
</style>

<div class="container-fluid py-3">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-calendar-week me-2"></i>Schedules</h4>
            <div class="text-muted small">Times shown in <?= htmlspecialchars($userTz) ?></div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small text-muted">Client:</label>
            <select id="agent-filter" class="form-select form-select-sm" style="width: auto;">
                <option value="">All</option>
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

    <!-- Histogram: hour-of-day load -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-body fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="bi bi-bar-chart me-1"></i>Load by hour (daily + the selected day's weekly schedules)</span>
            <span class="text-muted small">peak: <?= $maxHistCount ?> <?= $maxHistCount === 1 ? 'backup' : 'backups' ?></span>
        </div>
        <div class="card-body py-3">
            <div class="hist-container" id="histogram">
                <div class="hist-yaxis">
                    <span><?= $maxHistCount ?></span>
                    <span><?= (int) ceil($maxHistCount / 2) ?></span>
                    <span>0</span>
                </div>
                <?php for ($h = 0; $h < 24; $h++): ?>
                <?php
                    $bar = $histogram[$h];
                    $total = $bar['total'];
                    $barHeightPct = $maxHistCount > 0 ? ($total / $maxHistCount) * 100 : 0;
                    $isMajor = ($h % 6 === 0) || $h === 23;
                    $hourLabel = $h === 0 ? '12a' : ($h < 12 ? "{$h}a" : ($h === 12 ? '12p' : ($h - 12) . 'p'));
                ?>
                <div class="hist-bar-wrap" data-hour="<?= $h ?>">
                    <?php if ($total > 0): ?>
                    <div class="hist-count-label"><?= $total ?></div>
                    <?php endif; ?>
                    <div class="hist-bar" style="height: <?= $barHeightPct ?>%;">
                        <?php foreach ($bar['agents'] as $aid => $count): ?>
                        <div class="hist-seg"
                             data-agent-id="<?= (int) $aid ?>"
                             style="flex: <?= (int) $count ?>; background: <?= bbs_agent_color((int) $aid) ?>;"
                             title="<?= htmlspecialchars($shownAgents[$aid] ?? 'Unknown') ?>: <?= (int) $count ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="hist-hour-label <?= $isMajor ? 'major' : '' ?>"><?= $isMajor ? $hourLabel : '' ?></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- Day picker + timeline -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold"><i class="bi bi-calendar-day me-1"></i>Day view</span>
                <div class="day-pills" id="day-pills">
                    <?php foreach ($dayLabels as $idx => $label): ?>
                    <?php $count = count($blocksByDay[$idx]); ?>
                    <button type="button"
                            class="day-pill <?= $idx === $todayIdx ? 'today' : '' ?>"
                            data-day-idx="<?= $idx ?>">
                        <?= $idx === $todayIdx ? 'Today' : $label ?>
                        <?php if ($count > 0): ?><span class="pill-count"><?= $count ?></span><?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="card-body p-2">
            <div class="day-timeline">
                <div class="day-hours" style="height: <?= $gridHeight ?>px;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <div class="hour-label" style="top: <?= $h * $pxPerHour ?>px;">
                        <?= $h === 0 ? '12 AM' : ($h < 12 ? "{$h} AM" : ($h === 12 ? '12 PM' : ($h - 12) . ' PM')) ?>
                    </div>
                    <?php endfor; ?>
                </div>
                <div class="day-col" id="day-col" style="height: <?= $gridHeight ?>px;">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <div class="hour-line <?= $h % 6 === 0 ? 'major' : '' ?>" style="top: <?= $h * $pxPerHour ?>px;"></div>
                    <?php endfor; ?>

                    <?php for ($dIdx = 0; $dIdx < 7; $dIdx++): ?>
                    <div class="day-content" data-day-idx="<?= $dIdx ?>" style="<?= $dIdx === $todayIdx ? '' : 'display: none;' ?>">
                        <?php foreach ($blocksByDay[$dIdx] as $b): ?>
                            <?php
                            $top = $b['start_min'] * ($pxPerHour / 60);
                            $height = max($minBlockPx, $b['duration_min'] * ($pxPerHour / 60));
                            $laneWidth = 100 / $b['lane_count'];
                            $left = $b['lane'] * $laneWidth;
                            $color = bbs_agent_color($b['agent_id']);
                            $durLabel = $b['duration_min'] >= 60
                                ? floor($b['duration_min'] / 60) . 'h ' . ($b['duration_min'] % 60) . 'm'
                                : $b['duration_min'] . 'm';
                            $title = sprintf(
                                "%s\nClient: %s\nStarts: %s (%s)\nEstimated duration: %s%s",
                                $b['plan_name'],
                                $b['agent_name'],
                                $b['time_label'],
                                ucfirst($b['frequency']),
                                $durLabel,
                                $b['estimated'] ? ' (no history)' : ''
                            );
                            ?>
                        <a class="day-block <?= $b['estimated'] ? 'estimated' : '' ?>"
                           data-agent-id="<?= $b['agent_id'] ?>"
                           href="/clients/<?= $b['agent_id'] ?>?tab=schedules"
                           style="top: <?= $top ?>px; height: <?= $height ?>px; left: calc(<?= $left ?>% + 4px); width: calc(<?= $laneWidth ?>% - 8px); background: <?= $color ?>;"
                           title="<?= htmlspecialchars($title) ?>">
                            <div class="plan"><?= htmlspecialchars($b['plan_name']) ?></div>
                            <?php if ($height >= 40): ?>
                            <div class="meta"><?= htmlspecialchars($b['agent_name']) ?> · <?= htmlspecialchars($b['time_label']) ?> · <?= htmlspecialchars($durLabel) ?></div>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                        <?php if (empty($blocksByDay[$dIdx])): ?>
                        <div class="d-flex align-items-center justify-content-center text-muted" style="height: <?= $gridHeight ?>px; font-style: italic;">
                            No schedules for <?= $dayLabelsLong[$dIdx] ?>.
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($continuous)): ?>
    <div class="card border-0 shadow-sm mb-3">
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
    <div class="card border-0 shadow-sm mb-3">
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
    const pills = document.querySelectorAll('.day-pill');
    const contents = document.querySelectorAll('.day-content');
    const filter = document.getElementById('agent-filter');

    function showDay(idx) {
        pills.forEach(p => p.classList.toggle('active', Number(p.dataset.dayIdx) === idx));
        contents.forEach(c => c.style.display = (Number(c.dataset.dayIdx) === idx) ? '' : 'none');
    }

    // Default active = today
    const today = <?= $todayIdx ?>;
    showDay(today);

    pills.forEach(p => p.addEventListener('click', () => showDay(Number(p.dataset.dayIdx))));

    if (filter) {
        filter.addEventListener('change', function () {
            const agentId = this.value;
            document.querySelectorAll('[data-agent-id]').forEach(function (el) {
                if (!agentId || el.dataset.agentId === agentId) {
                    el.classList.remove('dim');
                    if (el.classList.contains('col-md-6')) el.style.display = '';
                } else {
                    if (el.classList.contains('day-block') || el.classList.contains('hist-seg')) {
                        el.classList.add('dim');
                    } else {
                        el.style.display = 'none';
                    }
                }
            });
        });
    }
})();
</script>
