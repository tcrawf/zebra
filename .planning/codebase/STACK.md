---
last_mapped_commit: ad45783744f89df5769afa272ec1a706185b21cc
---

# Technology Stack

**Analysis Date:** 2026-05-09

## Languages

**Primary:**

- PHP 8.4+ — Application code under `src/` (`composer.json` requires `php:^8.4`, `declare(strict_types=1)` throughout)

**Secondary:**

- INI / XML — Tooling configs (`phpunit.xml`, `phpstan.neon`, `phpcs.xml`)

## Runtime

**Environment:**

- PHP CLI — Primary execution via `bin/zebra` (shebang `#!/usr/bin/env php`)
- Optional PHAR — Built with Humbug Box (`composer.json` scripts `build`, `build-phar`)

**Package Manager:**

- Composer — Dependency and autoload management
- Lockfile: `composer.lock` expected after `composer install`

## Frameworks

**Core:**

- Symfony Console `^7.0` — CLI application shell (`src/Command/Application.php` extends `Symfony\Component\Console\Application`)

**Supporting libraries:**

- Guzzle HTTP `^7.10` — HTTP client for remote Zebra API (`src/Client/`)
- nesbot/carbon `^2.0` — Date/time handling
- vlucas/phpdotenv `^5.6` — `.env` loading (`src/bootstrap.php`)

**Testing:**

- PHPUnit `^11.0` — Unit/integration tests (`tests/`, `phpunit.xml`)
- mikey179/vfsstream — Virtual filesystem for tests

**Quality:**

- squizlabs/php_codesniffer `^4` + slevomat/coding-standard — Style (`phpcs.xml`)
- phpstan/phpstan `^2.0` — Static analysis (`phpstan.neon`)

**Build:**

- humbug/box `^4.6` — PHAR compilation

## Key Dependencies

**Critical:**

- `symfony/console` — Command routing, I/O, Symfony Style
- `guzzlehttp/guzzle` — All outbound HTTP to Zebra services
- `nesbot/carbon` — Normalized date handling for frames, timesheets, reports
- `vlucas/phpdotenv` — Environment-driven config (token, timezone)

**Infrastructure:**

- `ext-json`, `ext-date` (implicit) — Serialization and time
- PCOV or Xdebug (optional dev) — Coverage (`composer.json` suggests `ext-pcov`)

## Configuration

**Environment:**

- `.env` at project root (or cwd when running as PHAR) via `Dotenv::createUnsafeImmutable` in `src/bootstrap.php`
- Notable variables: `TZ` (timezone; defaults to `Europe/Zurich` with stderr warning), API token patterns used by Zebra client code (see INTEGRATIONS.md)

**Project config:**

- File-backed application config via `src/Config/ConfigFileStorage.php` (paths relative to user home / app conventions)

**Tooling:**

- `phpunit.xml` — Test bootstrap `tests/bootstrap.php`, coverage includes `src/` excluding `src/bootstrap.php`
- `phpstan.neon` — PHPStan level and paths
- `phpcs.xml` — PSR-12-oriented ruleset with Slevomat additions

## Platform Requirements

**Development:**

- PHP 8.4+, Composer
- Linux/macOS/Windows with PHP CLI (README documents install paths)

**Production / distribution:**

- Packagist package `tcrawf/zebra`, alpha stability
- Global install optional via PHAR to `~/.local/bin/zebra` (per README)

---

*Stack analysis: 2026-05-09*
*Update after major dependency changes*
