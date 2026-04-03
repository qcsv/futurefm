<?php $pageTitle = SITE_NAME . ' — Schedule'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<?php
$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$dayShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Organise schedules for the weekly grid
$byDay   = array_fill(0, 7, []);
$allDays = [];
foreach ($schedules as $s) {
    if ($s['day_of_week'] === null) {
        $allDays[] = $s;
    } else {
        $byDay[(int) $s['day_of_week']][] = $s;
    }
}
?>

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
        Scheduled playlists are loaded automatically at the specified time.
        The clock is checked every ~10 seconds via the now-playing heartbeat.
    </p>

    <!-- ── Weekly calendar grid ─────────────────────────────────── -->

    <h2>Weekly Overview</h2>

    <div class="schedule-grid-wrap">
        <table class="schedule-grid">
            <thead>
                <tr>
                    <?php foreach ($dayShort as $d): ?>
                        <th><?= $d ?></th>
                    <?php endforeach; ?>
                    <th class="every-day-col">Every Day</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php for ($i = 0; $i < 7; $i++): ?>
                        <td>
                            <?php foreach ($byDay[$i] as $s): ?>
                                <div class="sched-item <?= $s['active'] ? '' : 'sched-item--inactive' ?>">
                                    <span class="sched-time"><?= htmlspecialchars($s['time_of_day']) ?></span>
                                    <span class="sched-name"><?= htmlspecialchars($s['playlist_name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($byDay[$i])): ?>
                                <span class="sched-empty">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endfor; ?>
                    <td>
                        <?php foreach ($allDays as $s): ?>
                            <div class="sched-item <?= $s['active'] ? '' : 'sched-item--inactive' ?>">
                                <span class="sched-time"><?= htmlspecialchars($s['time_of_day']) ?></span>
                                <span class="sched-name"><?= htmlspecialchars($s['playlist_name']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($allDays)): ?>
                            <span class="sched-empty">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ── Add new schedule ──────────────────────────────────────── -->

    <h2 style="margin-top:2rem">Add Schedule Entry</h2>

    <?php if (empty($playlists)): ?>
        <p class="library-meta">
            No playlists available. <a href="/admin/playlists">Create a playlist</a> first.
        </p>
    <?php else: ?>
        <form method="post" action="/admin/schedule/create" class="schedule-form">
            <div class="schedule-form-row">
                <label for="sched-playlist">Playlist</label>
                <select name="playlist" id="sched-playlist" required>
                    <?php foreach ($playlists as $pl): ?>
                        <option value="<?= htmlspecialchars($pl['playlist']) ?>">
                            <?= htmlspecialchars($pl['playlist']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="schedule-form-row">
                <label for="sched-day">Day</label>
                <select name="day_of_week" id="sched-day">
                    <option value="">Every day</option>
                    <?php foreach ($dayNames as $idx => $name): ?>
                        <option value="<?= $idx ?>"><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="schedule-form-row">
                <label for="sched-time">Time (24h)</label>
                <input type="time" name="time_of_day" id="sched-time" required>
            </div>

            <button type="submit">Add Schedule</button>
        </form>
    <?php endif; ?>

    <!-- ── All schedule entries ──────────────────────────────────── -->

    <h2 style="margin-top:2rem">All Entries</h2>

    <?php if (empty($schedules)): ?>
        <p class="library-meta">No schedule entries yet.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Playlist</th>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Last Run</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schedules as $s): ?>
                    <tr class="<?= $s['active'] ? '' : 'sched-row-inactive' ?>">
                        <td><?= htmlspecialchars($s['playlist_name']) ?></td>
                        <td><?= $s['day_of_week'] !== null ? $dayNames[(int)$s['day_of_week']] : 'Every day' ?></td>
                        <td><?= htmlspecialchars($s['time_of_day']) ?></td>
                        <td><?= $s['active'] ? '<span class="status-active">Active</span>' : '<span class="status-inactive">Paused</span>' ?></td>
                        <td class="text-grey">
                            <?= $s['last_run_at'] ? date('D M j, H:i', (int)$s['last_run_at']) : 'Never' ?>
                        </td>
                        <td class="table-actions">
                            <form method="post" action="/admin/schedule/toggle" class="form-inline">
                                <input type="hidden" name="id"     value="<?= (int)$s['id'] ?>">
                                <input type="hidden" name="active" value="<?= $s['active'] ? '0' : '1' ?>">
                                <button type="submit" class="btn-sm btn-secondary">
                                    <?= $s['active'] ? 'Pause' : 'Resume' ?>
                                </button>
                            </form>
                            <form method="post" action="/admin/schedule/delete" class="form-inline">
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button
                                    type="submit"
                                    class="btn-sm btn-danger"
                                    onclick="return confirm('Delete this schedule entry?')"
                                >Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</section>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
