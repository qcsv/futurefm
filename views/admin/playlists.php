<?php $pageTitle = SITE_NAME . ' — Playlists'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="admin-page">
    <h1>Playlists</h1>

    <nav class="admin-nav">
        <a href="/admin">Dashboard</a>
        <a href="/admin/queue">Queue</a>
        <a href="/admin/playlists">Playlists</a>
        <a href="/admin/schedule">Schedule</a>
        <a href="/admin/users">Users &amp; Invites</a>
    </nav>

    <?php if (!empty($mpdError)): ?>
        <p class="error">MPD unavailable: <?= htmlspecialchars($mpdError) ?></p>
    <?php endif; ?>

    <?php if ($viewName === ''): ?>

        <!-- ── Create new playlist ──────────────────────────────── -->

        <h2>Save Current Queue as Playlist</h2>
        <form method="post" action="/admin/playlists/create" class="inline-form" style="margin-bottom:2rem">
            <input
                type="text"
                name="name"
                placeholder="Playlist name..."
                required
                maxlength="100"
            >
            <button type="submit">Save Queue</button>
        </form>

        <!-- ── Saved playlists ──────────────────────────────────── -->

        <h2>Saved Playlists</h2>

        <?php if (empty($playlists)): ?>
            <p class="library-meta">No saved playlists yet. Save the current queue above to create one.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Last Modified</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($playlists as $pl): ?>
                        <tr>
                            <td>
                                <a href="/admin/playlists?view=<?= urlencode($pl['playlist']) ?>">
                                    <?= htmlspecialchars($pl['playlist']) ?>
                                </a>
                            </td>
                            <td class="text-grey">
                                <?= htmlspecialchars($pl['last-modified'] ?? '—') ?>
                            </td>
                            <td class="table-actions">
                                <form method="post" action="/admin/playlists/load" class="form-inline">
                                    <input type="hidden" name="name" value="<?= htmlspecialchars($pl['playlist']) ?>">
                                    <button type="submit">Load to Queue</button>
                                </form>
                                <a href="/admin/playlists?view=<?= urlencode($pl['playlist']) ?>" class="btn btn-secondary">View</a>
                                <form method="post" action="/admin/playlists/delete" class="form-inline">
                                    <input type="hidden" name="name" value="<?= htmlspecialchars($pl['playlist']) ?>">
                                    <button
                                        type="submit"
                                        class="btn-danger"
                                        onclick="return confirm('Delete playlist &quot;<?= htmlspecialchars(addslashes($pl['playlist'])) ?>&quot;?')"
                                    >Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php else: ?>

        <!-- ── Viewing a specific playlist ─────────────────────── -->

        <div class="playlist-header">
            <h2><?= htmlspecialchars($viewName) ?></h2>
            <div class="table-actions" style="margin-bottom:1rem">
                <form method="post" action="/admin/playlists/load" class="form-inline">
                    <input type="hidden" name="name" value="<?= htmlspecialchars($viewName) ?>">
                    <button type="submit">Load to Queue</button>
                </form>
                <a href="/admin/playlists" class="btn btn-secondary">Back to Playlists</a>
            </div>
        </div>

        <!-- Songs in this playlist -->
        <?php if (empty($playlistSongs)): ?>
            <p class="library-meta">This playlist is empty.</p>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Artist</th>
                        <th>Album</th>
                        <th>Duration</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($playlistSongs as $i => $song): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($song->displayTitle()) ?></td>
                            <td><?= htmlspecialchars($song->artist) ?></td>
                            <td><?= htmlspecialchars($song->album) ?></td>
                            <td><?= htmlspecialchars($song->durationFormatted()) ?></td>
                            <td>
                                <form method="post" action="/admin/playlists/remove-song" class="form-inline">
                                    <input type="hidden" name="name" value="<?= htmlspecialchars($viewName) ?>">
                                    <input type="hidden" name="pos"  value="<?= $i ?>">
                                    <button type="submit" class="btn-danger btn-sm">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- ── Add songs to this playlist via search ────────────── -->

        <h2 style="margin-top:2rem">Add Songs</h2>
        <form method="get" action="/admin/playlists" class="inline-form library-search">
            <input type="hidden" name="view" value="<?= htmlspecialchars($viewName) ?>">
            <input
                type="text"
                name="search"
                placeholder="Search library to add songs..."
                value="<?= htmlspecialchars($search) ?>"
            >
            <button type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a href="/admin/playlists?view=<?= urlencode($viewName) ?>" class="btn">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($search !== '' && !empty($searchResults)): ?>
            <p class="library-meta"><?= count($searchResults) ?> result(s) for &ldquo;<?= htmlspecialchars($search) ?>&rdquo;</p>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Artist</th>
                        <th>Album</th>
                        <th>Duration</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($searchResults as $song): ?>
                        <tr>
                            <td><?= htmlspecialchars($song->displayTitle()) ?></td>
                            <td><?= htmlspecialchars($song->artist) ?></td>
                            <td><?= htmlspecialchars($song->album) ?></td>
                            <td><?= htmlspecialchars($song->durationFormatted()) ?></td>
                            <td>
                                <form method="post" action="/admin/playlists/add-song" class="form-inline">
                                    <input type="hidden" name="playlist" value="<?= htmlspecialchars($viewName) ?>">
                                    <input type="hidden" name="uri"      value="<?= htmlspecialchars($song->file) ?>">
                                    <input type="hidden" name="search"   value="<?= htmlspecialchars($search) ?>">
                                    <button type="submit" class="btn-sm">+ Add</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($search !== ''): ?>
            <p class="library-meta">No results for &ldquo;<?= htmlspecialchars($search) ?>&rdquo;.</p>
        <?php endif; ?>

    <?php endif; ?>
</section>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
