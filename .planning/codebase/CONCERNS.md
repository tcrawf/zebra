---
last_mapped_commit: ad45783744f89df5769afa272ec1a706185b21cc
---

# Codebase Concerns

**Analysis Date:** 2026-05-09

## Documentation drift

**Issue:** Repository-level AI rules (`.cursorrules`) describe a **TimeTracker** namespace, `bin/track`, and paths that do not match `composer.json` (`Tcrawf\Zebra`, `bin/zebra`).

**Impact:** Automated assistants may generate wrong namespaces or commands; onboarding confusion.

**Mitigation:** Prefer `composer.json`, `README.md`, and this map; schedule a docs/rules sync when priorities allow.

## External dependency on Zebra API

**Risk:** Remote commands fail without valid credentials/network; behavior depends on Liip backend availability.

**Mitigation:** Local repositories and file storage allow offline workflows where designed; errors surfaced via `ZebraApiException` and command output.

## Secrets handling

**Risk:** API tokens in `.env` or shell environment — standard CLI risk of leakage via logs/screenshots.

**Mitigation:** Follow dotenv precedence documented in `src/bootstrap.php`; avoid logging token values (verify when adding new debug paths).

## Timezone correctness

**Risk:** Incorrect `TZ` shifts all reporting and frame boundaries.

**Mitigation:** `bootstrap.php` validates timezone and defaults with visible stderr warning if unset.

## Dual persistence model

**Risk:** Local vs Zebra repositories can diverge; sync/migration commands are correctness-critical.

**Mitigation:** Dedicated migration services (`FrameMigrationService`, `TimesheetMigrationService`) and sync orchestration — changes here need regression tests.

## PHAR vs dev paths

**Risk:** Different `.env` resolution when running as PHAR (`src/bootstrap.php` branch).

**Mitigation:** Test both modes when changing bootstrap or install paths.

---

*Concerns analysis: 2026-05-09*
