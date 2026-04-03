<?php $pageTitle = SITE_NAME . ' — Queue'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="admin-page">
    <h1>Queue</h1>

    <nav class="admin-nav">
        <a href="/admin">Dashboard</a>
        <a href="/admin/queue">Queue</a>
        <a href="/admin/playlists">Playlists</a>
        <a href="/admin/schedule">Schedule</a>
        <a href="/admin/users">Users &amp; Invites</a>
    </nav>

    <!-- ── Sticky stream player ──────────────────────────────── -->

    <div class="player-bar player-bar--sticky" id="player-bar">
        <audio id="radio-audio" preload="none">
            <source src="<?= htmlspecialchars(STREAM_URL) ?>" type="audio/ogg">
        </audio>

        <div class="player-bar-left">
            <button class="player-btn" id="play-btn" onclick="togglePlay()">&#9654;</button>
        </div>

        <div class="player-bar-center">
            <span class="player-bar-title"  id="bar-title">—</span>
            <span class="player-bar-artist" id="bar-artist"></span>
        </div>

        <div class="player-bar-right">
            <button class="player-btn" id="mute-btn" onclick="toggleMute()">&#128266;</button>
            <input
                type="range"
                id="volume-slider"
                class="volume-slider"
                min="0" max="1" step="0.05"
                value="1"
                oninput="setVolume(this.value)"
            >
        </div>
    </div>

    <script>
        const audio     = document.getElementById('radio-audio');
        const playBtn   = document.getElementById('play-btn');
        const muteBtn   = document.getElementById('mute-btn');
        const barTitle  = document.getElementById('bar-title');
        const barArtist = document.getElementById('bar-artist');

        let playing = false;

        function togglePlay() {
            if (playing) {
                audio.pause();
                audio.src = '';
                playBtn.innerHTML = '&#9654;';
                playing = false;
            } else {
                audio.src = '<?= htmlspecialchars(STREAM_URL) ?>';
                audio.play();
                playBtn.innerHTML = '&#9646;&#9646;';
                playing = true;
            }
        }

        function toggleMute() {
            audio.muted = !audio.muted;
            muteBtn.innerHTML = audio.muted ? '&#128263;' : '&#128266;';
        }

        function setVolume(val) {
            audio.volume = val;
            audio.muted  = false;
            muteBtn.innerHTML = '&#128266;';
        }

        async function updateNowPlaying() {
            try {
                const res = await fetch('/now-playing');
                if (!res.ok) return;
                const data = await res.json();
                barTitle.textContent  = data.title  || '—';
                barArtist.textContent = data.artist || '';
            } catch (e) {}
        }

        updateNowPlaying();
        setInterval(updateNowPlaying, 10000);
    </script>

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

        <!-- ── Current queue ─────────────────────────────────── -->

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

        <!-- ── Library browser ───────────────────────────────── -->

        <h2 class="library-heading">Library</h2>

        <!-- Search bar -->
        <form method="get" action="/admin/queue" class="inline-form library-search">
            <input
                type="text"
                name="search"
                placeholder="Search songs, artists, albums..."
                value="<?= htmlspecialchars($search) ?>"
            >
            <button type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a href="/admin/queue" class="btn">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Tab navigation -->
        <?php if ($search === ''): ?>
            <nav class="library-tabs">
                <a href="/admin/queue"
                   class="<?= $tab === '' ? 'active' : '' ?>">Browse</a>
                <a href="/admin/queue?tab=artist"
                   class="<?= $tab === 'artist' && $filter === '' ? 'active' : '' ?>">Artists</a>
                <a href="/admin/queue?tab=album"
                   class="<?= $tab === 'album'  && $filter === '' ? 'active' : '' ?>">Albums</a>
            </nav>
        <?php endif; ?>

        <?php if (!empty($libraryError)): ?>
            <p class="error">Library unavailable: <?= htmlspecialchars($libraryError) ?></p>

        <?php elseif ($search !== ''): ?>
            <!-- Search results -->
            <p class="library-meta">
                <?= count($librarySongs) ?> result(s) for
                &ldquo;<?= htmlspecialchars($search) ?>&rdquo;
            </p>
            <?php if (empty($librarySongs)): ?>
                <p>No songs found.</p>
            <?php else: ?>
                <?php include VIEWS_DIR . '/admin/_library_songs.php'; ?>
            <?php endif; ?>

        <?php elseif ($tab === ''): ?>
            <p class="library-meta">Pick a tab or search to browse the library.</p>

        <?php elseif ($filter === '' && !empty($libraryList)): ?>
            <!-- Artist or album list -->
            <table class="data-table">
                <thead>
                    <tr>
                        <th><?= $tab === 'artist' ? 'Artist' : 'Album' ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($libraryList as $item): ?>
                        <?php if (trim($item) === '') continue; ?>
                        <tr>
                            <td><?= htmlspecialchars($item) ?></td>
                            <td class="table-actions">
                                <a href="/admin/queue?tab=<?= urlencode($tab) ?>&filter=<?= urlencode($item) ?>">
                                    View songs
                                </a>
                                <form method="post" action="/admin/queue/add-all" class="form-inline">
                                    <input type="hidden" name="type"   value="<?= $tab === 'artist' ? 'artist' : 'album' ?>">
                                    <input type="hidden" name="value"  value="<?= htmlspecialchars($item) ?>">
                                    <input type="hidden" name="tab"    value="<?= htmlspecialchars($tab) ?>">
                                    <button type="submit" class="btn-sm">+ Add All</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($filter !== '' && !empty($librarySongs)): ?>
            <!-- Songs filtered by artist or album -->
            <p class="library-meta">
                <?php if ($tab === 'artist'): ?>
                    Songs by <strong><?= htmlspecialchars($filter) ?></strong>
                    &mdash; <a href="/admin/queue?tab=artist">Back to artists</a>
                <?php else: ?>
                    Album: <strong><?= htmlspecialchars($filter) ?></strong>
                    &mdash; <a href="/admin/queue?tab=album">Back to albums</a>
                <?php endif; ?>
            </p>
            <?php include VIEWS_DIR . '/admin/_library_songs.php'; ?>

        <?php elseif ($filter !== ''): ?>
            <p>No songs found.</p>

        <?php endif; ?>

    <?php endif; ?>
</section>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>