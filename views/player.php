<?php $pageTitle = SITE_NAME . ' — Listen'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="player">
    <div class="now-playing">
        <h2 class="now-playing-label">Now Playing</h2>
        <p class="track-title" id="track-title">—</p>
        <p class="track-artist" id="track-artist">—</p>
        <p class="track-album" id="track-album"></p>
    </div>

    <div class="player-controls">
        <audio id="radio-stream" controls autoplay>
            <source src="<?= htmlspecialchars(STREAM_URL) ?>" type="audio/ogg">
            Your browser does not support audio streaming.
        </audio>
    </div>
</section>

<script>
    // Poll /now-playing every 10 seconds and update the display.
    // No framework — just fetch() and DOM manipulation.

    async function updateNowPlaying() {
        try {
            const res = await fetch('/now-playing');
            if (res.status === 401) {
                window.location.href = '/login';
                return;
            }
            if (!res.ok) return;

            const data = await res.json();
            document.getElementById('track-title').textContent  = data.title  || '—';
            document.getElementById('track-artist').textContent = data.artist || '—';
            document.getElementById('track-album').textContent  = data.album  || '';
        } catch (e) {
            // Network error — silently skip this tick
        }
    }

    updateNowPlaying();
    setInterval(updateNowPlaying, 10000);
</script>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>
