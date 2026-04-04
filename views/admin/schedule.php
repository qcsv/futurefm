<?php $pageTitle = SITE_NAME . ' — Schedule'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<?php
// ── Helpers ──────────────────────────────────────────────────────────────────

/** Format seconds as "1h 30m" or "45m". */
function fmtDur(int $secs): string {
    $h = intdiv($secs, 3600);
    $m = intdiv($secs % 3600, 60);
    if ($h > 0 && $m > 0) return "{$h}h {$m}m";
    if ($h > 0)            return "{$h}h";
    return "{$m}m";
}

// ── Constants ────────────────────────────────────────────────────────────────

$PX_PER_MIN    = 5;    // pixels per minute
$TOTAL_MINUTES = 120;  // midnight (00:00) to 2:00 AM
$GRID_HEIGHT   = $PX_PER_MIN * $TOTAL_MINUTES; // 600px

/**
 * CSS position style for a schedule block.
 */
function blockStyle(array $sched, int $durationSecs, int $pxPerMin, int $totalMin): string {
    [$h, $m] = array_map('intval', explode(':', $sched['time_of_day']));
    $minutes = $h * 60 + $m;
    $top     = $minutes * $pxPerMin;
    $durMin  = max(4, (int) round($durationSecs / 60)); // min 4 min visually
    $height  = min($durMin * $pxPerMin, ($totalMin * $pxPerMin) - $top);
    $height  = max(20, $height); // at least 20px
    return "top:{$top}px;height:{$height}px";
}

// ── Data organisation ─────────────────────────────────────────────────────────

$dayShort  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$dayFull   = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$colOrder  = [1, 2, 3, 4, 5, 6, 0]; // Mon → Sun
$todayDow  = (int) date('w');

// Group schedules by day of week (0-6)
$blocksByDay = array_fill(0, 7, []);
foreach ($schedules as $s) {
    if ($s['day_of_week'] !== null) {
        $blocksByDay[(int) $s['day_of_week']][] = $s;
    }
}

// Day loop settings (defaults to false)
$dayLoop = [];
for ($d = 0; $d < 7; $d++) {
    $dayLoop[$d] = !empty($dayLoopSettings[$d]);
}
?>

<?php /* Override site-main width for this page only */ ?>
<style>.site-main { max-width: none; padding: 1.5rem; }</style>

<section class="admin-page">
    <h1>Schedule</h1>

    <nav class="admin-nav">
        <a href="/admin">Dashboard</a>
        <a href="/admin/queue">Queue</a>
        <a href="/admin/playlists">Playlists</a>
        <a href="/admin/schedule">Schedule</a>
        <a href="/admin/users">Users &amp; Invites</a>
    </nav>

    <p class="library-meta">
        Drag a playlist from the panel onto any time slot to schedule it.
        Drag an existing block to move it. The clock is checked every ~10 s.
    </p>

    <div class="sched-layout">

        <!-- ── Sidebar ────────────────────────────────────────────── -->

        <aside class="sched-sidebar">
            <h2>Playlists</h2>

            <?php if (empty($playlists)): ?>
                <p class="library-meta">
                    No playlists yet.<br>
                    <a href="/admin/playlists">Create one first.</a>
                </p>
            <?php else: ?>
                <?php foreach ($playlists as $pl):
                    $dur = $playlistDurations[$pl['playlist']] ?? 0;
                ?>
                    <div class="playlist-drag-item"
                         draggable="true"
                         ondragstart="onSidebarDragStart(event, <?= json_encode($pl['playlist']) ?>, <?= $dur ?>)"
                         ondragend="this.classList.remove('dragging')">
                        <div class="playlist-drag-item-name"><?= htmlspecialchars($pl['playlist']) ?></div>
                        <div class="playlist-drag-item-meta">
                            <?= $dur > 0 ? htmlspecialchars(fmtDur($dur)) : '—' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </aside>

        <!-- ── Calendar ───────────────────────────────────────────── -->

        <div class="sched-calendar-outer">
            <div class="sched-calendar-scroll" id="calScroll">
                <div class="sched-calendar">

                    <!-- Time gutter -->
                    <div class="time-gutter">
                        <div class="cal-col-header"></div>
                        <div class="time-gutter-body" style="height:<?= $GRID_HEIGHT ?>px">
                            <?php for ($h = 0; $h <= 2; $h++): ?>
                                <div class="time-label" style="top:<?= $h * 60 * $PX_PER_MIN ?>px">
                                    <?= sprintf('%02d:00', $h) ?>
                                </div>
                            <?php endfor; ?>
                            <?php // Half-hour labels ?>
                            <?php for ($h = 0; $h < 2; $h++): ?>
                                <div class="time-label time-label--minor" style="top:<?= ($h * 60 + 30) * $PX_PER_MIN ?>px">
                                    <?= sprintf('%02d:30', $h) ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Mon – Sun columns -->
                    <?php foreach ($colOrder as $dow): ?>
                        <div class="cal-col">
                            <div class="cal-col-header <?= $todayDow === $dow ? 'cal-col-header--today' : '' ?>">
                                <span class="cal-col-header-day"><?= $dayShort[$dow] ?></span>
                                <label class="cal-col-header-loop" title="Loop last playlist on <?= $dayFull[$dow] ?>">
                                    <input type="checkbox"
                                           <?= $dayLoop[$dow] ? 'checked' : '' ?>
                                           onchange="toggleDayLoop(<?= $dow ?>, this.checked)">
                                    <span class="cal-col-header-loop-label">Loop</span>
                                </label>
                            </div>
                            <div class="cal-col-body"
                                 style="height:<?= $GRID_HEIGHT ?>px"
                                 data-day="<?= $dow ?>"
                                 ondragover="onDragOver(event)"
                                 ondragleave="onDragLeave(event)"
                                 ondrop="onDrop(event, <?= $dow ?>)">
                                <?php // Hour lines ?>
                                <?php for ($h = 0; $h <= 2; $h++): ?>
                                    <div class="hour-line" style="top:<?= $h * 60 * $PX_PER_MIN ?>px"></div>
                                <?php endfor; ?>
                                <?php // Half-hour lines ?>
                                <?php for ($h = 0; $h < 2; $h++): ?>
                                    <div class="hour-line hour-line--minor" style="top:<?= ($h * 60 + 30) * $PX_PER_MIN ?>px"></div>
                                <?php endfor; ?>
                                <?php // 15-min lines ?>
                                <?php for ($m = 0; $m < $TOTAL_MINUTES; $m += 15): ?>
                                    <?php if ($m % 30 !== 0): ?>
                                        <div class="hour-line hour-line--quarter" style="top:<?= $m * $PX_PER_MIN ?>px"></div>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php foreach ($blocksByDay[$dow] as $s): ?>
                                    <?php $dur = $playlistDurations[$s['playlist_name']] ?? 0; ?>
                                    <?php echo renderBlock($s, $dur, $PX_PER_MIN, $TOTAL_MINUTES); ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                </div><!-- .sched-calendar -->
            </div><!-- .sched-calendar-scroll -->
        </div><!-- .sched-calendar-outer -->

    </div><!-- .sched-layout -->
</section>

<?php
/** Render a positioned schedule block. */
function renderBlock(array $s, int $durationSecs, int $pxPerMin, int $totalMin): string {
    $id       = (int) $s['id'];
    $name     = htmlspecialchars($s['playlist_name']);
    $dur      = $durationSecs > 0 ? htmlspecialchars(fmtDur($durationSecs)) : '';
    $style    = blockStyle($s, $durationSecs, $pxPerMin, $totalMin);
    $active   = $s['active'] ? '' : ' sched-block--inactive';
    $nameJs   = json_encode($s['playlist_name']);

    return <<<HTML
<div class="sched-block{$active}"
     style="{$style}"
     draggable="true"
     ondragstart="onBlockDragStart(event, {$id}, {$nameJs}, {$durationSecs})">
    <div class="sched-block-name">{$name}</div>
    <div class="sched-block-dur">{$dur}</div>
    <div class="sched-block-btns">
        <button title="Delete" onclick="deleteBlock({$id})">&times;</button>
    </div>
</div>
HTML;
}
?>

<script>
// ── Constants ──────────────────────────────────────────────────────────────
const PX_PER_MIN    = <?= $PX_PER_MIN ?>;
const TOTAL_MINUTES = <?= $TOTAL_MINUTES ?>;
const GRID_HEIGHT   = <?= $GRID_HEIGHT ?>;

// ── Drag state ──────────────────────────────────────────────────────────────

let drag = null; // { type:'new'|'move', playlist, durationSecs, id? }

// ── Sidebar drag ────────────────────────────────────────────────────────────

function onSidebarDragStart(event, playlist, durationSecs) {
    drag = {
        type: 'new',
        playlist,
        durationSecs,
    };
    event.currentTarget.classList.add('dragging');
    event.dataTransfer.effectAllowed = 'copy';
}

// ── Existing block drag ─────────────────────────────────────────────────────

function onBlockDragStart(event, id, playlist, durationSecs) {
    event.stopPropagation();
    drag = { type: 'move', id, playlist, durationSecs };
    event.dataTransfer.effectAllowed = 'move';
}

// ── Drop column handlers ────────────────────────────────────────────────────

function onDragOver(event) {
    event.preventDefault();
    const col = event.currentTarget;
    col.classList.add('drag-over');
    if (!drag) return;
    const time = resolveTime(event.clientY, col);
    showPreview(col, time, drag.durationSecs);
}

function onDragLeave(event) {
    if (!event.currentTarget.contains(event.relatedTarget)) {
        event.currentTarget.classList.remove('drag-over');
        hidePreview(event.currentTarget);
    }
}

async function onDrop(event, day) {
    event.preventDefault();
    const col = event.currentTarget;
    col.classList.remove('drag-over');
    hidePreview(col);
    if (!drag) return;

    const time = resolveTime(event.clientY, col);
    const fd   = new FormData();
    fd.append('day_of_week', String(day));
    fd.append('time_of_day', time);

    try {
        if (drag.type === 'new') {
            fd.append('playlist', drag.playlist);
            await post('/admin/schedule/create', fd);
        } else {
            fd.append('id', String(drag.id));
            await post('/admin/schedule/move', fd);
        }
        location.reload();
    } catch (e) {
        console.error('Schedule drop failed', e);
    }
    drag = null;
}

// ── Time resolution from Y coordinate ──────────────────────────────────────

function resolveTime(clientY, colEl) {
    const rect   = colEl.getBoundingClientRect();
    const relY   = clientY - rect.top + colEl.scrollTop;
    const rawMin = Math.max(0, Math.min(TOTAL_MINUTES - 1, Math.round(relY / PX_PER_MIN)));
    const snapped = Math.round(rawMin / 15) * 15; // snap to 15-min grid
    const clamped = Math.min(snapped, TOTAL_MINUTES - 15); // ensure room for at least 15 min
    const h = Math.floor(clamped / 60);
    const m = clamped % 60;
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
}

// ── Drop preview ghost ──────────────────────────────────────────────────────

function showPreview(colEl, time, durationSecs) {
    let el = colEl.querySelector('.drop-preview');
    if (!el) {
        el = document.createElement('div');
        el.className = 'drop-preview';
        colEl.appendChild(el);
    }
    const [h, m] = time.split(':').map(Number);
    const topMin = h * 60 + m;
    const top    = topMin * PX_PER_MIN;
    const durMin = Math.max(4, Math.round(durationSecs / 60));
    const height = Math.max(20, Math.min(durMin * PX_PER_MIN, GRID_HEIGHT - top));
    el.style.top    = `${top}px`;
    el.style.height = `${height}px`;
    el.style.display = 'block';
}

function hidePreview(colEl) {
    const el = colEl.querySelector('.drop-preview');
    if (el) el.style.display = 'none';
}

// ── Block actions ───────────────────────────────────────────────────────────

async function deleteBlock(id) {
    if (!confirm('Delete this schedule entry?')) return;
    const fd = new FormData();
    fd.append('id', String(id));
    await post('/admin/schedule/delete', fd);
    location.reload();
}

// ── Day loop toggle ─────────────────────────────────────────────────────────

async function toggleDayLoop(dayOfWeek, enabled) {
    const fd = new FormData();
    fd.append('day_of_week', String(dayOfWeek));
    fd.append('loop_last',   enabled ? '1' : '0');
    await post('/admin/schedule/toggle-day-loop', fd);
}

// ── Fetch helper ────────────────────────────────────────────────────────────

function post(url, formData) {
    return fetch(url, {
        method:  'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body:    formData,
    });
}
</script>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
