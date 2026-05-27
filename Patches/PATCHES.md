# TYPO3 Core Patches for Worker-Mode Compatibility

Submission order for Gerrit. Each patch is self-contained per sysext.

Gerrit framing: "Prevent cross-request state leakage" — never lead with "FrankenPHP".
Reference the existing `getState()`/`updateState()` pattern on PageRenderer/AssetCollector/MetaTagManagerRegistry as precedent.

## Wave 1: Security/Correctness fixes (backportable to TYPO3 14.x)

| # | Patch File                                                   | Sysext         | What                                                                                                         |
|---|--------------------------------------------------------------|----------------|--------------------------------------------------------------------------------------------------------------|
| 1 | `cms-core-form-protection-factory-session-aware-cache.patch` | cms-core       | Include BE_USER session ID in FormProtectionFactory cache key — prevents cross-session CSRF token reuse      |
| 2 | `cms-core-backend-user-auth-scoped-filemount-cache.patch`    | cms-core       | Include user UID in file mount cache key — prevents cross-user file mount data exposure                      |
| 3 | `cms-workspaces-user-scoped-workspace-cache.patch`           | cms-workspaces | Include user UID in workspace availability cache key — prevents cross-user workspace permission leakage      |
| 4 | `cms-backend-uri-builder-reset-generated-cache.patch`        | cms-backend    | Add `resetGeneratedCache()` to UriBuilder — prevents stale CSRF tokens in cached URIs causing redirect loops |

## Wave 2: Public reset APIs (target TYPO3 15.x)

| #  | Patch File                                           | Sysext         | What                                                                                                                                                                 |
|----|------------------------------------------------------|----------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 5  | `cms-core-flash-message-service-reset.patch`         | cms-core       | Add `resetQueues()` to FlashMessageService                                                                                                                           |
| 6  | `cms-core-registry-flush-cache.patch`                | cms-core       | Add `flushInMemoryCache()` to Registry                                                                                                                               |
| 7  | `cms-core-memory-spool-reset.patch`                  | cms-core       | Add `reset()` to MemorySpool                                                                                                                                         |
| 8  | `cms-core-csp-directive-hash-collection-reset.patch` | cms-core       | Add `reset()` to DirectiveHashCollection                                                                                                                             |
| 9  | `cms-backend-doc-header-reset.patch`                 | cms-backend    | Add `resetState()` to DocHeaderComponent, ButtonBar, MenuRegistry                                                                                                    |
| 10 | `cms-backend-system-information-toolbar-reset.patch` | cms-backend    | Add `resetCollectedInformation()` to SystemInformationToolbarItem                                                                                                    |
| 11 | `cms-extbase-persistence-clear-state.patch`          | cms-extbase    | Add `clearState()`/`clearCache()` to Backend, ConfigurationManager, CacheService, ValidatorResolver; expand `PersistenceManager::clearState()` to also reset Backend |
| 12 | `cms-adminpanel-in-memory-log-writer-reset.patch`    | cms-adminpanel | Add `clearLog()` to InMemoryLogWriter                                                                                                                                |
| 13 | `cms-form-slot-reset.patch`                          | cms-form       | Add `clearAllowedInvocations()` to FilePersistenceSlot, `clearFileIdentifiers()` to ResourcePublicationSlot                                                          |
| 14 | `cms-frontend-menu-factory-reset.patch`              | cms-frontend   | Add `getMenuTypeMapping()`/`setMenuTypeMapping()` to MenuContentObjectFactory                                                                                        |

## How to apply

The patches use paths relative to the sysext root (`Classes/...`). In the TYPO3 monorepo the sysexts live under `typo3/sysext/<name>/`, so use `--directory` to prefix the path:

```bash
cd /path/to/typo3-core

# Apply a single patch (example: patch #5)
git apply --directory=typo3/sysext/core Patches/cms-core-flash-message-service-reset.patch

# Apply all patches at once
git apply --directory=typo3/sysext/core       Patches/cms-core-form-protection-factory-session-aware-cache.patch
git apply --directory=typo3/sysext/core       Patches/cms-core-backend-user-auth-scoped-filemount-cache.patch
git apply --directory=typo3/sysext/workspaces Patches/cms-workspaces-user-scoped-workspace-cache.patch
git apply --directory=typo3/sysext/backend    Patches/cms-backend-uri-builder-reset-generated-cache.patch
git apply --directory=typo3/sysext/core       Patches/cms-core-flash-message-service-reset.patch
git apply --directory=typo3/sysext/core       Patches/cms-core-registry-flush-cache.patch
git apply --directory=typo3/sysext/core       Patches/cms-core-memory-spool-reset.patch
git apply --directory=typo3/sysext/core       Patches/cms-core-csp-directive-hash-collection-reset.patch
git apply --directory=typo3/sysext/backend    Patches/cms-backend-doc-header-reset.patch
git apply --directory=typo3/sysext/backend    Patches/cms-backend-system-information-toolbar-reset.patch
git apply --directory=typo3/sysext/extbase    Patches/cms-extbase-persistence-clear-state.patch
git apply --directory=typo3/sysext/adminpanel Patches/cms-adminpanel-in-memory-log-writer-reset.patch
git apply --directory=typo3/sysext/form       Patches/cms-form-slot-reset.patch
git apply --directory=typo3/sysext/frontend   Patches/cms-frontend-menu-factory-reset.patch
```

For Gerrit, create one commit per patch (or group by sysext). Each patch file starts with a ready-to-use commit message.
