<?php

/**
 * router.php — Front controller request dispatcher
 *
 * Parses the incoming request URI and HTTP method, then dispatches
 * to the appropriate handler. All handlers have access to the shared
 * $db and $auth instances.
 *
 * Requires config.php, db.php, and auth.php to be loaded first.
 */

class Router
{
    /** Registered routes: [method, pattern, handler] */
    private array $routes = [];

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    /**
     * @param Database $db   Shared database instance.
     * @param Auth     $auth Shared auth instance.
     */
    public function __construct(
        private readonly Database $db,
        private readonly Auth     $auth
    ) {}

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    /**
     * Register a GET route.
     *
     * @param string   $pattern  URI pattern, e.g. '/admin/users'
     * @param callable $handler  Handler callable, receives ($params) array.
     */
    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    /**
     * Register a POST route.
     *
     * @param string   $pattern  URI pattern, e.g. '/admin/users/toggle'
     * @param callable $handler  Handler callable, receives ($params) array.
     */
    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    /**
     * Match the current request against registered routes and call the handler.
     *
     * URI parameters are extracted from patterns like '/register/{token}'
     * and passed to the handler as an associative array.
     *
     * Sends a 404 if no route matches.
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri    = rtrim($uri, '/') ?: '/';

        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }

            $params = $this->matchPattern($pattern, $uri);
            if ($params !== null) {
                $handler($params);
                return;
            }
        }

        $this->send404();
    }

    // -------------------------------------------------------------------------
    // Pattern matching
    // -------------------------------------------------------------------------

    /**
     * Match a URI against a route pattern.
     *
     * Patterns may contain named segments like {token} which are extracted
     * into the returned params array. Returns null if the URI does not match.
     *
     * @return array|null Associative array of captured params, or null.
     */
    private function matchPattern(string $pattern, string $uri): ?array
    {
        // Convert pattern placeholders to named regex groups
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $uri, $matches)) {
            return null;
        }

        // Return only named captures, not numeric ones
        return array_filter(
            $matches,
            fn($key) => !is_int($key),
            ARRAY_FILTER_USE_KEY
        );
    }

    // -------------------------------------------------------------------------
    // Default responses
    // -------------------------------------------------------------------------

    /**
     * Send a 404 response.
     */
    private function send404(): void
    {
        http_response_code(404);
        require APP_DIR . '/views/404.php';
    }

    // -------------------------------------------------------------------------
    // Route definitions
    // -------------------------------------------------------------------------

    /**
     * Register all application routes.
     *
     * Called once from the front controller after instantiation.
     */
    public function registerRoutes(): void
    {
        $db   = $this->db;
        $auth = $this->auth;

        // ── Public ────────────────────────────────────────────────────────────

        $this->get('/', function () use ($auth) {
            $auth->requireAuth();
            require VIEWS_DIR . '/player.php';
        });

        $this->get('/login', function () use ($auth) {
            if ($auth->isLoggedIn()) {
                header('Location: /');
                exit;
            }
            require VIEWS_DIR . '/login.php';
        });

        $this->post('/login', function () use ($auth) {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            try {
                $auth->login($username, $password);
                header('Location: /');
                exit;
            } catch (AuthException $e) {
                $error = $e->getMessage();
                require VIEWS_DIR . '/login.php';
            }
        });

        $this->get('/logout', function () use ($auth) {
            $auth->logout();
            header('Location: /login');
            exit;
        });

        $this->get('/register', function () use ($auth, $db) {
            $token  = trim($_GET['token'] ?? '');
            $invite = $token !== '' ? $db->findInviteToken($token) : null;

            if ($invite === null) {
                header('Location: /unauthorized');
                exit;
            }

            require VIEWS_DIR . '/register.php';
        });

        $this->post('/register', function () use ($auth, $db) {
            $token    = trim($_POST['token']    ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password']      ?? '';
            $confirm  = $_POST['confirm']       ?? '';

            if ($password !== $confirm) {
                $error  = 'Passwords do not match.';
                $invite = $db->findInviteToken($token);
                require VIEWS_DIR . '/register.php';
                return;
            }

            try {
                $auth->registerWithInvite($token, $username, $password);
                header('Location: /');
                exit;
            } catch (AuthException $e) {
                $error  = $e->getMessage();
                $invite = $db->findInviteToken($token);
                require VIEWS_DIR . '/register.php';
            }
        });

        $this->get('/unauthorized', function () {
            http_response_code(403);
            require VIEWS_DIR . '/unauthorized.php';
        });

        // ── API ───────────────────────────────────────────────────────────────

        $this->get('/now-playing', function () use ($auth, $db) {
            header('Content-Type: application/json');

            if (!$auth->isLoggedIn()) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }

            try {
                $mpd  = new MPD(MPD_HOST, MPD_PORT);
                $mpd->connect();
                $song   = $mpd->getCurrentSong();
                $status = $mpd->getStatus();
                $mpd->disconnect();

                echo json_encode([
                    'title'    => $song?->displayTitle() ?? '',
                    'artist'   => $song?->artist         ?? '',
                    'album'    => $song?->album           ?? '',
                    'duration' => $song?->duration        ?? 0,
                    'elapsed'  => $status->elapsed,
                    'state'    => $status->state,
                ]);
            } catch (MpdException $e) {
                http_response_code(503);
                echo json_encode(['error' => 'Stream unavailable']);
            }

            exit;
        });

        // ── Admin ─────────────────────────────────────────────────────────────

        $this->get('/admin', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();
            require VIEWS_DIR . '/admin/dashboard.php';
        });

        $this->get('/admin/users', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();
            $users  = $db->getAllUsers();
            $tokens = $db->getAllInviteTokens();
            require VIEWS_DIR . '/admin/users.php';
        });

        $this->post('/admin/users/toggle', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $id     = (int) ($_POST['user_id'] ?? 0);
            $active = (bool) ($_POST['active'] ?? false);

            try {
                $db->setUserActive($id, $active);
                // Suspend sessions immediately if deactivating
                if (!$active) {
                    $db->deleteUserSessions($id);
                }
            } catch (DatabaseException $e) {
                // Log and continue — admin will see stale state
                error_log('toggle user active failed: ' . $e->getMessage());
            }

            header('Location: /admin/users');
            exit;
        });

        $this->post('/admin/users/role', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $id   = (int) ($_POST['user_id'] ?? 0);
            $role = trim($_POST['role'] ?? '');

            try {
                $db->setUserRole($id, $role);
            } catch (DatabaseException $e) {
                error_log('set user role failed: ' . $e->getMessage());
            }

            header('Location: /admin/users');
            exit;
        });

        $this->post('/admin/invite', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $email = trim($_POST['email'] ?? '');
            $user  = $auth->currentUser();

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error  = 'A valid email address is required.';
                $users  = $db->getAllUsers();
                $tokens = $db->getAllInviteTokens();
                require VIEWS_DIR . '/admin/users.php';
                return;
            }

            try {
                $token = $db->createInviteToken($email, $user['user_id']);
                $inviteUrl = 'https://futureradio.net/register?token=' . $token;
                $success   = 'Invite created: ' . $inviteUrl;
            } catch (DatabaseException $e) {
                $error = 'Could not create invite: ' . $e->getMessage();
            }

            $users  = $db->getAllUsers();
            $tokens = $db->getAllInviteTokens();
            require VIEWS_DIR . '/admin/users.php';
        });

        $this->get('/admin/queue', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            try {
                $mpd      = new MPD(MPD_HOST, MPD_PORT);
                $mpd->connect();
                $queue    = $mpd->getPlaylist();
                $current  = $mpd->getCurrentSong();
                $status   = $mpd->getStatus();
                $mpd->disconnect();
            } catch (MpdException $e) {
                $mpdError = $e->getMessage();
                $queue    = [];
                $current  = null;
                $status   = null;
            }

            require VIEWS_DIR . '/admin/queue.php';
        });

        $this->post('/admin/queue/action', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $action = trim($_POST['action'] ?? '');

            try {
                $mpd = new MPD(MPD_HOST, MPD_PORT);
                $mpd->connect();

                match ($action) {
                    'play'     => $mpd->play(),
                    'pause'    => $mpd->togglePause(),
                    'stop'     => $mpd->stop(),
                    'next'     => $mpd->next(),
                    'previous' => $mpd->previous(),
                    'clear'    => $mpd->clearQueue(),
                    'shuffle'  => $mpd->shuffle(),
                    'delete'   => $mpd->deleteId((int) ($_POST['song_id'] ?? 0)),
                    default    => null,
                };

                $mpd->disconnect();
            } catch (MpdException $e) {
                error_log('MPD queue action failed: ' . $e->getMessage());
            }

            header('Location: /admin/queue');
            exit;
        });
    }
}