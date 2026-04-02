<?php
 
/**
 * config.php — Application configuration
 *
 * This file is included by the front controller before anything else.
 * Never commit secrets (passwords, keys) to the repository — if you
 * add any, pull them from environment variables instead.
 */
 
// ── Database ──────────────────────────────────────────────────────────────────
 
/** Absolute path to the SQLite database file. */
define('DB_PATH', '/opt/radio/data/radio.db');
 
// ── MPD ───────────────────────────────────────────────────────────────────────
 
/** Hostname or IP address of the MPD daemon. */
define('MPD_HOST', '127.0.0.1');
 
/** TCP port MPD listens on for control connections. */
define('MPD_PORT', 6600);
 
// ── Sessions ──────────────────────────────────────────────────────────────────
 
/** How long a login session lasts before expiring (seconds). 1 week. */
define('SESSION_LIFETIME', 60 * 60 * 24 * 7);
 
// ── Invite Tokens ─────────────────────────────────────────────────────────────
 
/** How long an unused invite link remains valid (seconds). 2 days. */
define('INVITE_TOKEN_LIFETIME', 60 * 60 * 24 * 2);
 
// ── Site ──────────────────────────────────────────────────────────────────────
 
/** Human-readable name of the station. */
define('SITE_NAME', 'Future Radio');
 
/** Public URL of the audio stream, relative to the site root. */
define('STREAM_URL', '/stream');
 