<?php

/**
 * db.php — Database access layer
 *
 * All database interaction goes through this class. No raw SQL
 * anywhere else in the codebase. Instantiate once in the front
 * controller and pass the instance to whatever needs it.
 *
 * Requires config.php to be loaded before instantiation.
 */

class DatabaseException extends RuntimeException {}

class Database
{
    private PDO $pdo;

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    /**
     * Open the SQLite database at DB_PATH.
     *
     * @throws DatabaseException If the database cannot be opened.
     */
    public function __construct()
    {
        try {
            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Enable WAL mode for better concurrent read performance
            $this->pdo->exec('PRAGMA journal_mode=WAL');

            // Enforce foreign key constraints (SQLite disables them by default)
            $this->pdo->exec('PRAGMA foreign_keys=ON');

            // Ensure schedule table exists (migration-safe)
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS schedule (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                playlist_name TEXT NOT NULL,
                day_of_week   INTEGER,
                time_of_day   TEXT NOT NULL,
                active        INTEGER NOT NULL DEFAULT 1,
                loop_all_day  INTEGER NOT NULL DEFAULT 0,
                last_run_at   INTEGER,
                created_by    INTEGER NOT NULL REFERENCES users(id),
                created_at    INTEGER NOT NULL DEFAULT (unixepoch())
            )");

            // Migrate existing installs: add loop_all_day if missing
            try {
                $this->pdo->exec(
                    'ALTER TABLE schedule ADD COLUMN loop_all_day INTEGER NOT NULL DEFAULT 0'
                );
            } catch (\PDOException $e) {
                // Column already exists — nothing to do
            }
        } catch (PDOException $e) {
            throw new DatabaseException('Could not open database: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    /**
     * Find a user by their username.
     *
     * @return array|null The user row, or null if not found.
     */
    public function findUserByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Find a user by their ID.
     *
     * @return array|null The user row, or null if not found.
     */
    public function findUserById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Find a user by their email address.
     *
     * @return array|null The user row, or null if not found.
     */
    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Create a new user account.
     *
     * The password must already be hashed before being passed in.
     * Role must be 'listener' or 'admin'.
     *
     * @return int The new user's ID.
     * @throws DatabaseException If the username or email is already taken.
     */
    public function createUser(
        string $username,
        string $email,
        string $passwordHash,
        string $role = 'listener'
    ): int {
        if (!in_array($role, ['listener', 'admin'], true)) {
            throw new DatabaseException("Invalid role: {$role}");
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (username, email, password, role)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$username, $email, $passwordHash, $role]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            throw new DatabaseException('Could not create user: ' . $e->getMessage());
        }
    }

    /**
     * Delete a user and all associated sessions and invite tokens.
     *
     * Deletion order respects foreign key constraints:
     * sessions → invite_tokens → users.
     *
     * @throws DatabaseException If the user does not exist.
     */
    public function deleteUser(int $id): void
    {
        if ($this->findUserById($id) === null) {
            throw new DatabaseException("User {$id} not found.");
        }

        $this->pdo->prepare(
            'DELETE FROM sessions WHERE user_id = ?'
        )->execute([$id]);

        $this->pdo->prepare(
            'DELETE FROM invite_tokens WHERE created_by = ?'
        )->execute([$id]);

        $this->pdo->prepare(
            'DELETE FROM users WHERE id = ?'
        )->execute([$id]);
    }

    /**
     * Return all users, ordered by creation date descending.
     *
     * @return array Array of user rows.
     */
    public function getAllUsers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, username, email, role, active, created_at
             FROM users
             ORDER BY created_at DESC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Set a user's active flag.
     *
     * @throws DatabaseException If the user does not exist.
     */
    public function setUserActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET active = ? WHERE id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $id]);

        if ($stmt->rowCount() === 0) {
            throw new DatabaseException("User {$id} not found.");
        }
    }

    /**
     * Set a user's role.
     *
     * @throws DatabaseException If the user does not exist or role is invalid.
     */
    public function setUserRole(int $id, string $role): void
    {
        if (!in_array($role, ['listener', 'admin'], true)) {
            throw new DatabaseException("Invalid role: {$role}");
        }

        $stmt = $this->pdo->prepare(
            'UPDATE users SET role = ? WHERE id = ?'
        );
        $stmt->execute([$role, $id]);

        if ($stmt->rowCount() === 0) {
            throw new DatabaseException("User {$id} not found.");
        }
    }

    /**
     * Update a user's hashed password.
     *
     * The password must already be hashed before being passed in.
     *
     * @throws DatabaseException If the user does not exist.
     */
    public function setUserPassword(int $id, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password = ? WHERE id = ?'
        );
        $stmt->execute([$passwordHash, $id]);

        if ($stmt->rowCount() === 0) {
            throw new DatabaseException("User {$id} not found.");
        }
    }

    // -------------------------------------------------------------------------
    // Sessions
    // -------------------------------------------------------------------------

    /**
     * Create a new session for a user.
     *
     * Generates a cryptographically random token, stores it, and
     * returns the token so it can be set as a cookie.
     *
     * @return string The session token.
     * @throws DatabaseException If the session cannot be created.
     */
    public function createSession(int $userId): string
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = time() + SESSION_LIFETIME;

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO sessions (user_id, token, expires_at)
                 VALUES (?, ?, ?)'
            );
            $stmt->execute([$userId, $token, $expiresAt]);
        } catch (PDOException $e) {
            throw new DatabaseException('Could not create session: ' . $e->getMessage());
        }

        return $token;
    }

    /**
     * Look up a session by token.
     *
     * Returns null if the token does not exist or has expired.
     * Expired sessions are deleted automatically on lookup.
     *
     * @return array|null The session row joined with user data, or null.
     */
    public function findSession(string $token): ?array
    {
        // Clean up this token if it's expired
        $this->pdo->prepare(
            'DELETE FROM sessions WHERE token = ? AND expires_at < ?'
        )->execute([$token, time()]);

        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.token, s.expires_at,
                    u.id AS user_id, u.username, u.role, u.active
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token = ?
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Delete a session by token (logout).
     */
    public function deleteSession(string $token): void
    {
        $this->pdo->prepare(
            'DELETE FROM sessions WHERE token = ?'
        )->execute([$token]);
    }

    /**
     * Delete all sessions belonging to a user.
     *
     * Useful when suspending an account or forcing a password reset.
     */
    public function deleteUserSessions(int $userId): void
    {
        $this->pdo->prepare(
            'DELETE FROM sessions WHERE user_id = ?'
        )->execute([$userId]);
    }

    /**
     * Delete all expired sessions across all users.
     *
     * Run this periodically — the setup cron or a maintenance script.
     *
     * @return int Number of sessions deleted.
     */
    public function purgeExpiredSessions(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM sessions WHERE expires_at < ?'
        );
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }

    // -------------------------------------------------------------------------
    // Invite tokens
    // -------------------------------------------------------------------------

    /**
     * Create a new invite token for a given email address.
     *
     * @param  int    $createdBy  The admin user's ID creating the invite.
     * @return string             The raw token to embed in the invite URL.
     * @throws DatabaseException  If the token cannot be created.
     */
    public function createInviteToken(string $email, int $createdBy): string
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = time() + INVITE_TOKEN_LIFETIME;

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO invite_tokens (token, email, created_by, expires_at)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$token, $email, $createdBy, $expiresAt]);
        } catch (PDOException $e) {
            throw new DatabaseException('Could not create invite token: ' . $e->getMessage());
        }

        return $token;
    }

    /**
     * Look up an invite token.
     *
     * Returns null if the token does not exist, has already been used,
     * or has expired.
     *
     * @return array|null The invite token row, or null.
     */
    public function findInviteToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM invite_tokens
             WHERE token = ?
               AND used = 0
               AND expires_at > ?
             LIMIT 1'
        );
        $stmt->execute([$token, time()]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Mark an invite token as used.
     *
     * Call this immediately after the invited user's account is created.
     */
    public function consumeInviteToken(string $token): void
    {
        $this->pdo->prepare(
            'UPDATE invite_tokens SET used = 1 WHERE token = ?'
        )->execute([$token]);
    }

    /**
     * Return all invite tokens, ordered by creation date descending.
     *
     * @return array Array of invite token rows.
     */
    public function getAllInviteTokens(): array
    {
        $stmt = $this->pdo->query(
            'SELECT t.*, u.username AS created_by_username
             FROM invite_tokens t
             JOIN users u ON u.id = t.created_by
             ORDER BY t.created_at DESC'
        );
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Login attempts
    // -------------------------------------------------------------------------

    /**
     * Get the current failed login attempt count for an IP address.
     *
     * @return int The number of failed attempts, or 0 if none recorded.
     */
    public function getLoginAttempts(string $ip): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempts FROM login_attempts WHERE ip = ? LIMIT 1'
        );
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        return $row !== false ? (int) $row['attempts'] : 0;
    }

    /**
     * Record a failed login attempt for an IP address.
     *
     * Inserts a new row or increments the existing count.
     *
     * @return int The new total attempt count for this IP.
     */
    public function recordLoginAttempt(string $ip): int
    {
        $this->pdo->prepare(
            'INSERT INTO login_attempts (ip, attempts, last_attempt)
             VALUES (?, 1, unixepoch())
             ON CONFLICT(ip) DO UPDATE SET
                 attempts     = attempts + 1,
                 last_attempt = unixepoch()'
        )->execute([$ip]);

        return $this->getLoginAttempts($ip);
    }

    /**
     * Reset the failed login attempt counter for an IP address.
     *
     * Call this on successful login.
     */
    public function resetLoginAttempts(string $ip): void
    {
        $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE ip = ?'
        )->execute([$ip]);
    }

    // -------------------------------------------------------------------------
    // Album art cache
    // -------------------------------------------------------------------------

    /** Cache TTL in seconds. 24 hours. */
    private const ART_TTL = 86400;

    /**
     * Normalise artist + album into a cache key.
     */
    private function artKey(string $artist, string $album): string
    {
        return strtolower(trim($artist)) . '|' . strtolower(trim($album));
    }

    /**
     * Look up a cached album art URL.
     *
     * Returns null if not cached or expired.
     * Returns empty string if previously looked up but no art was found.
     * Returns a URL string if art was found.
     */
    public function getCachedAlbumArt(string $artist, string $album): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT url, cached_at FROM album_art_cache WHERE key = ? LIMIT 1'
        );
        $stmt->execute([$this->artKey($artist, $album)]);
        $row = $stmt->fetch();

        if ($row === false) return null;
        if ((time() - (int) $row['cached_at']) > self::ART_TTL) return null;

        return $row['url'];
    }

    /**
     * Store an album art URL in the cache.
     *
     * Pass an empty string to record a negative result (no art found).
     */
    public function cacheAlbumArt(string $artist, string $album, string $url): void
    {
        $this->pdo->prepare(
            'INSERT INTO album_art_cache (key, url, cached_at)
             VALUES (?, ?, unixepoch())
             ON CONFLICT(key) DO UPDATE SET
                 url       = excluded.url,
                 cached_at = unixepoch()'
        )->execute([$this->artKey($artist, $album), $url]);
    }

    // -------------------------------------------------------------------------
    // Schedule
    // -------------------------------------------------------------------------

    /**
     * Return all schedule entries ordered by day and time.
     */
    public function getSchedules(): array
    {
        $stmt = $this->pdo->query(
            'SELECT s.*, u.username AS created_by_username
             FROM schedule s
             JOIN users u ON u.id = s.created_by
             ORDER BY s.day_of_week IS NULL DESC, s.day_of_week, s.time_of_day'
        );
        return $stmt->fetchAll();
    }

    /**
     * Create a new schedule entry.
     *
     * @param  string   $playlist   MPD playlist name to load.
     * @param  int|null $dayOfWeek  0=Sunday…6=Saturday, or null for every day.
     * @param  string   $timeOfDay  "HH:MM" 24-hour format.
     * @param  int      $userId     Admin user creating the entry.
     * @param  bool     $loopAllDay Whether to repeat the playlist on a loop all day.
     * @return int New entry ID.
     */
    public function createSchedule(
        string $playlist,
        ?int   $dayOfWeek,
        string $timeOfDay,
        int    $userId,
        bool   $loopAllDay = false
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO schedule (playlist_name, day_of_week, time_of_day, loop_all_day, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$playlist, $dayOfWeek, $timeOfDay, $loopAllDay ? 1 : 0, $userId]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Move a schedule entry to a new day/time (used by drag-and-drop).
     *
     * Resets last_run_at so it can fire again at the new time.
     */
    public function updateScheduleTime(int $id, ?int $dayOfWeek, string $timeOfDay): void
    {
        $this->pdo->prepare(
            'UPDATE schedule SET day_of_week = ?, time_of_day = ?, last_run_at = NULL WHERE id = ?'
        )->execute([$dayOfWeek, $timeOfDay, $id]);
    }

    /**
     * Toggle the loop_all_day flag on a schedule entry.
     */
    public function toggleScheduleLoop(int $id, bool $loopAllDay): void
    {
        $this->pdo->prepare(
            'UPDATE schedule SET loop_all_day = ? WHERE id = ?'
        )->execute([$loopAllDay ? 1 : 0, $id]);
    }

    /**
     * Delete a schedule entry.
     */
    public function deleteSchedule(int $id): void
    {
        $this->pdo->prepare('DELETE FROM schedule WHERE id = ?')->execute([$id]);
    }

    /**
     * Toggle the active flag on a schedule entry.
     */
    public function toggleScheduleActive(int $id, bool $active): void
    {
        $this->pdo->prepare(
            'UPDATE schedule SET active = ? WHERE id = ?'
        )->execute([$active ? 1 : 0, $id]);
    }

    /**
     * Return schedules that are due to fire right now.
     *
     * A schedule is due when its day/time matches the current moment and it
     * has not already been triggered within the last 5 minutes.
     */
    public function getDueSchedules(): array
    {
        $dow   = (int) date('w');     // 0=Sun … 6=Sat
        $hhmm  = date('H:i');         // "HH:MM"
        $since = time() - 300;        // don't re-fire within 5 minutes

        $stmt = $this->pdo->prepare(
            'SELECT * FROM schedule
             WHERE active = 1
               AND (day_of_week IS NULL OR day_of_week = ?)
               AND time_of_day = ?
               AND (last_run_at IS NULL OR last_run_at < ?)'
        );
        $stmt->execute([$dow, $hhmm, $since]);
        return $stmt->fetchAll();
    }

    /**
     * Mark a schedule entry as just executed.
     */
    public function markScheduleRun(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE schedule SET last_run_at = unixepoch() WHERE id = ?'
        )->execute([$id]);
    }
}