---
last_mapped_commit: ad45783744f89df5769afa272ec1a706185b21cc
---

# Architecture

**Analysis Date:** 2026-05-09

## Pattern Overview

**Overall:** Layered **PHP CLI** application with **Symfony Console** front-end, **repository-style** domain boundaries, and **dual backends** (local file storage vs remote Zebra HTTP API).

**Key Characteristics:**

- Single-process, synchronous CLI commands
- Constructor-wired composition root in `Tcrawf\Zebra\Command\Application`
- Interface-first repositories and services for testability
- File-based persistence for local entities; HTTP for remote Zebra entities

## Layers

**CLI / Command layer:**

- Purpose: Parse argv, format output, orchestrate user workflows
- Contains: Symfony `Command` subclasses under `src/Command/` (including `Task/` sub-namespace), traits for shared CLI behavior (`src/Command/Trait/`)
- Depends on: Domain services (`Track`, `ReportService`), repositories, migration services
- Used by: `bin/zebra` → `Application::run()`

**Domain / application layer:**

- Purpose: Time tracking orchestration and reporting rules
- Contains: `src/Track/Track.php`, `src/Report/ReportService.php`, migration services (`FrameMigrationService`, `TimesheetMigrationService`), sync orchestration (`TimesheetSyncService`)
- Depends on: Repositories, storage factories, API services

**Persistence / integration layer:**

- Purpose: Abstract storage and remote API access
- Contains: `src/FileStorage/`, `src/*Repository*.php`, `src/*ApiService*.php`, `src/Client/`
- Depends on: Guzzle, filesystem, config

**Infrastructure:**

- Purpose: Bootstrap, timezone normalization, HTTP client factory
- Contains: `src/bootstrap.php`, `src/Client/HttpClientFactory.php`, `src/Config/`

## Data Flow

**Typical tracking command (e.g. start/stop):**

1. User invokes `bin/zebra <command>` (`bin/zebra`)
2. Composer autoload + `src/bootstrap.php` set timezone and environment
3. `Application` constructs repositories/services and registers commands (`src/Command/Application.php`)
4. Symfony Console dispatches to the matched `Command`
5. Command uses `Track` or repositories to read/write **frames** via `FrameRepository` / file storage
6. Output via Symfony Style / stdout

**Remote sync flow:**

1. Command or service calls Zebra-backed repository or `*ApiService`
2. Guzzle performs HTTP request
3. Responses mapped to domain objects; optional caching via `CacheFileStorageFactory`

**State management:**

- Long-lived state on disk (frames, tasks, timesheets JSON or analogous files under user data dirs)
- In-memory only for single command execution unless caching layers hold ephemeral data

## Key Abstractions

**Repository interfaces:**

- Examples: `src/Frame/FrameRepositoryInterface.php`, `src/Project/ProjectRepositoryReadInterface.php`, `src/User/UserRepositoryInterface.php`
- Pattern: Read/write split where needed; local vs Zebra implementations

**Factories:**

- `FrameFileStorageFactory`, `TaskFileStorageFactory`, `TimesheetFileStorageFactory`, `CacheFileStorageFactory` — Construct file-backed storage with consistent paths

**Track:**

- `src/Track/Track.php` / `TrackInterface.php` — Central coordinator for frame lifecycle (start/stop/cancel/log semantics)

**Entity keys:**

- `src/EntityKey/` — Stable identification across local/Zebra sources

## Entry Points

**CLI:**

- `bin/zebra` — Resolves `vendor/autoload.php`, instantiates `Tcrawf\Zebra\Command\Application`, calls `run()`
- `src/bootstrap.php` — Loaded via Composer `autoload.files`

## Error Handling

**Strategy:** Typed domain exceptions (e.g. `src/Exception/FrameAlreadyStartedException.php`, `NoFrameStartedException.php`, `InvalidTimeException.php`) plus `ZebraApiException` for HTTP failures

**Patterns:**

- Fail fast on invalid user input in commands (traits like `ArgumentParsingTrait`, `DateRangeParserTrait`)
- Symfony Console exit codes via command `execute()` return values

## Cross-Cutting Concerns

**Timezones:**

- `src/bootstrap.php` enforces valid `TZ` and sets `date_default_timezone_set`
- `src/Timezone/TimezoneFormatter.php` — Display consistency

**Validation:**

- Command-layer parsing traits; domain validation in services/entities as needed

**Caching:**

- `src/Cache/` — File cache for API responses (factory-driven)

---

*Architecture analysis: 2026-05-09*
*Update when major patterns change*
