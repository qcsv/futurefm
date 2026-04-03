<?php $pageTitle = SITE_NAME . ' — Listen'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="player">
    <div class="now-playing">
        <p class="now-playing-label">Now Playing</p>
        <p class="track-title"  id="track-title">—</p>
        <p class="track-artist" id="track-artist">—</p>
        <p class="track-album"  id="track-album"></p>
    </div>
</section>

<!-- Fixed custom player bar -->
<div class="player-bar" id="player-bar">
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
    const audio       = document.getElementById('radio-audio');
    const playBtn     = document.getElementById('play-btn');
    const muteBtn     = document.getElementById('mute-btn');
    const volSlider   = document.getElementById('volume-slider');
    const barTitle    = document.getElementById('bar-title');
    const barArtist   = document.getElementById('bar-artist');
    const trackTitle  = document.getElementById('track-title');
    const trackArtist = document.getElementById('track-artist');
    const trackAlbum  = document.getElementById('track-album');

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
            if (res.status === 401) {
                window.location.href = '/login';
                return;
            }
            if (!res.ok) return;

            const data = await res.json();
            const title  = data.title  || '—';
            const artist = data.artist || '';
            const album  = data.album  || '';

            trackTitle.textContent  = title;
            trackArtist.textContent = artist;
            trackAlbum.textContent  = album;
            barTitle.textContent    = title;
            barArtist.textContent   = artist;
        } catch (e) {
            // Network error — silently skip this tick
        }
    }

    updateNowPlaying();
    setInterval(updateNowPlaying, 10000);
</script>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>