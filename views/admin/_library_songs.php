<?php
/**
 * _library_songs.php — Shared partial for displaying library song results.
 *
 * Expects $librarySongs (array of MpdSong), $tab, $filter, $search
 * to be defined in the including scope.
 */
?>
<?php if ($tab !== '' && $filter !== ''): ?>
    <div class="add-all-bar">
        <form method="post" action="/admin/queue/add-all" class="form-inline">
            <input type="hidden" name="type"   value="<?= $tab === 'artist' ? 'artist' : 'album' ?>">
            <input type="hidden" name="value"  value="<?= htmlspecialchars($filter) ?>">
            <input type="hidden" name="tab"    value="<?= htmlspecialchars($tab) ?>">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <button type="submit">+ Add All to Queue</button>
        </form>
        <span class="library-meta"><?= count($librarySongs) ?> song(s)</span>
    </div>
<?php endif; ?>
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
        <?php foreach ($librarySongs as $song): ?>
            <tr>
                <td><?= htmlspecialchars($song->displayTitle()) ?></td>
                <td><?= htmlspecialchars($song->artist) ?></td>
                <td><?= htmlspecialchars($song->album) ?></td>
                <td><?= htmlspecialchars($song->durationFormatted()) ?></td>
                <td>
                    <form method="post" action="/admin/queue/add">
                        <input type="hidden" name="uri"    value="<?= htmlspecialchars($song->file) ?>">
                        <input type="hidden" name="tab"    value="<?= htmlspecialchars($tab) ?>">
                        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <button type="submit">+ Queue</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>