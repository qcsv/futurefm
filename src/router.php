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

        $this->get('/scp', function () use ($auth) {
            $auth->resetAttempts();
            header('Location: /scp.png');
            exit;
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

        $this->get('/album-art', function () use ($auth, $db) {
            header('Content-Type: application/json');

            if (!$auth->isLoggedIn()) {
                http_response_code(401);
                echo json_encode(['url' => '']);
                exit;
            }

            $artist = trim($_GET['artist'] ?? '');
            $album  = trim($_GET['album']  ?? '');

            if ($artist === '' || $album === '') {
                echo json_encode(['url' => '']);
                exit;
            }

            // Check cache first
            $cached = $db->getCachedAlbumArt($artist, $album);
            if ($cached !== null) {
                echo json_encode(['url' => $cached]);
                exit;
            }

            // Query MusicBrainz for a release ID
            $url   = '';
            $error = '';
            try {
                $query = http_build_query([
                    'query'  => 'artist:"' . $artist . '" AND release:"' . $album . '"',
                    'limit'  => '1',
                    'fmt'    => 'json',
                ]);

                $mbContext = stream_context_create([
                    'http' => [
                        'header'  => "User-Agent: FutureRadio/1.0 (futureradio.net)\r\n",
                        'timeout' => 5,
                    ],
                ]);

                $response = @file_get_contents(
                    'https://musicbrainz.org/ws/2/release/?' . $query,
                    false,
                    $mbContext
                );

                if ($response === false) {
                    $error = 'MusicBrainz request failed';
                    error_log("Album art: MusicBrainz request failed for artist=\"{$artist}\" album=\"{$album}\"");
                } else {
                    $data      = json_decode($response, true);
                    $releaseId = $data['releases'][0]['id'] ?? null;

                    if ($releaseId === null) {
                        $error = 'No MusicBrainz release found';
                    } else {
                        // Use the Cover Art Archive index JSON to check existence without
                        // downloading the full image.
                        $caaIndex = @file_get_contents(
                            "https://coverartarchive.org/release/{$releaseId}",
                            false,
                            stream_context_create(['http' => [
                                'timeout' => 5,
                                'header'  => "User-Agent: FutureRadio/1.0 (futureradio.net)\r\n",
                            ]])
                        );

                        if ($caaIndex !== false) {
                            $url = "https://coverartarchive.org/release/{$releaseId}/front-250";
                        } else {
                            $error = 'No cover art found on Cover Art Archive';
                        }
                    }
                }
            } catch (\Throwable $e) {
                $error = 'Exception: ' . $e->getMessage();
                error_log('Album art lookup failed: ' . $e->getMessage());
            }

            // Cache the result (including empty string for misses)
            $db->cacheAlbumArt($artist, $album, $url);

            echo json_encode(['url' => $url, 'error' => $error]);
            exit;
        });

        $this->get('/now-playing', function () use ($auth, $db) {
            header('Content-Type: application/json');

            if (!$auth->isLoggedIn()) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }

            try {
                $mpd = new MPD(MPD_HOST, MPD_PORT);
                $mpd->connect();

                // Fire any scheduled playlists due at this moment
                try {
                    foreach ($db->getDueSchedules() as $sched) {
                        $mpd->clearQueue();
                        $mpd->loadPlaylist($sched['playlist_name']);
                        // Loop the last playlist of the day if the day's loop-last toggle is on
                        $dow = (int) $sched['day_of_week'];
                        $shouldLoop = $db->isDayLoopLastEnabled($dow)
                            && $db->isLastScheduleForDay((int) $sched['id'], $dow);
                        $mpd->setRepeat($shouldLoop);
                        $mpd->play();
                        $db->markScheduleRun((int) $sched['id']);
                    }
                } catch (\Throwable $e) {
                    error_log('Schedule execution failed: ' . $e->getMessage());
                }

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

        $this->post('/admin/users/delete', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $id          = (int) ($_POST['user_id'] ?? 0);
            $currentUser = $auth->currentUser();

            // Prevent admin from deleting themselves
            if ($id === (int) $currentUser['user_id']) {
                error_log('Admin attempted to delete their own account.');
                header('Location: /admin/users');
                exit;
            }

            try {
                $db->deleteUser($id);
            } catch (DatabaseException $e) {
                error_log('delete user failed: ' . $e->getMessage());
            }

            header('Location: /admin/users');
            exit;
        });

        $this->get('/admin/queue', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            // ── Current queue and playback status ─────────────────────────────

            try {
                $mpd     = new MPD(MPD_HOST, MPD_PORT);
                $mpd->connect();
                $queue   = $mpd->getPlaylist();
                $current = $mpd->getCurrentSong();
                $status  = $mpd->getStatus();
                $mpd->disconnect();
            } catch (MpdException $e) {
                $mpdError = $e->getMessage();
                $queue    = [];
                $current  = null;
                $status   = null;
            }

            // ── Library browser ───────────────────────────────────────────────

            $tab          = trim($_GET['tab']    ?? '');
            $filter       = trim($_GET['filter'] ?? '');
            $search       = trim($_GET['search'] ?? '');
            $librarySongs = [];
            $libraryList  = [];
            $libraryError = null;

            try {
                $mpd = new MPD(MPD_HOST, MPD_PORT);
                $mpd->connect();

                if ($search !== '') {
                    // Search across all tags
                    $librarySongs = $mpd->search('any', $search);

                } elseif ($tab === 'artist') {
                    if ($filter !== '') {
                        // Songs by this artist
                        $librarySongs = $mpd->find('artist', $filter);
                    } else {
                        // List all artists
                        $libraryList = $mpd->listTag('artist');
                    }

                } elseif ($tab === 'album') {
                    if ($filter !== '') {
                        // Songs on this album
                        $librarySongs = $mpd->find('album', $filter);
                    } else {
                        // List all albums
                        $libraryList = $mpd->listTag('album');
                    }
                }
                // Default (no tab, no search) — show nothing until user picks a tab

                $mpd->disconnect();
            } catch (MpdException $e) {
                $libraryError = $e->getMessage();
            }

            require VIEWS_DIR . '/admin/queue.php';
        });

        $this->post('/admin/queue/add', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $uri = trim($_POST['uri'] ?? '');

            if ($uri !== '') {
                try {
                    $mpd = new MPD(MPD_HOST, MPD_PORT);
                    $mpd->connect();
                    $mpd->add($uri);
                    $mpd->disconnect();
                } catch (MpdException $e) {
                    error_log('MPD add failed: ' . $e->getMessage());
                }
            }

            // Preserve the current tab/filter/search state on redirect
            $tab    = trim($_POST['tab']    ?? '');
            $filter = trim($_POST['filter'] ?? '');
            $search = trim($_POST['search'] ?? '');

            $qs = http_build_query(array_filter([
                'tab'    => $tab,
                'filter' => $filter,
                'search' => $search,
            ]));

            header('Location: /admin/queue' . ($qs !== '' ? '?' . $qs : ''));
            exit;
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

        // ── Add entire album or artist to queue in one shot ───────────────────

        $this->post('/admin/queue/add-all', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $type  = trim($_POST['type']  ?? '');
            $value = trim($_POST['value'] ?? '');

            if ($value !== '' && in_array($type, ['album', 'artist'], true)) {
                try {
                    $mpd = new MPD(MPD_HOST, MPD_PORT);
                    $mpd->connect();
                    $mpd->findAdd($type, $value);
                    $mpd->disconnect();
                } catch (MpdException $e) {
                    error_log('MPD findadd failed: ' . $e->getMessage());
                }
            }

            $tab    = trim($_POST['tab']    ?? '');
            $filter = trim($_POST['filter'] ?? '');
            $search = trim($_POST['search'] ?? '');

            $qs = http_build_query(array_filter([
                'tab'    => $tab,
                'filter' => $filter,
                'search' => $search,
            ]));

            header('Location: /admin/queue' . ($qs !== '' ? '?' . $qs : ''));
            exit;
        });

        // ── Playlists ─────────────────────────────────────────────────────────

        $this->get('/admin/playlists', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $viewName      = trim($_GET['view']   ?? '');
            $search        = trim($_GET['search'] ?? '');
            $playlists     = [];
            $playlistSongs = [];
            $searchResults = [];
            $mpdError      = null;

            try {
                $mpd = new MPD(MPD_HOST, MPD_PORT);
                $mpd->connect();
                $playlists = $mpd->listSavedPlaylists();

                if ($viewName !== '') {
                    $playlistSongs = $mpd->listPlaylistInfo($viewName);
                }

                if ($viewName !== '' && $search !== '') {
                    $searchResults = $mpd->search('any', $search);
                }

                $mpd->disconnect();
            } catch (MpdException $e) {
                $mpdError = $e->getMessage();
            }

            require VIEWS_DIR . '/admin/playlists.php';
        });

        $this->post('/admin/playlists/create', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $name = trim($_POST['name'] ?? '');
            if ($name !== '') {
                try {
                    $mpd = new MPD(MPD_HOST, MPD_PORT);
                    $mpd->connect();
                    $mpd->savePlaylist($name);
                    $mpd->disconnect();
                } catch (MpdException $e) {
                    error_log('MPD save playlist failed: ' . $e->getMessage());
                }
            }

            header('Location: /admin/playlists');
            exit;
        });

        $this->post('/admin/playlists/delete', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $name = trim($_POST['name'] ?? '');
            if ($name !== '') {
                try {
                    $mpd = new MPD(MPD_HOST, MPD_PORT);
                    $mpd->connect();
                    $mpd->deletePlaylist($name);
                    $mpd->disconnect();
                } catch (MpdException $e) {
                    error_log('MPD delete playlist failed: ' . $e->getMessage());
                }
            }

            header('Location: /admin/playlists');
            exit;
        });

        $this->post('/admin/playlists/load', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $name = trim($_POST['name'] ?? '');
            if ($name !== '') {
                try {
                    $mpd = new MPD(MPD_HOST, MPD_PORT);
                    $mpd->connect();
                    $mpd->loadPlaylist($name);
                    $mpd->disconnect();
                } catch (MpdException $e) {
                    error_log('MPD load playlist failed: ' . $e->getMessage());
                }
            }

            header('Location: /admin/queue');
            exit;
        });

        $this->post('/admin/playlists/remove-song', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $name = trim($_POST['name'] ?? '');
            $pos  = (int) ($_POST['pos'] ?? -1);

            if ($name !== '' && $pos >= 0) {
                try {
                    $mpd = new MPD(MPD_HOST, MPD_PORT);
                    $mpd->connect();
                    $mpd->playlistDelete($name, $pos);
                    $mpd->disconnect();
                } catch (MpdException $e) {
                    error_log('MPD playlistdelete failed: ' . $e->getMessage());
                }
            }

            header('Location: /admin/playlists?view=' . urlencode($name));
            exit;
        });

        $this->post('/admin/playlists/add-song', function () use ($auth) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $playlist = trim($_POST['playlist'] ?? '');
            $uri      = trim($_POST['uri']      ?? '');
            $search   = trim($_POST['search']   ?? '');

            if ($playlist !== '' && $uri !== '') {
                try {
                    $mpd = new MPD(MPD_HOST, MPD_PORT);
                    $mpd->connect();
                    $mpd->playlistAdd($playlist, $uri);
                    $mpd->disconnect();
                } catch (MpdException $e) {
                    error_log('MPD playlistadd failed: ' . $e->getMessage());
                }
            }

            $qs = http_build_query(array_filter([
                'view'   => $playlist,
                'search' => $search,
            ]));
            header('Location: /admin/playlists?' . $qs);
            exit;
        });

        // ── Schedule ──────────────────────────────────────────────────────────

        $this->get('/admin/schedule', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $schedules         = $db->getSchedules();
            $dayLoopSettings   = $db->getDayLoopSettings();
            $playlists         = [];
            $playlistDurations = []; // name => total seconds

            try {
                $mpd = new MPD(MPD_HOST, MPD_PORT);
                $mpd->connect();
                $playlists = $mpd->listSavedPlaylists();

                // Compute total duration of each playlist for the calendar blocks
                foreach ($playlists as $pl) {
                    try {
                        $songs = $mpd->listPlaylistInfo($pl['playlist']);
                        $playlistDurations[$pl['playlist']] = array_sum(
                            array_map(fn($s) => $s->duration, $songs)
                        );
                    } catch (MpdException $e) {
                        $playlistDurations[$pl['playlist']] = 0;
                    }
                }

                $mpd->disconnect();
            } catch (MpdException $e) {
                // playlists stays empty — calendar shows no sidebar items
            }

            require VIEWS_DIR . '/admin/schedule.php';
        });

        $this->post('/admin/schedule/create', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $playlist   = trim($_POST['playlist']    ?? '');
            $dayRaw     = trim($_POST['day_of_week'] ?? '');
            $dayOfWeek  = $dayRaw !== '' ? (int) $dayRaw : null;
            $timeOfDay  = trim($_POST['time_of_day'] ?? '');
            $user       = $auth->currentUser();

            if ($playlist !== '' && $timeOfDay !== '' && $dayOfWeek !== null) {
                try {
                    $db->createSchedule(
                        $playlist, $dayOfWeek, $timeOfDay,
                        (int) $user['user_id'], false
                    );
                } catch (DatabaseException $e) {
                    error_log('Create schedule failed: ' . $e->getMessage());
                }
            }

            // Respond with 204 for fetch() callers; redirect for form callers
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                http_response_code(204);
                exit;
            }
            header('Location: /admin/schedule');
            exit;
        });

        $this->post('/admin/schedule/move', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $id        = (int) ($_POST['id'] ?? 0);
            $dayRaw    = trim($_POST['day_of_week'] ?? '');
            $dayOfWeek = $dayRaw !== '' ? (int) $dayRaw : null;
            $timeOfDay = trim($_POST['time_of_day'] ?? '');

            if ($id > 0 && $timeOfDay !== '' && $dayOfWeek !== null) {
                $db->updateScheduleTime($id, $dayOfWeek, $timeOfDay);
            }

            http_response_code(204);
            exit;
        });

        $this->post('/admin/schedule/delete', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->deleteSchedule($id);
            }

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                http_response_code(204);
                exit;
            }
            header('Location: /admin/schedule');
            exit;
        });

        $this->post('/admin/schedule/toggle', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $id     = (int)  ($_POST['id']     ?? 0);
            $active = (bool) ($_POST['active']  ?? 0);

            if ($id > 0) {
                $db->toggleScheduleActive($id, $active);
            }

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                http_response_code(204);
                exit;
            }
            header('Location: /admin/schedule');
            exit;
        });

        $this->post('/admin/schedule/toggle-day-loop', function () use ($auth, $db) {
            $auth->requireAuth();
            $auth->requireAdmin();

            $dayOfWeek = (int)  ($_POST['day_of_week'] ?? -1);
            $loopLast  = (bool) ($_POST['loop_last']   ?? 0);

            if ($dayOfWeek >= 0 && $dayOfWeek <= 6) {
                $db->setDayLoopLast($dayOfWeek, $loopLast);
            }

            http_response_code(204);
            exit;
        });
    }
}