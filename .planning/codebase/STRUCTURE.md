---
last_mapped_commit: ad45783744f89df5769afa272ec1a706185b21cc
---

# Repository Structure

**Analysis Date:** 2026-05-09

## Top-level layout

| Path | Role |
|------|------|
| `bin/zebra` | CLI entry script |
| `src/` | Application source (`Tcrawf\Zebra\` PSR-4 root) |
| `tests/` | PHPUnit tests mirror `src/` layout |
| `vendor/` | Composer dependencies (generated) |
| `composer.json` / `composer.lock` | Package definition and lockfile |
| `phpunit.xml` | PHPUnit configuration |
| `phpstan.neon` | PHPStan configuration |
| `phpcs.xml` | PHP_CodeSniffer rules |
| `README.md` | User-facing documentation |

## Source (`src/`)

**Namespace:** `Tcrawf\Zebra\` (note: some workspace docs still say `TimeTracker`; Composer is authoritative)

| Area | Directory | Contents |
|------|-----------|----------|
| CLI shell | `Command/` | `Application.php`, per-command classes, `Command/Task/`, `Command/Autocompletion/`, `Command/Trait/` |
| Tracking core | `Track/` | Session orchestration |
| Frames | `Frame/` | Models, repository, file storage, formatting, migration |
| Projects | `Project/` | Local vs API repositories, entities, status |
| Activities | `Activity/` | Repository and Zebra/local implementations |
| Tasks | `Task/` | Task files and repository |
| Timesheets | `Timesheet/` | Local/Zebra repos, sync, API service, file storage |
| User / roles | `User/`, `Role/` | Profile and roles |
| Reporting | `Report/` | Aggregation and reporting service |
| HTTP | `Client/` | Guzzle factory, `ZebraApiException` |
| Config | `Config/` | File-backed configuration |
| Cache | `Cache/` | Cache file storage |
| File IO | `FileStorage/` | Abstract and concrete storage |
| Shared | `EntityKey/`, `Uuid/`, `Timezone/`, `Version.php` |
| Bootstrap | `bootstrap.php` | env, timezone, dotenv |

## Tests (`tests/`)

- PHPUnit bootstrap `tests/bootstrap.php`
- Mirror structure: e.g. `tests/Frame/`, `tests/Command/`, etc.

## Naming conventions

- **Interfaces:** `*Interface` suffix in dedicated files (e.g. `FrameRepositoryInterface.php`)
- **Commands:** `*Command.php` under `Command/` or nested namespaces
- **Exceptions:** `src/Exception/*.php`
- **Strict types:** `declare(strict_types=1);` first statement after `<?php`

---

*Structure analysis: 2026-05-09*
