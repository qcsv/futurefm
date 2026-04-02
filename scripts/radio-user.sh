#!/bin/bash
set -e

# radio-user — Manage futureradio.net user accounts
#
# Usage:
#   radio-user create <username> <email> [--admin]
#   radio-user list
#   radio-user disable <username>
#   radio-user enable <username>
#   radio-user reset-password <username>

DB="/opt/radio/data/radio.db"

# ── Helpers ───────────────────────────────────────────────────────────────────

die() {
    echo "ERROR: $1" >&2
    exit 1
}

usage() {
    echo "Usage:"
    echo "  radio-user create <username> <email> [--admin]"
    echo "  radio-user list"
    echo "  radio-user disable <username>"
    echo "  radio-user enable <username>"
    echo "  radio-user reset-password <username>"
    exit 1
}

require_db() {
    [ -f "$DB" ] || die "Database not found at $DB. Has setup.sh been run?"
}

query() {
    sqlite3 "$DB" "$1"
}

# Hash a password using PHP's password_hash() so it matches auth.php
hash_password() {
    local plaintext="$1"
    php -r "echo password_hash('$plaintext', PASSWORD_BCRYPT);"
}

prompt_password() {
    local pass1 pass2
    while true; do
        read -rsp "Password: " pass1
        echo
        read -rsp "Confirm password: " pass2
        echo
        if [ "$pass1" = "$pass2" ]; then
            if [ ${#pass1} -lt 8 ]; then
                echo "Password must be at least 8 characters."
            else
                PASSWORD="$pass1"
                return
            fi
        else
            echo "Passwords do not match. Try again."
        fi
    done
}

user_exists() {
    local username="$1"
    local count
    count=$(query "SELECT COUNT(*) FROM users WHERE username = '$username';")
    [ "$count" -gt 0 ]
}

# ── Subcommands ───────────────────────────────────────────────────────────────

cmd_create() {
    local username="$1"
    local email="$2"
    local role="listener"

    [ -z "$username" ] && usage
    [ -z "$email" ]    && usage

    # Check for --admin flag
    if [ "${3:-}" = "--admin" ]; then
        role="admin"
    fi

    require_db

    # Validate username — alphanumeric and underscores only
    if ! echo "$username" | grep -qE '^[a-zA-Z0-9_]+$'; then
        die "Username may only contain letters, numbers, and underscores."
    fi

    # Check for duplicates
    if user_exists "$username"; then
        die "Username '$username' is already taken."
    fi

    local count
    count=$(query "SELECT COUNT(*) FROM users WHERE email = '$email';")
    if [ "$count" -gt 0 ]; then
        die "Email '$email' is already registered."
    fi

    prompt_password
    local hash
    hash=$(hash_password "$PASSWORD")

    query "INSERT INTO users (username, email, password, role)
           VALUES ('$username', '$email', '$hash', '$role');"

    echo "Created user '$username' with role '$role'."
}

cmd_list() {
    require_db

    echo ""
    printf "%-20s %-30s %-10s %-8s %-12s\n" "USERNAME" "EMAIL" "ROLE" "ACTIVE" "CREATED"
    printf "%-20s %-30s %-10s %-8s %-12s\n" "--------" "-----" "----" "------" "-------"

    query "SELECT username, email, role, active,
                  strftime('%Y-%m-%d', datetime(created_at, 'unixepoch'))
           FROM users
           ORDER BY created_at DESC;" \
    | while IFS='|' read -r uname email role active created; do
        local active_label="yes"
        [ "$active" = "0" ] && active_label="no"
        printf "%-20s %-30s %-10s %-8s %-12s\n" \
            "$uname" "$email" "$role" "$active_label" "$created"
    done

    echo ""
}

cmd_disable() {
    local username="$1"
    [ -z "$username" ] && usage

    require_db

    user_exists "$username" || die "User '$username' not found."

    query "UPDATE users SET active = 0 WHERE username = '$username';"
    query "DELETE FROM sessions
           WHERE user_id = (SELECT id FROM users WHERE username = '$username');"

    echo "User '$username' disabled and sessions revoked."
}

cmd_enable() {
    local username="$1"
    [ -z "$username" ] && usage

    require_db

    user_exists "$username" || die "User '$username' not found."

    query "UPDATE users SET active = 1 WHERE username = '$username';"
    echo "User '$username' enabled."
}

cmd_reset_password() {
    local username="$1"
    [ -z "$username" ] && usage

    require_db

    user_exists "$username" || die "User '$username' not found."

    prompt_password
    local hash
    hash=$(hash_password "$PASSWORD")

    query "UPDATE users SET password = '$hash' WHERE username = '$username';"
    query "DELETE FROM sessions
           WHERE user_id = (SELECT id FROM users WHERE username = '$username');"

    echo "Password updated for '$username'. Existing sessions revoked."
}

# ── Dispatch ──────────────────────────────────────────────────────────────────

SUBCOMMAND="${1:-}"
shift || true

case "$SUBCOMMAND" in
    create)         cmd_create "$@" ;;
    list)           cmd_list ;;
    disable)        cmd_disable "$@" ;;
    enable)         cmd_enable "$@" ;;
    reset-password) cmd_reset_password "$@" ;;
    *)              usage ;;
esac
