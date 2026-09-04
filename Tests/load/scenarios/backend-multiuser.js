/**
 * Backend multi-user interleaving — 4 VUs × 3 min, two permission sets.
 *
 * Every cross-user leak found so far only shows up when requests of
 * DIFFERENT users land on the same worker process in alternation:
 * ResourceStorage permissions cached in StorageRepository, the module
 * menu reduced by ModuleProvider for a restricted user, toolbar items
 * built for the first user. The other backend scenarios use a single
 * admin session and can never see any of that.
 *
 * Odd VUs log in as the admin, even VUs as the restricted `editor`
 * fixture (file mount 1:/user_upload/ only, Media module only). Each
 * iteration fetches the backend shell and the file list root and asserts
 * role-specific markers:
 *
 *   admin : System module + clear-cache dropdown in the shell,
 *           storage root listing shows `user_upload`
 *   editor: no System / Admin Tools module, no clear-cache dropdown,
 *           storage root is "Folder not accessible"
 *
 * Any check failure here is a cross-user leak, never a performance issue.
 *
 *   k6 run scenarios/backend-multiuser.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { CONFIG } from '../lib/config.js';
import { loginOncePerVU, moduleUrl, BACKEND_REQUEST_PARAMS } from '../lib/auth.js';
import { backendThresholds } from '../lib/thresholds.js';
import { okStatus, looksLikeBackend, looksLikeModule, noPHPError, noSecurityTokenError } from '../lib/checks.js';

export const options = {
    scenarios: {
        interleaved: {
            executor: 'constant-vus',
            vus: 4,
            duration: '3m',
        },
    },
    thresholds: backendThresholds,
    insecureSkipTLSVerify: true,
};

const SYSTEM_MODULE = 'data-modulemenu-identifier="system"';
const ADMIN_MODULE = 'data-modulemenu-identifier="admin"';
// A main module with a single accessible submodule is promoted to a
// standalone entry: the editor's Media module renders as `media_management`.
const MEDIA_MODULE_RE = /data-modulemenu-identifier="media(_management)?"/;
const CLEAR_CACHE_ACTION = 't3js-toolbar-cache-flush-action';
const BLOCKED_RE = /Folder not accessible|not allowed to access|missing.*permission/i;

const adminShell = {
    'admin sees the System module': (r) => r.body.includes(SYSTEM_MODULE),
    'admin sees the clear-cache dropdown': (r) => r.body.includes(CLEAR_CACHE_ACTION),
};
const editorShell = {
    'editor sees the Media module': (r) => MEDIA_MODULE_RE.test(r.body),
    'editor does not see the System module': (r) => !r.body.includes(SYSTEM_MODULE),
    'editor does not see Admin Tools': (r) => !r.body.includes(ADMIN_MODULE),
    'editor does not get the clear-cache dropdown': (r) => !r.body.includes(CLEAR_CACHE_ACTION),
};
const adminRoot = {
    'admin lists the storage root': (r) => r.body.includes('user_upload') && !BLOCKED_RE.test(r.body),
};
const editorRoot = {
    'editor is blocked outside the file mount': (r) => BLOCKED_RE.test(r.body) && !r.body.includes('_temp_'),
};

export default function () {
    const isAdmin = __VU % 2 === 1;
    const ok = isAdmin
        ? loginOncePerVU()
        : loginOncePerVU(CONFIG.editorUser, CONFIG.editorPass);
    if (!ok) {
        return;
    }

    const main = http.get(`${CONFIG.baseUrl}/typo3/main`, BACKEND_REQUEST_PARAMS);
    check(main, { ...okStatus, ...looksLikeBackend, ...noPHPError, ...noSecurityTokenError, ...(isAdmin ? adminShell : editorShell) });

    const rootUrl = moduleUrl(main.body, '/typo3/module/file/list', 'id=' + encodeURIComponent('1:/'));
    if (rootUrl !== null) {
        const root = http.get(rootUrl, BACKEND_REQUEST_PARAMS);
        check(root, { ...okStatus, ...looksLikeModule, ...noPHPError, ...noSecurityTokenError, ...(isAdmin ? adminRoot : editorRoot) });
    } else {
        check(main, { 'file list module link present in the menu': () => false });
    }
    sleep(0.3);
}
