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

/**
 * CSS position style for a schedule block.
 * 1 minute == 1 px; calendar body is 1440 px tall.
 */
function blockStyle(array $sched, int $durationSecs): string {
    if ($sched['loop_all_day']) {
        return 'top:0px;height:1440px';
    }
    [$h, $m] = array_map('intval', explode(':', $sched['time_of_day']));
    $top    = $h * 60 + $m;
    $height = max(20, min((int) round($durationSecs / 60), 1440 - $top));
    return "top:{$top}px;height:{$height}px";
}

// ── Data organisation ─────────────────────────────────────────────────────────

$dayShort  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
// Display order Mon → Sun, then a "Daily" column for day_of_week = NULL
$colOrder  = [1, 2, 3, 4, 5, 6, 0];
$todayDow  = (int) date('w');

// $blocksByDay: index 0-6 for specific days, 7 for "every day" (NULL)
$blocksByDay = array_fill(0, 8, []);
foreach ($schedules as $s) {
    $idx = $s['day_of_week'] !== null ? (int) $s['day_of_week'] : 7;
    $blocksByDay[$idx][] = $s;
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
                        <label class="playlist-drag-item-loop" onclick="event.stopPropagation()">
                            <input type="checkbox" class="loop-checkbox"> Loop all day
                        </label>
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
                        <div class="time-gutter-body">
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <div class="time-label" style="top:<?= $h * 60 ?>px">
                                    <?= sprintf('%02d:00', $h) ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Daily column (day_of_week = NULL) -->
                    <div class="cal-col">
                        <div class="cal-col-header">Daily</div>
                        <div class="cal-col-body"
                             data-day=""
                             ondragover="onDragOver(event)"
                             ondragleave="onDragLeave(event)"
                             ondrop="onDrop(event, '')">
                            <?php for ($h = 0; $h < 24; $h++): ?>
                                <div class="hour-line" style="top:<?= $h * 60 ?>px"></div>
                            <?php endfor; ?>
                            <?php foreach ($blocksByDay[7] as $s): ?>
                                <?php $dur = $playlistDurations[$s['playlist_name']] ?? 0; ?>
                                <?php echo renderBlock($s, $dur); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Mon – Sun columns -->
                    <?php foreach ($colOrder as $dow): ?>
                        <div class="cal-col">
                            <div class="cal-col-header <?= $todayDow === $dow ? 'cal-col-header--today' : '' ?>">
                                <?= $dayShort[$dow] ?>
                            </div>
                            <div class="cal-col-body"
                                 data-day="<?= $dow ?>"
                                 ondragover="onDragOver(event)"
                                 ondragleave="onDragLeave(event)"
                                 ondrop="onDrop(event, <?= $dow ?>)">
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                    <div class="hour-line" style="top:<?= $h * 60 ?>px"></div>
                                <?php endfor; ?>
                                <?php foreach ($blocksByDay[$dow] as $s): ?>
                                    <?php $dur = $playlistDurations[$s['playlist_name']] ?? 0; ?>
                                    <?php echo renderBlock($s, $dur); ?>
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
/** Render a positioned schedule block (shared for all columns). */
function renderBlock(array $s, int $durationSecs): string {
    $id       = (int) $s['id'];
    $name     = htmlspecialchars($s['playlist_name']);
    $dur      = $durationSecs > 0 ? htmlspecialchars(fmtDur($durationSecs)) : '';
    $style    = blockStyle($s, $durationSecs);
    $active   = $s['active']      ? '' : ' sched-block--inactive';
    $loop     = $s['loop_all_day'] ? ' sched-block--loop' : '';
    $loopNext = $s['loop_all_day'] ? 0 : 1;
    $loopTip  = $s['loop_all_day'] ? 'Remove loop' : 'Loop all day';
    $loopIcon = $s['loop_all_day'] ? '⟳✓' : '⟳';
    $nameJs   = json_encode($s['playlist_name']);
    $loopJs   = $s['loop_all_day'] ? 'true' : 'false';

    return <<<HTML
<div class="sched-block{$active}{$loop}"
     style="{$style}"
     draggable="true"
     ondragstart="onBlockDragStart(event, {$id}, {$nameJs}, {$durationSecs}, {$loopJs})">
    <div class="sched-block-name">{$name}</div>
    <div class="sched-block-dur">{$dur}</div>
    <div class="sched-block-btns">
        <button title="{$loopTip}" onclick="toggleLoop({$id}, {$loopNext})">
            {$loopIcon}
        </button>
        <button title="Delete" onclick="deleteBlock({$id})">&times;</button>
    </div>
</div>
HTML;
}
?>

<script>
// ── Drag state ──────────────────────────────────────────────────────────────

let drag = null; // { type:'new'|'move', playlist, durationSecs, loopAllDay, id? }

// ── Sidebar drag ────────────────────────────────────────────────────────────

function onSidebarDragStart(event, playlist, durationSecs) {
    const loopCb = event.currentTarget.querySelector('.loop-checkbox');
    drag = {
        type: 'new',
        playlist,
        durationSecs,
        loopAllDay: loopCb ? loopCb.checked : false,
    };
    event.currentTarget.classList.add('dragging');
    event.dataTransfer.effectAllowed = 'copy';
}

// ── Existing block drag ─────────────────────────────────────────────────────

function onBlockDragStart(event, id, playlist, durationSecs, loopAllDay) {
    event.stopPropagation();
    drag = { type: 'move', id, playlist, durationSecs, loopAllDay };
    event.dataTransfer.effectAllowed = 'move';
}

// ── Drop column handlers ────────────────────────────────────────────────────

function onDragOver(event) {
    event.preventDefault();
    const col = event.currentTarget;
    col.classList.add('drag-over');
    if (!drag) return;
    const time = resolveTime(event.clientY, col, drag.loopAllDay);
    showPreview(col, time, drag.durationSecs, drag.loopAllDay);
}

function onDragLeave(event) {
    // Only clear when leaving the column itself, not its children
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

    const time = drag.loopAllDay ? '00:00' : resolveTime(event.clientY, col, false);
    const fd   = new FormData();
    fd.append('day_of_week',  day === '' ? '' : String(day));
    fd.append('time_of_day',  time);
    fd.append('loop_all_day', drag.loopAllDay ? '1' : '0');

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

function resolveTime(clientY, colEl, loopAllDay) {
    if (loopAllDay) return '00:00';
    const rect   = colEl.getBoundingClientRect();
    const relY   = clientY - rect.top + colEl.scrollTop;
    const rawMin = Math.max(0, Math.min(1439, Math.round(relY)));
    const snapped = Math.round(rawMin / 15) * 15; // snap to 15-min grid
    const h = Math.floor(snapped / 60);
    const m = snapped % 60;
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`;
}

// ── Drop preview ghost ──────────────────────────────────────────────────────

function showPreview(colEl, time, durationSecs, loopAllDay) {
    let el = colEl.querySelector('.drop-preview');
    if (!el) {
        el = document.createElement('div');
        el.className = 'drop-preview';
        colEl.appendChild(el);
    }
    if (loopAllDay) {
        el.style.top    = '0px';
        el.style.height = '1440px';
    } else {
        const [h, m] = time.split(':').map(Number);
        const top    = h * 60 + m;
        const height = Math.max(20, Math.min(Math.round(durationSecs / 60), 1440 - top));
        el.style.top    = `${top}px`;
        el.style.height = `${height}px`;
    }
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

async function toggleLoop(id, newVal) {
    const fd = new FormData();
    fd.append('id',          String(id));
    fd.append('loop_all_day', String(newVal));
    await post('/admin/schedule/toggle-loop', fd);
    location.reload();
}

// ── Fetch helper ────────────────────────────────────────────────────────────

function post(url, formData) {
    return fetch(url, {
        method:  'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body:    formData,
    });
}

// ── Auto-scroll to current time ─────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    const scroller = document.getElementById('calScroll');
    if (!scroller) return;
    const now = new Date();
    const currentMin = now.getHours() * 60 + now.getMinutes();
    scroller.scrollTop = Math.max(0, currentMin - 120); // 2 hours above now
});
</script>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
