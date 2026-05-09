---
last_mapped_commit: ad45783744f89df5769afa272ec1a706185b21cc
---

# Testing Patterns

**Analysis Date:** 2026-05-09

## Test Framework

**Runner:**

- PHPUnit **11.x** (`composer.json` require-dev, `phpunit.xml` schema 11.0)

**Config:**

- `phpunit.xml` — Bootstrap `tests/bootstrap.php`, colors, strict output/coverage metadata
- Cache directory: `.phpunit.cache`

**Assertion library:**

- PHPUnit built-in assertions (`assertSame`, `assertInstanceOf`, etc.)

## Run Commands

```bash
composer run test                 # phpunit — full suite
composer run test-coverage      # Requires pcov or xdebug — HTML + clover + text
composer run test-coverage-text # Text-only coverage
vendor/bin/phpunit tests/Some/Test.php  # Single file (example)
```

## Test file organization

**Location:**

- `tests/` tree mirrors `src/` (`Tcrawf\Zebra\Tests\` PSR-4 in `composer.json`)

**Naming:**

- Test classes end with `Test` (e.g. `FrameTest.php`)

**Bootstrap:**

- `tests/bootstrap.php` — Shared setup for suite

## Patterns

**Isolation:**

- `vfsstream` available for virtual filesystem scenarios (`mikey179/vfsstream`)

**Coverage:**

- Source includes `src/`, excludes `vendor`, `tests`, and explicitly `src/bootstrap.php` from coverage scope in `phpunit.xml`

**Strictness:**

- `failOnRisky="true"`, `failOnWarning="true"`, strict coverage metadata and output checks enabled

## Quality gates (CI / local)

Typical flow before merge (from `.cursorrules` / project norms):

1. `composer run phpcs-check`
2. `composer run phpstan`
3. `composer run test`

---

*Testing analysis: 2026-05-09*
