<?php $pageTitle = SITE_NAME . ' — Listen'; ?>
<?php require VIEWS_DIR . '/layout/header.php'; ?>

<section class="player">
    <div class="now-playing">
        <div class="album-art-wrap">
            <img
                id="album-art"
                class="album-art"
                src=""
                alt="Album art"
                style="display:none"
            >
            <div class="album-art-placeholder" id="album-art-placeholder">&#9835;</div>
        </div>

        <div class="now-playing-info">
            <p class="now-playing-label">Now Playing</p>
            <p class="track-title"  id="track-title">—</p>
            <p class="track-artist" id="track-artist">—</p>
            <p class="track-album"  id="track-album"></p>
        </div>
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
    const barTitle    = document.getElementById('bar-title');
    const barArtist   = document.getElementById('bar-artist');
    const trackTitle  = document.getElementById('track-title');
    const trackArtist = document.getElementById('track-artist');
    const trackAlbum  = document.getElementById('track-album');
    const albumArt    = document.getElementById('album-art');
    const artHolder   = document.getElementById('album-art-placeholder');

    let playing     = false;
    let lastArtKey  = '';

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

    async function fetchAlbumArt(artist, album) {
        const key = artist + '|' + album;
        if (key === lastArtKey) return;
        lastArtKey = key;

        if (!artist || !album) {
            albumArt.style.display  = 'none';
            artHolder.style.display = 'flex';
            return;
        }

        try {
            const res = await fetch(
                '/album-art?artist=' + encodeURIComponent(artist) +
                '&album='  + encodeURIComponent(album)
            );
            if (!res.ok) return;
            const data = await res.json();

            if (data.url) {
                albumArt.src           = data.url;
                albumArt.style.display = 'block';
                artHolder.style.display = 'none';
            } else {
                albumArt.style.display  = 'none';
                artHolder.style.display = 'flex';
            }
        } catch (e) {
            // silently fail
        }
    }

    async function updateNowPlaying() {
        try {
            const res = await fetch('/now-playing');
            if (res.status === 401) {
                window.location.href = '/login';
                return;
            }
            if (!res.ok) return;

            const data   = await res.json();
            const title  = data.title  || '—';
            const artist = data.artist || '';
            const album  = data.album  || '';

            trackTitle.textContent  = title;
            trackArtist.textContent = artist;
            trackAlbum.textContent  = album;
            barTitle.textContent    = title;
            barArtist.textContent   = artist;

            fetchAlbumArt(artist, album);
        } catch (e) {
            // silently fail
        }
    }

    updateNowPlaying();
    setInterval(updateNowPlaying, 10000);
</script>

<?php require VIEWS_DIR . '/layout/footer.php'; ?>