<?php $pageTitle = SITE_NAME . ' — Admin'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="admin-page">
    <h1>Admin Dashboard</h1>

    <nav class="admin-nav">
        <a href="/admin/queue">Queue</a>
        <a href="/admin/users">Users &amp; Invites</a>
    </nav>

    <?php
        try {
            $mpd    = new MPD(MPD_HOST, MPD_PORT);
            $mpd->connect();
            $status = $mpd->getStatus();
            $song   = $mpd->getCurrentSong();
            $stats  = $mpd->getStats();
            $mpd->disconnect();
        } catch (MpdException $e) {
            $mpdError = $e->getMessage();
        }
    ?>

    <?php if (!empty($mpdError)): ?>
        <p class="error">MPD unavailable: <?= htmlspecialchars($mpdError) ?></p>
    <?php else: ?>
        <div class="dashboard-status">
            <h2>Stream Status</h2>
            <table class="data-table">
                <tr>
                    <th>State</th>
                    <td><?= htmlspecialchars($status->state) ?></td>
                </tr>
                <tr>
                    <th>Now Playing</th>
                    <td><?= htmlspecialchars($song?->displayTitle() ?? '—') ?></td>
                </tr>
                <tr>
                    <th>Artist</th>
                    <td><?= htmlspecialchars($song?->artist ?? '—') ?></td>
                </tr>
                <tr>
                    <th>Volume</th>
                    <td><?= $status->volume === -1 ? 'N/A' : $status->volume . '%' ?></td>
                </tr>
                <tr>
                    <th>Queue Length</th>
                    <td><?= $status->playlistLen ?> track(s)</td>
                </tr>
                <tr>
                    <th>Library</th>
                    <td>
                        <?= $stats->songs ?> songs,
                        <?= $stats->artists ?> artists,
                        <?= $stats->albums ?> albums
                    </td>
                </tr>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
