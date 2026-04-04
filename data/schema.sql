CREATE TABLE IF NOT EXISTS users (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    username    TEXT NOT NULL UNIQUE,
    email       TEXT NOT NULL UNIQUE,
    password    TEXT NOT NULL,
    role        TEXT NOT NULL DEFAULT 'listener',
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS sessions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id),
    token       TEXT NOT NULL UNIQUE,
    created_at  INTEGER NOT NULL DEFAULT (unixepoch()),
    expires_at  INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS invite_tokens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    token       TEXT NOT NULL UNIQUE,
    email       TEXT NOT NULL,
    created_by  INTEGER NOT NULL REFERENCES users(id),
    created_at  INTEGER NOT NULL DEFAULT (unixepoch()),
    expires_at  INTEGER NOT NULL,
    used        INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS login_attempts (
    ip           TEXT PRIMARY KEY,
    attempts     INTEGER NOT NULL DEFAULT 0,
    last_attempt INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS album_art_cache (
    key        TEXT PRIMARY KEY,  -- "artist|album" normalized to lowercase
    url        TEXT NOT NULL,     -- cover art URL, empty string if not found
    cached_at  INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS schedule (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    playlist_name TEXT NOT NULL,
    day_of_week   INTEGER NOT NULL, -- 0=Sun, 1=Mon, ..., 6=Sat
    time_of_day   TEXT NOT NULL,    -- "HH:MM" 24-hour format
    active        INTEGER NOT NULL DEFAULT 1,
    loop_all_day  INTEGER NOT NULL DEFAULT 0,
    last_run_at   INTEGER,          -- unix timestamp of last execution
    created_by    INTEGER NOT NULL REFERENCES users(id),
    created_at    INTEGER NOT NULL DEFAULT (unixepoch())
);

CREATE TABLE IF NOT EXISTS schedule_day_settings (
    day_of_week  INTEGER PRIMARY KEY, -- 0=Sun, 1=Mon, ..., 6=Sat
    loop_last    INTEGER NOT NULL DEFAULT 0  -- 1 = loop the last playlist of the day
);
