#!/usr/bin/env bash

set -euo pipefail

LOG_PREFIX="${LOG_PREFIX:-[typo3-frankenphp]}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="${SCRIPT_DIR}/../Build"
SETTINGS_FILE="${BUILD_DIR}/config/system/settings.php"
ADDITIONAL_PHP="${BUILD_DIR}/config/system/additional.php"
COMPOSER_JSON="${BUILD_DIR}/composer.json"
TYPO3_VERSION_MARKER="${BUILD_DIR}/var/typo3-version"

TYPO3_VERSION="${TYPO3_VERSION:-^14.3}"

# Variable names mirror those documented by `vendor/bin/typo3 setup --help`
# frankenphp:init derives its profile from the context; an unset context
# counts as Production in TYPO3, which would give the sandbox ports 80/443.
export TYPO3_CONTEXT="${TYPO3_CONTEXT:-Development}"
export TYPO3_SETUP_ADMIN_USERNAME="${TYPO3_SETUP_ADMIN_USERNAME:-admin}"
export TYPO3_SETUP_ADMIN_PASSWORD="${TYPO3_SETUP_ADMIN_PASSWORD:-Password.1}"
export TYPO3_SETUP_ADMIN_EMAIL="${TYPO3_SETUP_ADMIN_EMAIL:-typo3@example.com}"
# Non-admin editor used by Tests/e2e/module/storage-permission-isolation.spec.ts
export TYPO3_E2E_EDITOR_USERNAME="${TYPO3_E2E_EDITOR_USERNAME:-editor}"
export TYPO3_E2E_EDITOR_PASSWORD="${TYPO3_E2E_EDITOR_PASSWORD:-Password.1}"
export TYPO3_PROJECT_NAME="${TYPO3_PROJECT_NAME:-typo3-frankenphp}"
export TYPO3_SERVER_TYPE="${TYPO3_SERVER_TYPE:-other}"
export TYPO3_DB_DRIVER="sqlite"

log()  { printf '%s %s\n' "${LOG_PREFIX}" "$*"; }
warn() { printf '%s %s\n' "${LOG_PREFIX}" "$*" >&2; }
die()  { warn "$*"; exit 1; }

check_prerequisites() {
    local missing=()
    local cmd
    for cmd in composer php sqlite3; do
        command -v "${cmd}" >/dev/null 2>&1 || missing+=("${cmd}")
    done
    if [ ${#missing[@]} -gt 0 ]; then
        warn "Missing required tools: ${missing[*]}"
        warn "  macOS:                       brew install ${missing[*]}"
        warn "  Debian/Ubuntu (incl. WSL):   sudo apt-get install ${missing[*]}"
        warn "  Fedora/RHEL:                 sudo dnf install ${missing[*]}"
        die  "Install the listed tools and re-run."
    fi
    command -v frankenphp >/dev/null 2>&1 \
        || warn "Note: 'frankenphp' is not on PATH — install it before 'frankenphp run'."
}

# Create composer.json
generate_composer_json() {
    mkdir -p "$(dirname "${TYPO3_VERSION_MARKER}")"
    local current=""
    [ -f "${TYPO3_VERSION_MARKER}" ] && current="$(cat "${TYPO3_VERSION_MARKER}")"

    if [ -f "${COMPOSER_JSON}" ] && [ "${TYPO3_VERSION}" = "${current}" ]; then
        return
    fi

    if [ -n "${current}" ] && [ "${TYPO3_VERSION}" != "${current}" ]; then
        log "Switching TYPO3 from ${current} to ${TYPO3_VERSION} ..."
        rm -rf "${BUILD_DIR}/vendor" "${BUILD_DIR}/composer.lock" \
               "${BUILD_DIR}/config/system" "${BUILD_DIR}/var/cache" "${BUILD_DIR}/var/log"
        rm -rf "${BUILD_DIR}/public/typo3" "${BUILD_DIR}/public/_assets" \
               "${BUILD_DIR}/public/index.php" 2>/dev/null || true
    else
        log "Generating Build/composer.json (TYPO3 ${TYPO3_VERSION}) ..."
    fi

    # Write composer.json file
    cat > "${COMPOSER_JSON}" <<EOF
{
    "name": "ochorocho/typo3-frankenphp-dev",
    "description": "Development TYPO3 installation for working on ochorocho/frankenphp.",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": "^8.3",
        "ochorocho/frankenphp": "@dev",
        "typo3/cms-backend": "${TYPO3_VERSION}",
        "typo3/cms-fluid-styled-content": "${TYPO3_VERSION}",
        "typo3/cms-core": "${TYPO3_VERSION}",
        "typo3/cms-extbase": "${TYPO3_VERSION}",
        "typo3/cms-extensionmanager": "${TYPO3_VERSION}",
        "typo3/cms-filelist": "${TYPO3_VERSION}",
        "typo3/cms-fluid": "${TYPO3_VERSION}",
        "typo3/cms-frontend": "${TYPO3_VERSION}",
        "typo3/cms-info": "${TYPO3_VERSION}",
        "typo3/cms-install": "${TYPO3_VERSION}",
        "typo3/cms-seo": "${TYPO3_VERSION}",
        "typo3/cms-setup": "${TYPO3_VERSION}",
        "typo3/cms-lowlevel": "${TYPO3_VERSION}",
        "typo3/cms-tstemplate": "${TYPO3_VERSION}",
        "typo3/cms-impexp": "${TYPO3_VERSION}",
        "typo3/cms-beuser": "${TYPO3_VERSION}",
        "typo3/cms-dashboard": "${TYPO3_VERSION}",
        "typo3/cms-adminpanel": "${TYPO3_VERSION}",
        "typo3/cms-belog": "${TYPO3_VERSION}",
        "typo3/cms-felogin": "${TYPO3_VERSION}",
        "typo3/cms-form": "${TYPO3_VERSION}",
        "typo3/cms-indexed-search": "${TYPO3_VERSION}",
        "typo3/cms-linkvalidator": "${TYPO3_VERSION}",
        "typo3/cms-opendocs": "${TYPO3_VERSION}",
        "typo3/cms-reactions": "${TYPO3_VERSION}",
        "typo3/cms-recycler": "${TYPO3_VERSION}",
        "typo3/cms-redirects": "${TYPO3_VERSION}",
        "typo3/cms-scheduler": "${TYPO3_VERSION}",
        "typo3/cms-sys-note": "${TYPO3_VERSION}",
        "typo3/cms-viewpage": "${TYPO3_VERSION}",
        "typo3/cms-webhooks": "${TYPO3_VERSION}",
        "typo3/cms-workspaces": "${TYPO3_VERSION}",
        "typo3/theme-camino": "${TYPO3_VERSION}"
    },
    "repositories": [
        { "type": "path", "url": "../", "options": { "symlink": true } }
    ],
    "config": {
        "allow-plugins": {
            "typo3/cms-composer-installers": true,
            "typo3/class-alias-loader": true,
            "helhum/dotenv-connector": true
        },
        "sort-packages": true,
        "vendor-dir": "vendor"
    },
    "extra": {
      "typo3/cms": { "web-dir": "public" }
    }
}
EOF
    printf '%s\n' "${TYPO3_VERSION}" > "${TYPO3_VERSION_MARKER}"
}

# Install composer packages
ensure_composer_install() {
    if [ -f "${BUILD_DIR}/vendor/autoload.php" ] \
        && [ -x "${BUILD_DIR}/vendor/bin/typo3" ] \
        && [ -x "${BUILD_DIR}/vendor/bin/phpstan" ]; then
        log "composer dependencies already installed, skipping."
        return
    fi
    log "Running 'composer update' in Build/ ..."
    (cd "${BUILD_DIR}" && composer update --no-interaction --no-progress)
}

# Setup TYPO3
run_typo3_setup() {
    [ -f "${SETTINGS_FILE}" ] && return

    log "Running 'typo3 setup' against the sqlite database ..."
    rm -rf "${BUILD_DIR}"/var/sqlite/*
    (cd "${BUILD_DIR}" && vendor/bin/typo3 setup --force --no-interaction)
    # Cache is only cleared right after a fresh setup. Clearing it while a
    # FrankenPHP worker is already running breaks the worker — it has stale
    # paths cached in memory and cannot recreate the dirs on the fly, which
    # causes 500s until the worker is restarted.
    rm -rf "${BUILD_DIR}/var/cache" 2>/dev/null || true
}

# Seed e2e fixtures: a non-admin editor whose only file mount is
# 1:/user_upload/. Idempotent; the storage-permission-isolation spec
# alternates this user with the admin on the same worker.
seed_e2e_fixtures() {
    local db mount group
    db=$(ls "${BUILD_DIR}"/var/sqlite/*.sqlite 2>/dev/null | head -n 1)
    [ -n "${db}" ] || { warn "No sqlite database found, skipping e2e fixtures."; return 0; }

    log "Seeding e2e fixtures (editor user with restricted file mount) ..."
    sqlite3 "${db}" "INSERT INTO sys_filemounts (pid, title, identifier, read_only) \
        SELECT 0, 'e2e user_upload', '1:/user_upload/', 0 \
        WHERE NOT EXISTS (SELECT 1 FROM sys_filemounts WHERE title = 'e2e user_upload');"
    mount=$(sqlite3 "${db}" "SELECT uid FROM sys_filemounts WHERE title = 'e2e user_upload';")
    # workspace_perms=1: live workspace access, otherwise the user lands in
    # workspace -99 and every workspace AJAX call fails.
    sqlite3 "${db}" "INSERT INTO be_groups (pid, title, file_mountpoints, file_permissions, groupMods, workspace_perms) \
        SELECT 0, 'e2e editors', '${mount}', 'readFolder,readFile', 'media,media_management', 1 \
        WHERE NOT EXISTS (SELECT 1 FROM be_groups WHERE title = 'e2e editors');"
    group=$(sqlite3 "${db}" "SELECT uid FROM be_groups WHERE title = 'e2e editors';")
    if [ "$(sqlite3 "${db}" "SELECT count(*) FROM be_users WHERE username = '${TYPO3_E2E_EDITOR_USERNAME}';")" = "0" ]; then
        (cd "${BUILD_DIR}" && vendor/bin/typo3 backend:user:create \
            -u "${TYPO3_E2E_EDITOR_USERNAME}" -p "${TYPO3_E2E_EDITOR_PASSWORD}" \
            -e "editor@example.com" -g "${group}" --no-interaction)
    fi
    # options=3: inherit DB and file mounts from groups. workspace_id=0: the
    # create command defaults to -99, which the editor has no permission for.
    sqlite3 "${db}" "UPDATE be_users SET usergroup = '${group}', options = 3, admin = 0, workspace_id = 0 \
        WHERE username = '${TYPO3_E2E_EDITOR_USERNAME}';"
}

# Configure ImageMagick path
configure_imagemagick() {
    [ -f "${ADDITIONAL_PHP}" ] && return   # respect existing user customization

    local magick_bin=""

    # Explicit override — CI, containers, custom builds.
    if [ -n "${MAGICK_BIN:-}" ]; then
        if [ -x "${MAGICK_BIN}" ] && "${MAGICK_BIN}" -version >/dev/null 2>&1; then
            magick_bin="${MAGICK_BIN}"
        else
            warn "MAGICK_BIN='${MAGICK_BIN}' is not executable or fails -version;"
            warn "  falling back to auto-detection."
        fi
    fi

    # Auto-detect ImageMagick for GFX.processor_path
    if [ -z "${magick_bin}" ]; then
        local candidate
        for candidate in \
                /opt/homebrew/bin/magick \
                /usr/bin/magick \
                /usr/local/bin/magick \
                "$(command -v magick 2>/dev/null || true)" \
                "$(command -v convert 2>/dev/null || true)"; do
            if [ -n "${candidate}" ] && [ -x "${candidate}" ] \
                    && "${candidate}" -version >/dev/null 2>&1; then
                magick_bin="${candidate}"
                break
            fi
        done
    fi

    if [ -z "${magick_bin}" ]; then
        warn "No working ImageMagick found — image processing will be disabled."
        warn "Install one:"
        warn "  macOS:                       brew install imagemagick"
        warn "  Debian/Ubuntu (incl. WSL):   sudo apt-get install imagemagick"
        warn "  Fedora/RHEL:                 sudo dnf install ImageMagick"
        warn "Or set MAGICK_BIN=/absolute/path/to/magick and re-run."
        return
    fi

    local magick_dir
    magick_dir="$(dirname "${magick_bin}")/"
    log "Configuring GFX.processor_path=${magick_dir} + localhost login-rate-limit exclusion"
    cat > "${ADDITIONAL_PHP}" <<EOF
<?php
// Auto-generated by scripts/setup-typo3.sh.

// TYPO3's default GFX.processor_path is /usr/bin/, which is empty on macOS.
\$GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] = '${magick_dir}';

// Exclude localhost from BE/FE login rate limiting in the dev sandbox.
// TYPO3 ships a default of 5 failed BE logins per 15 minutes per IP,
// backed by a persistent cache. In worker mode the rate-limiter state
// survives across worker restarts, so a single k6 load-test run or a
// few wrong attempts during development can lock 127.0.0.1 out —
// every subsequent login then returns the "Your login attempt did
// not succeed" page with no obvious cause (TYPO3 raises a
// RequestRateLimitedException that the auth flow renders as the
// generic credentials-rejected page). Excluding 127.0.0.1 / ::1
// short-circuits the limiter for local + CI use (k6 runs from
// localhost) without disabling it globally.
\$GLOBALS['TYPO3_CONF_VARS']['BE']['loginRateLimitIpExcludeList'] = '127.0.0.1, ::1';
\$GLOBALS['TYPO3_CONF_VARS']['FE']['loginRateLimitIpExcludeList'] = '127.0.0.1, ::1';
EOF
}

# Cleanup processed files
cleanup_processedfile() {
    [ -x "${BUILD_DIR}/vendor/bin/typo3" ] || return 0
    (cd "${BUILD_DIR}" && vendor/bin/typo3 cleanup:localprocessedfiles --all --no-interaction)
}

# Start setup
mkdir -p "${BUILD_DIR}"
check_prerequisites
generate_composer_json
ensure_composer_install
run_typo3_setup
seed_e2e_fixtures
configure_imagemagick
cleanup_processedfile

log "TYPO3 is ready."
log "Login:"
log "  ${TYPO3_SETUP_ADMIN_USERNAME} / ${TYPO3_SETUP_ADMIN_PASSWORD}"
