<?php

/**
 * index.php — Front controller
 *
 * Every request passes through here. Loads config, bootstraps the
 * shared instances, registers routes, and dispatches.
 *
 * Apache rewrites everything except real files and /stream to this file.
 */

declare(strict_types=1);

// ── Path constants ────────────────────────────────────────────────────────────

define('APP_DIR',   '/opt/radio');
define('SRC_DIR',   APP_DIR . '/src');
define('VIEWS_DIR', APP_DIR . '/views');

// ── Load config and source files ──────────────────────────────────────────────

require_once APP_DIR  . '/config/config.php';
require_once SRC_DIR  . '/db.php';
require_once SRC_DIR  . '/auth.php';
require_once SRC_DIR  . '/mpd.php';
require_once SRC_DIR  . '/router.php';

// ── Bootstrap shared instances ────────────────────────────────────────────────

try {
    $db = new Database();
} catch (DatabaseException $e) {
    http_response_code(500);
    error_log('Database failed to open: ' . $e->getMessage());
    die('Service unavailable.');
}

$auth   = new Auth($db);
$router = new Router($db, $auth);

// ── Register routes and dispatch ──────────────────────────────────────────────

$router->registerRoutes();
$router->dispatch();