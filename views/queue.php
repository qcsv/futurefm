<?php $pageTitle = SITE_NAME . ' — Queue'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="admin-page">
    <h1>Queue</h1>

    <nav class="admin-nav">
        <a href="/admin">Dashboard</a>
        <a href="/admin/users">Users &amp; Invites</a>
    </nav>

    <?php if (!empty($mpdError)): ?>
        <p class="error">MPD unavailable: <?= htmlspecialchars($mpdError) ?></p>
    <?php else: ?>

        <!-- ── Playback controls ──────────────────────────────── -->

        <div class="playback-controls">
            <?php
                $controls = [
                    'previous' => '&#9664;&#9664;',
                    'play'     => '&#9654;',
                    'pause'    => '&#9646;&#9646;',
                    'stop'     => '&#9632;',
                    'next'     => '&#9654;&#9654;',
                ];
            ?>
            <?php foreach ($controls as $action => $label): ?>
                <form method="post" action="/admin/queue/action">
                    <input type="hidden" name="action" value="<?= $action ?>">
                    <button type="submit" class="control-btn" title="<?= ucfirst($action) ?>">
                        <?= $label ?>
                    </button>
                </form>
            <?php endforeach; ?>

            <form method="post" action="/admin/queue/action">
                <input type="hidden" name="action" value="shuffle">
                <button type="submit">Shuffle</button>
            </form>

            <form method="post" action="/admin/queue/action">
                <input type="hidden" name="action" value="clear">
                <button type="submit" onclick="return confirm('Clear the entire queue?')">Clear</button>
            </form>
        </div>

        <!-- ── Now playing ───────────────────────────────────── -->

        <?php if ($current !== null): ?>
            <div class="now-playing-admin">
                <strong>Now Playing:</strong>
                <?= htmlspecialchars($current->displayTitle()) ?>
                <?php if ($current->artist): ?>
                    — <?= htmlspecialchars($current->artist) ?>
                <?php endif; ?>
                (<?= htmlspecialchars($current->durationFormatted()) ?>)
            </div>
        <?php endif; ?>

        <!-- ── Queue ─────────────────────────────────────────── -->

        <?php if (empty($queue)): ?>
            <p>The queue is empty.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Artist</th>
                        <th>Duration</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queue as $song): ?>
                        <tr class="<?= $status && $status->songPos === $song->pos ? 'queue-current' : '' ?>">
                            <td><?= $song->pos + 1 ?></td>
                            <td><?= htmlspecialchars($song->displayTitle()) ?></td>
                            <td><?= htmlspecialchars($song->artist) ?></td>
                            <td><?= htmlspecialchars($song->durationFormatted()) ?></td>
                            <td>
                                <form method="post" action="/admin/queue/action">
                                    <input type="hidden" name="action"  value="delete">
                                    <input type="hidden" name="song_id" value="<?= $song->id ?>">
                                    <button type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php endif; ?>
</section>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
