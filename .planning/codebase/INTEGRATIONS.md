---
last_mapped_commit: ad45783744f89df5769afa272ec1a706185b21cc
---

# External Integrations

**Analysis Date:** 2026-05-09

## Zebra API (HTTP)

**Purpose:** Remote synchronization for projects, activities, timesheets, user profile, and related Liip Zebra backend operations.

**Client:**

- Guzzle client created via `src/Client/HttpClientFactory.php` and injected into API-facing services (e.g. `src/User/UserApiService.php`, `src/Project/ProjectApiService.php`, `src/Timesheet/TimesheetApiService.php`, `src/Activity/ZebraActivityRepository.php`, `src/Project/ZebraProjectRepository.php`, `src/Timesheet/ZebraTimesheetRepository.php`)

**Patterns:**

- Repository interfaces split **local** vs **Zebra** implementations (`LocalProjectRepository` / `ZebraProjectRepository`, etc.)
- `src/Client/ZebraApiException.php` — Domain-specific API errors

**Authentication / secrets:**

- Token and base URL expectations are satisfied through environment and config loaded at bootstrap (exact env keys implemented in `UserApiService`, HTTP factory, and config storage — grep `getenv`, `ZEBRA`, `TOKEN` in `src/` when extending)

## Local filesystem state

**Purpose:** Offline-first or cached data: frames, tasks, timesheet files, backups, cache.

**Implementation:**

- `src/FileStorage/AbstractFileStorage.php` — Shared file persistence patterns
- Domain-specific storage: `src/Frame/FrameFileStorage*.php`, `src/Task/TaskFileStorage*.php`, `src/Timesheet/TimesheetFileStorage*.php`, `src/Cache/CacheFileStorage*.php`, `src/Project/LocalProjectFileStorage.php`

**Note:** Not a database; integration surface is the OS filesystem under configured home/project paths.

## Environment file (.env)

**Purpose:** Non-interactive configuration for tokens and `TZ`.

**Loader:** `src/bootstrap.php` — `Dotenv::safeLoad()`, PHAR-aware path selection

## Shell / distribution

**Autocompletion:**

- Symfony Console–based completion helpers under `src/Command/Autocompletion/`

**Install command:**

- `src/Command/InstallCommand.php` — PHAR install path workflow (see README)

## Third-party services (non-Zebra)

**None required** for core CLI operation beyond optional remote Zebra API. No bundled OAuth providers, queues, or SaaS analytics in tree.

---

*Integrations analysis: 2026-05-09*
