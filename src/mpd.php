<?php

/**
 * libmpd.php — PHP client library for the Music Player Daemon protocol
 *
 * Wraps the MPD socket protocol (RFC-style plain text over TCP).
 * All public methods throw MpdException on protocol or connection errors.
 *
 * Usage:
 *   $mpd = new MPD('127.0.0.1', 6600);
 *   $mpd->connect();
 *   $status = $mpd->getStatus();
 *   $mpd->disconnect();
 *
 * Or use the static factory for one-shot calls:
 *   $song = MPD::quick(fn($m) => $m->getCurrentSong());
 */

class MpdException extends RuntimeException {}

class MpdConnectionException extends MpdException {}

class MpdCommandException extends MpdException {
    public function __construct(
        string $message,
        public readonly int $mpdCode = 0,
        public readonly string $mpdCommand = ''
    ) {
        parent::__construct($message);
    }
}

// ---------------------------------------------------------------------------
// Value objects returned by the library
// ---------------------------------------------------------------------------

class MpdSong {
    public function __construct(
        public readonly string  $file        = '',
        public readonly string  $title       = '',
        public readonly string  $artist      = '',
        public readonly string  $album       = '',
        public readonly string  $date        = '',
        public readonly string  $genre       = '',
        public readonly int     $duration    = 0,   // seconds
        public readonly int     $pos         = -1,  // playlist position
        public readonly int     $id          = -1,  // playlist id
    ) {}

    /** Human-readable duration  e.g. "3:47" */
    public function durationFormatted(): string {
        $m = intdiv($this->duration, 60);
        $s = $this->duration % 60;
        return sprintf('%d:%02d', $m, $s);
    }

    /** Display title, falling back to the bare filename */
    public function displayTitle(): string {
        return $this->title !== '' ? $this->title : basename($this->file);
    }
}

class MpdStatus {
    public function __construct(
        public readonly string $state        = 'unknown', // play | stop | pause
        public readonly int    $volume       = 0,         // 0-100, -1 if disabled
        public readonly bool   $repeat       = false,
        public readonly bool   $random       = false,
        public readonly bool   $single       = false,
        public readonly bool   $consume      = false,
        public readonly int    $playlistLen  = 0,
        public readonly int    $songPos      = -1,        // current playlist position
        public readonly int    $songId       = -1,        // current song id
        public readonly float  $elapsed      = 0.0,       // seconds elapsed
        public readonly float  $duration     = 0.0,       // total seconds
        public readonly int    $bitrate      = 0,         // kbps
        public readonly string $audio        = '',        // sampleRate:bits:channels
        public readonly string $error        = '',
    ) {}

    public function isPlaying(): bool  { return $this->state === 'play';  }
    public function isPaused(): bool   { return $this->state === 'pause'; }
    public function isStopped(): bool  { return $this->state === 'stop';  }

    /** Progress as a float 0.0–1.0 */
    public function progress(): float {
        if ($this->duration <= 0) return 0.0;
        return min(1.0, $this->elapsed / $this->duration);
    }
}

class MpdStats {
    public function __construct(
        public readonly int $artists   = 0,
        public readonly int $albums    = 0,
        public readonly int $songs     = 0,
        public readonly int $uptime    = 0,   // seconds daemon has been running
        public readonly int $dbPlaytime = 0,  // total duration of all songs in db
        public readonly int $dbUpdate  = 0,   // unix timestamp of last db update
        public readonly int $playtime  = 0,   // total seconds ever played
    ) {}
}

// ---------------------------------------------------------------------------
// Main MPD class
// ---------------------------------------------------------------------------

class MPD {

    private mixed  $socket  = null;
    private string $version = '';

    public function __construct(
        private readonly string $host     = '127.0.0.1',
        private readonly int    $port     = 6600,
        private readonly string $password = '',
        private readonly float  $timeout  = 5.0,
    ) {}

    // -----------------------------------------------------------------------
    // Connection management
    // -----------------------------------------------------------------------

    /** Open the TCP connection and perform the MPD handshake. */
    public function connect(): void {
        if ($this->isConnected()) return;

        $err_code = 0;
        $err_str  = '';

        $sock = @fsockopen($this->host, $this->port, $err_code, $err_str, $this->timeout);
        if ($sock === false) {
            throw new MpdConnectionException(
                "Cannot connect to MPD at {$this->host}:{$this->port} — {$err_str} ({$err_code})"
            );
        }

        stream_set_timeout($sock, (int) $this->timeout);
        $this->socket = $sock;

        // Expect: OK MPD <version>
        $banner = $this->readLine();
        if (!str_starts_with($banner, 'OK MPD ')) {
            throw new MpdConnectionException("Unexpected MPD banner: {$banner}");
        }
        $this->version = trim(substr($banner, 7));

        if ($this->password !== '') {
            $this->command("password {$this->password}");
        }
    }

    public function disconnect(): void {
        if ($this->isConnected()) {
            @fwrite($this->socket, "close\n");
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function isConnected(): bool {
        return $this->socket !== null && !feof($this->socket);
    }

    public function getVersion(): string { return $this->version; }

    // -----------------------------------------------------------------------
    // Static quick-connect helper
    // -----------------------------------------------------------------------

    /**
     * Run a closure against a temporary MPD connection, then disconnect.
     *
     *   $song = MPD::quick(fn($m) => $m->getCurrentSong());
     */
    public static function quick(
        callable $fn,
        string   $host     = '127.0.0.1',
        int      $port     = 6600,
        string   $password = ''
    ): mixed {
        $mpd = new self($host, $port, $password);
        $mpd->connect();
        try {
            return $fn($mpd);
        } finally {
            $mpd->disconnect();
        }
    }

    // -----------------------------------------------------------------------
    // Status / info
    // -----------------------------------------------------------------------

    public function getStatus(): MpdStatus {
        $raw = $this->command('status');
        $d   = $this->parseKeyValue($raw);

        // elapsed and duration may be combined in "time: elapsed:total"
        $elapsed  = (float) ($d['elapsed'] ?? 0);
        $duration = (float) ($d['duration'] ?? 0);
        if (isset($d['time']) && str_contains($d['time'], ':')) {
            [$elapsed, $duration] = array_map('floatval', explode(':', $d['time'], 2));
        }

        return new MpdStatus(
            state:       $d['state']        ?? 'stop',
            volume:      (int)  ($d['volume']   ?? -1),
            repeat:      ($d['repeat']      ?? '0') === '1',
            random:      ($d['random']      ?? '0') === '1',
            single:      ($d['single']      ?? '0') === '1',
            consume:     ($d['consume']     ?? '0') === '1',
            playlistLen: (int)  ($d['playlistlength'] ?? 0),
            songPos:     (int)  ($d['song']     ?? -1),
            songId:      (int)  ($d['songid']   ?? -1),
            elapsed:     $elapsed,
            duration:    $duration,
            bitrate:     (int)  ($d['bitrate']  ?? 0),
            audio:       $d['audio']        ?? '',
            error:       $d['error']        ?? '',
        );
    }

    public function getCurrentSong(): ?MpdSong {
        $raw = $this->command('currentsong');
        if (trim($raw) === '') return null;
        return $this->parseSong($this->parseKeyValue($raw));
    }

    public function getStats(): MpdStats {
        $d = $this->parseKeyValue($this->command('stats'));
        return new MpdStats(
            artists:    (int) ($d['artists']    ?? 0),
            albums:     (int) ($d['albums']     ?? 0),
            songs:      (int) ($d['songs']      ?? 0),
            uptime:     (int) ($d['uptime']     ?? 0),
            dbPlaytime: (int) ($d['db_playtime'] ?? 0),
            dbUpdate:   (int) ($d['db_update']  ?? 0),
            playtime:   (int) ($d['playtime']   ?? 0),
        );
    }

    // -----------------------------------------------------------------------
    // Playback control
    // -----------------------------------------------------------------------

    public function play(?int $pos = null): void {
        $this->command($pos !== null ? "play {$pos}" : 'play');
    }

    public function playId(int $id): void {
        $this->command("playid {$id}");
    }

    public function pause(bool $pause = true): void {
        $this->command('pause ' . ($pause ? '1' : '0'));
    }

    public function togglePause(): void {
        $status = $this->getStatus();
        $this->pause(!$status->isPlaying());
    }

    public function stop(): void {
        $this->command('stop');
    }

    public function next(): void {
        $this->command('next');
    }

    public function previous(): void {
        $this->command('previous');
    }

    public function seekCurrent(float $seconds): void {
        $this->command(sprintf('seekcur %.3f', $seconds));
    }

    public function seekRelative(float $offset): void {
        $prefix = $offset >= 0 ? '+' : '';
        $this->command(sprintf('seekcur %s%.3f', $prefix, $offset));
    }

    // -----------------------------------------------------------------------
    // Volume / options
    // -----------------------------------------------------------------------

    /** Set volume 0–100 */
    public function setVolume(int $vol): void {
        $vol = max(0, min(100, $vol));
        $this->command("setvol {$vol}");
    }

    public function setRepeat(bool $on): void  { $this->command('repeat ' . ($on ? '1' : '0')); }
    public function setRandom(bool $on): void  { $this->command('random ' . ($on ? '1' : '0')); }
    public function setSingle(bool $on): void  { $this->command('single ' . ($on ? '1' : '0')); }
    public function setConsume(bool $on): void { $this->command('consume ' . ($on ? '1' : '0')); }

    // -----------------------------------------------------------------------
    // Playlist (current queue)
    // -----------------------------------------------------------------------

    /** Return the full current queue. */
    public function getPlaylist(): array {
        $raw   = $this->command('playlistinfo');
        return $this->parseMultipleSongs($raw);
    }

    /** Return a single song from the queue by playlist position. */
    public function getPlaylistSong(int $pos): ?MpdSong {
        $raw = $this->command("playlistinfo {$pos}");
        if (trim($raw) === '') return null;
        return $this->parseSong($this->parseKeyValue($raw));
    }

    /**
     * Add a URI (file or directory) to the end of the queue.
     * Returns the new song's id.
     */
    public function addToQueue(string $uri): int {
        $raw = $this->command('addid ' . $this->escape($uri));
        $d   = $this->parseKeyValue($raw);
        return (int) ($d['Id'] ?? -1);
    }

    /** Add a URI without needing the returned id. */
    public function add(string $uri): void {
        $this->command('add ' . $this->escape($uri));
    }

    /** Remove a song from the queue by playlist position. */
    public function deleteFromQueue(int $pos): void {
        $this->command("delete {$pos}");
    }

    /** Remove a song from the queue by its id. */
    public function deleteId(int $id): void {
        $this->command("deleteid {$id}");
    }

    /** Move a song to a new position (both are playlist positions). */
    public function move(int $from, int $to): void {
        $this->command("move {$from} {$to}");
    }

    /** Clear the entire queue. */
    public function clearQueue(): void {
        $this->command('clear');
    }

    /** Shuffle the queue (optionally just a range). */
    public function shuffle(?int $start = null, ?int $end = null): void {
        if ($start !== null && $end !== null) {
            $this->command("shuffle {$start}:{$end}");
        } else {
            $this->command('shuffle');
        }
    }

    // -----------------------------------------------------------------------
    // Saved playlists
    // -----------------------------------------------------------------------

    /** List all saved playlists. Returns array of ['playlist' => name, 'last-modified' => ...] */
    public function listSavedPlaylists(): array {
        $raw   = $this->command('listplaylists');
        return $this->parseKeyValueGroups($raw, 'playlist');
    }

    /** Load a saved playlist into the queue. */
    public function loadPlaylist(string $name): void {
        $this->command('load ' . $this->escape($name));
    }

    /** Save the current queue as a named playlist. */
    public function savePlaylist(string $name): void {
        $this->command('save ' . $this->escape($name));
    }

    /** Delete a saved playlist. */
    public function deletePlaylist(string $name): void {
        $this->command('rm ' . $this->escape($name));
    }

    /** Return songs in a saved playlist. */
    public function listPlaylistInfo(string $name): array {
        $raw = $this->command('listplaylistinfo ' . $this->escape($name));
        return $this->parseMultipleSongs($raw);
    }

    // -----------------------------------------------------------------------
    // Music library
    // -----------------------------------------------------------------------

    /**
     * List all files/directories under a path (default: root).
     * Returns an array of items, each with a 'type' key: 'file'|'directory'|'playlist'
     */
    public function listDirectory(string $path = ''): array {
        $cmd = $path !== '' ? 'lsinfo ' . $this->escape($path) : 'lsinfo';
        $raw = $this->command($cmd);
        return $this->parseDirectoryListing($raw);
    }

    /**
     * Search the library.
     *
     * $tag is an MPD tag like 'artist', 'album', 'title', 'any', etc.
     * Returns array of MpdSong.
     */
    public function search(string $tag, string $query): array {
        $raw = $this->command('search ' . $this->escape($tag) . ' ' . $this->escape($query));
        return $this->parseMultipleSongs($raw);
    }

    /** Case-sensitive library search (find). */
    public function find(string $tag, string $query): array {
        $raw = $this->command('find ' . $this->escape($tag) . ' ' . $this->escape($query));
        return $this->parseMultipleSongs($raw);
    }

    /** List all unique values of a tag (e.g. all artists, all albums). */
    public function listTag(string $tag, string $filterTag = '', string $filterValue = ''): array {
        $cmd = "list {$tag}";
        if ($filterTag !== '' && $filterValue !== '') {
            $cmd .= ' ' . $this->escape($filterTag) . ' ' . $this->escape($filterValue);
        }
        $raw = $this->command($cmd);
        $values = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'OK') continue;
            $pos = strpos($line, ': ');
            if ($pos !== false) {
                $values[] = substr($line, $pos + 2);
            }
        }
        return $values;
    }

    /** Trigger a database update (optionally for a sub-path). */
    public function updateLibrary(string $path = ''): int {
        $cmd = $path !== '' ? 'update ' . $this->escape($path) : 'update';
        $raw = $this->command($cmd);
        $d   = $this->parseKeyValue($raw);
        return (int) ($d['updating_db'] ?? 0);
    }

    // -----------------------------------------------------------------------
    // Outputs
    // -----------------------------------------------------------------------

    /** List all configured MPD audio outputs. */
    public function getOutputs(): array {
        $raw    = $this->command('outputs');
        $groups = $this->parseKeyValueGroups($raw, 'outputid');
        $result = [];
        foreach ($groups as $g) {
            $result[] = [
                'id'      => (int)  ($g['outputid']      ?? -1),
                'name'    =>        ($g['outputname']    ?? ''),
                'enabled' => ($g['outputenabled'] ?? '0') === '1',
            ];
        }
        return $result;
    }

    public function enableOutput(int $id): void  { $this->command("enableoutput {$id}");  }
    public function disableOutput(int $id): void { $this->command("disableoutput {$id}"); }

    // -----------------------------------------------------------------------
    // Command list (batch)
    // -----------------------------------------------------------------------

    /**
     * Send multiple commands atomically.
     *
     *   $mpd->batch(['play', 'setvol 80']);
     */
    public function batch(array $commands): string {
        $payload  = "command_list_begin\n";
        $payload .= implode("\n", $commands) . "\n";
        $payload .= "command_list_end\n";
        return $this->rawWrite($payload);
    }

    // -----------------------------------------------------------------------
    // Low-level socket I/O
    // -----------------------------------------------------------------------

    /**
     * Send a single command string and return the response body
     * (everything before the trailing OK or ACK line).
     */
    public function command(string $cmd): string {
        $this->assertConnected();
        $this->writeLine($cmd);
        return $this->readResponse();
    }

    private function rawWrite(string $payload): string {
        $this->assertConnected();
        if (@fwrite($this->socket, $payload) === false) {
            throw new MpdConnectionException('Write to MPD socket failed.');
        }
        return $this->readResponse();
    }

    private function writeLine(string $line): void {
        if (@fwrite($this->socket, $line . "\n") === false) {
            throw new MpdConnectionException('Write to MPD socket failed.');
        }
    }

    private function readLine(): string {
        $line = @fgets($this->socket, 4096);
        if ($line === false) {
            throw new MpdConnectionException('MPD socket read failed or connection closed.');
        }
        return rtrim($line, "\r\n");
    }

    private function readResponse(): string {
        $buf = '';
        while (true) {
            $line = $this->readLine();
            if ($line === 'OK') break;
            if (str_starts_with($line, 'ACK ')) {
                // ACK [error@cmd_list_num] {command} message
                preg_match('/^ACK \[(\d+)@\d+\] \{([^}]*)\} (.+)$/', $line, $m);
                $code    = isset($m[1]) ? (int) $m[1] : 0;
                $cmdName = $m[2] ?? '';
                $msg     = $m[3] ?? $line;
                throw new MpdCommandException("MPD error: {$msg}", $code, $cmdName);
            }
            $buf .= $line . "\n";
        }
        return $buf;
    }

    private function assertConnected(): void {
        if (!$this->isConnected()) {
            throw new MpdConnectionException('Not connected to MPD. Call connect() first.');
        }
    }

    // -----------------------------------------------------------------------
    // Parsing helpers
    // -----------------------------------------------------------------------

    /** Parse a flat "key: value\n" response into an associative array. */
    private function parseKeyValue(string $raw): array {
        $result = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'OK') continue;
            $pos = strpos($line, ': ');
            if ($pos !== false) {
                $result[strtolower(substr($line, 0, $pos))] = substr($line, $pos + 2);
            }
        }
        return $result;
    }

    /**
     * Parse a repeated-group response into an array of associative arrays.
     * $delimiter is the key whose appearance signals the start of a new group.
     */
    private function parseKeyValueGroups(string $raw, string $delimiter): array {
        $groups  = [];
        $current = null;
        $delim   = strtolower($delimiter);

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'OK') continue;
            $pos = strpos($line, ': ');
            if ($pos === false) continue;
            $key = strtolower(substr($line, 0, $pos));
            $val = substr($line, $pos + 2);

            if ($key === $delim) {
                if ($current !== null) $groups[] = $current;
                $current = [];
            }
            if ($current !== null) {
                $current[$key] = $val;
            }
        }
        if ($current !== null) $groups[] = $current;
        return $groups;
    }

    /** Parse a multi-song response into an array of MpdSong. */
    private function parseMultipleSongs(string $raw): array {
        $groups = $this->parseKeyValueGroups($raw, 'file');
        return array_map(fn($g) => $this->parseSong($g), $groups);
    }

    /** Build an MpdSong from a key-value dict. */
    private function parseSong(array $d): MpdSong {
        // Duration may be in 'time', 'duration', or 'time: elapsed:total'
        $duration = 0;
        if (isset($d['duration'])) {
            $duration = (int) round((float) $d['duration']);
        } elseif (isset($d['time'])) {
            $t = $d['time'];
            $duration = str_contains($t, ':')
                ? (int) explode(':', $t)[1]
                : (int) $t;
        }

        return new MpdSong(
            file:     $d['file']   ?? '',
            title:    $d['title']  ?? '',
            artist:   $d['artist'] ?? '',
            album:    $d['album']  ?? '',
            date:     $d['date']   ?? '',
            genre:    $d['genre']  ?? '',
            duration: $duration,
            pos:      (int) ($d['pos'] ?? -1),
            id:       (int) ($d['id']  ?? -1),
        );
    }

    /** Parse an lsinfo response into a mixed array of files/dirs/playlists. */
    private function parseDirectoryListing(string $raw): array {
        $items   = [];
        $current = null;

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'OK') continue;
            $pos = strpos($line, ': ');
            if ($pos === false) continue;
            $key = substr($line, 0, $pos);
            $val = substr($line, $pos + 2);

            if (in_array($key, ['file', 'directory', 'playlist'], true)) {
                if ($current !== null) $items[] = $current;
                $current = ['type' => strtolower($key), 'path' => $val];
            } elseif ($current !== null) {
                $current[strtolower($key)] = $val;
            }
        }
        if ($current !== null) $items[] = $current;
        return $items;
    }

    /** Escape an MPD argument (wrap in double quotes, escape backslash and quote). */
    private function escape(string $value): string {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        return '"' . $value . '"';
    }
}