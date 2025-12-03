# Code Style

This project uses PHP CodeSniffer (phpcs) with PSR-12 standard for code style checking.

## Setup

The required dependencies are already installed via Composer:

```bash
composer install
```

## Usage

### Check code style

```bash
# Using Composer scripts
composer run phpcs-check

# Using DDEV (recommended for containerized development)
ddev exec phpcs
```

### Fix code style issues automatically

```bash
# Using Composer scripts
composer run phpcbf-fix

# Using DDEV (recommended for containerized development)
ddev exec phpcbf
```

### Run phpcs directly

```bash
# Using Composer scripts
composer run phpcs

# Using DDEV (recommended for containerized development)
ddev exec phpcs [options]
```

### Run phpcbf directly

```bash
# Using Composer scripts
composer run phpcbf

# Using DDEV (recommended for containerized development)
ddev exec phpcbf [options]
```

### Combined check and fix (DDEV only)

```bash
ddev cs
```

## Configuration

The PHP CodeSniffer configuration is defined in `phpcs.xml` and includes:

- PSR-12 standard
- Checks all files in the `src/` and `tests/` directories
- Excludes the `vendor/` and `build/` directories
- Excludes test files in `src/` directory (e.g., `src/test.php`)
- Shows colors and sniff codes in output

## PSR-12 Standard

This project follows the PSR-12 Extended Coding Style Guide, which extends PSR-1 and includes:

- Proper class naming conventions
- Method and property visibility
- Indentation and spacing
- Line length limits
- And many other style guidelines

For more information about PSR-12, visit: https://www.php-fig.org/psr/psr-12/
