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
}