<?php
 
/**
 * auth.php — Authentication and access control
 *
 * Handles password hashing, login, logout, session resolution,
 * registration via invite token, and route access guards.
 *
 * Depends on Database from db.php and constants from config.php.
 */
 
class AuthException extends RuntimeException {}
 
class Auth
{
    /** Cookie name used to store the session token. */
    private const COOKIE_NAME = 'radio_session';
 
    /** Number of failed login attempts before lockout. */
    private const MAX_ATTEMPTS = 3;
 
    /** Resolved user for the current request, populated lazily. */
    private ?array $currentUser = null;
 
    /** Whether we have already attempted to resolve the current user. */
    private bool $resolved = false;
 
    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------
 
    /**
     * @param Database $db The database instance to use for all queries.
     */
    public function __construct(private readonly Database $db) {}
 
    // -------------------------------------------------------------------------
    // Password
    // -------------------------------------------------------------------------
 
    /**
     * Hash a plaintext password for storage.
     *
     * Always use this method — never call password_hash() directly
     * elsewhere in the codebase.
     *
     * @return string The hashed password.
     */
    public function hashPassword(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_BCRYPT);
    }
 
    /**
     * Verify a plaintext password against a stored hash.
     *
     * @return bool True if the password matches.
     */
    public function verifyPassword(string $plaintext, string $hash): bool
    {
        return password_verify($plaintext, $hash);
    }
 
    // -------------------------------------------------------------------------
    // Login / logout
    // -------------------------------------------------------------------------
 
    /**
     * Attempt to log in with a username and password.
     *
     * On success, creates a session, sets the session cookie, and resets
     * the failed login counter for this IP.
     *
     * On third failure, redirects to the memetic kill agent.
     * On prior failures, redirects to /login.
     *
     * @throws AuthException If the credentials are invalid or the account
     *                       is inactive.
     */
    public function login(string $username, string $password): void
    {
        $ip = $this->getClientIp();
 
        // Check attempt count before doing anything else
        $attempts = $this->db->getLoginAttempts($ip);
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->redirect('/scp');
        }

        $user = $this->db->findUserByUsername($username);

        if ($user === null || !$this->verifyPassword($password, $user['password'])) {
            $newAttempts = $this->db->recordLoginAttempt($ip);

            if ($newAttempts >= self::MAX_ATTEMPTS) {
                $this->redirect('/scp');
            }
 
            $remaining = self::MAX_ATTEMPTS - $newAttempts;
            throw new AuthException(
                "Invalid username or password. {$remaining} attempt(s) remaining."
            );
        }
 
        if (!$user['active']) {
            // Don't count this as a failed attempt — wrong credential
            // is not the same as a suspended account
            throw new AuthException('This account has been disabled.');
        }
 
        // Successful login — reset the counter
        $this->db->resetLoginAttempts($ip);
 
        $token = $this->db->createSession($user['id']);
        $this->setSessionCookie($token);
 
        // Cache the resolved user for the rest of this request
        $this->currentUser = $user;
        $this->resolved    = true;
    }
 
    /**
     * Log out the current user.
     *
     * Deletes the session from the database and clears the cookie.
     * Safe to call even if nobody is logged in.
     */
    public function logout(): void
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
 
        if ($token !== null) {
            $this->db->deleteSession($token);
        }
 
        $this->clearSessionCookie();
        $this->currentUser = null;
        $this->resolved    = false;
    }
 
    // -------------------------------------------------------------------------
    // Current user / session resolution
    // -------------------------------------------------------------------------
 
    /**
     * Return the currently logged-in user for this request.
     *
     * Reads the session cookie, validates it against the database,
     * and returns the user row. Returns null if nobody is logged in
     * or the session has expired.
     *
     * Result is cached — safe to call multiple times per request.
     *
     * @return array|null The user row, or null if not authenticated.
     */
    public function currentUser(): ?array
    {
        if ($this->resolved) {
            return $this->currentUser;
        }
 
        $this->resolved = true;
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
 
        if ($token === null) {
            return null;
        }
 
        $session = $this->db->findSession($token);
 
        if ($session === null) {
            $this->clearSessionCookie();
            return null;
        }
 
        if (!$session['active']) {
            $this->db->deleteSession($token);
            $this->clearSessionCookie();
            return null;
        }
 
        $this->currentUser = $session;
        return $this->currentUser;
    }
 
    /**
     * Return true if a user is currently logged in.
     */
    public function isLoggedIn(): bool
    {
        return $this->currentUser() !== null;
    }
 
    /**
     * Return true if the current user is an admin.
     */
    public function isAdmin(): bool
    {
        $user = $this->currentUser();
        return $user !== null && $user['role'] === 'admin';
    }
 
    // -------------------------------------------------------------------------
    // Access guards
    // -------------------------------------------------------------------------
 
    /**
     * Require the user to be logged in.
     *
     * Redirects to /login if not authenticated. Call at the top of any
     * handler that requires a logged-in user.
     */
    public function requireAuth(): void
    {
        if (!$this->isLoggedIn()) {
            $this->redirect('/login');
        }
    }
 
    /**
     * Require the current user to be an admin.
     *
     * Redirects to /unauthorized if not an admin. Call at the top of any
     * handler that requires admin privileges.
     */
    public function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            $this->redirect('/unauthorized');
        }
    }
 
    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------
 
    /**
     * Register a new user via an invite token.
     *
     * Validates the token, creates the account, marks the token as used,
     * and logs the new user straight in.
     *
     * @throws AuthException If the token is invalid, expired, or already used,
     *                       or if the username or email is already taken.
     */
    public function registerWithInvite(
        string $token,
        string $username,
        string $password
    ): void {
        $invite = $this->db->findInviteToken($token);
 
        if ($invite === null) {
            throw new AuthException('This invite link is invalid or has expired.');
        }
 
        $passwordHash = $this->hashPassword($password);
 
        try {
            $userId = $this->db->createUser(
                username:     $username,
                email:        $invite['email'],
                passwordHash: $passwordHash,
                role:         'listener'
            );
        } catch (DatabaseException $e) {
            throw new AuthException('Could not create account: ' . $e->getMessage());
        }
 
        $this->db->consumeInviteToken($token);
 
        // Log the new user straight in
        $sessionToken = $this->db->createSession($userId);
        $this->setSessionCookie($sessionToken);
 
        $this->currentUser = $this->db->findUserById($userId);
        $this->resolved    = true;
    }
 
    // -------------------------------------------------------------------------
    // Cookie management
    // -------------------------------------------------------------------------
 
    /**
     * Set the session cookie.
     */
    private function setSessionCookie(string $token): void
    {
        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires'  => time() + SESSION_LIFETIME,
                'path'     => '/',
                'httponly' => true,   // Not accessible via JavaScript
                'samesite' => 'Lax', // CSRF protection
                'secure'   => isset($_SERVER['HTTPS']), // HTTPS only if available
            ]
        );
    }
 
    /**
     * Clear the session cookie.
     */
    private function clearSessionCookie(): void
    {
        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }
 
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
 
    /**
     * Reset the login attempt counter for the current client IP.
     *
     * Called when the joke page loads so the user can try again.
     */
    public function resetAttempts(): void
    {
        $this->db->resetLoginAttempts($this->getClientIp());
    }

    /**
     * Get the client IP address.
     *
     * Checks X-Forwarded-For first in case Apache is proxying, falls
     * back to REMOTE_ADDR.
     */
    private function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // X-Forwarded-For can be a comma-separated list — take the first
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
 
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
 
    /**
     * Redirect to a URL and stop execution.
     */
    private function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}