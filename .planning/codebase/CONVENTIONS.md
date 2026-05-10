---
last_mapped_commit: ad45783744f89df5769afa272ec1a706185b21cc
---

# Coding Conventions

**Analysis Date:** 2026-05-09

## Naming Patterns

**Files:**

- PHP class files: **PascalCase** matching class name (e.g. `FrameRepository.php`)
- One primary class/interface per file under PSR-4 paths

**Functions / methods:**

- **camelCase** for methods (`startFrame`, `parseDateRange`)

**Variables:**

- **camelCase** for locals and properties
- Constructor dependency injection: `private readonly` properties common in newer code (`Application.php`)

**Types:**

- **Interfaces:** `*Interface` suffix (`UserRepositoryInterface`)
- **Enums / value objects:** PascalCase class names under domain folders

## Code Style

**Formatting:**

- **PSR-12** enforced via `phpcs.xml` (`<rule ref="PSR12"/>`)
- **Strict types:** `declare(strict_types=1);` immediately after opening tag in PHP files

**Linting / analysis:**

| Tool | Config | Composer script |
|------|--------|-----------------|
| PHP_CodeSniffer | `phpcs.xml` | `composer run phpcs-check`, `composer run phpcbf-fix` |
| PHPStan | `phpstan.neon` | `composer run phpstan` |
| Slevomat | Unused `use` sniff in `phpcs.xml` | Same as PHPCS |

## Import Organization

**Order (typical in `src/`):**

1. `declare(strict_types=1);`
2. Blank line
3. `use` statements for vendor / Symfony / Guzzle / Carbon
4. Blank line
5. `use` statements for `Tcrawf\Zebra\...` (often grouped by domain)

Alphabetical grouping within blocks is common but not strictly verified without automated fixer for imports beyond unused-use removal.

## Error Handling

**Patterns:**

- Domain-specific exceptions under `src/Exception/` and `src/Client/ZebraApiException.php`
- Commands propagate failures via Symfony Console exit codes

**Validation:**

- Traits under `src/Command/Trait/` centralize parsing (`ArgumentParsingTrait`, `DateRangeParserTrait`, etc.)

## Documentation

**PHPDoc:**

- Present on many public classes/methods; workspace rule expects PHPDoc for public API

## Project docs drift

**Workspace `.cursorrules`** references namespace `Tcrawf\TimeTracker\` and binary `bin/track`; **Composer** uses `Tcrawf\Zebra` and `bin/zebra`. Treat Composer/README as source of truth until docs are aligned.

---

*Conventions analysis: 2026-05-09*
