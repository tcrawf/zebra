# Zebra - Time Tracking CLI Tool

A powerful command-line time tracking application built with PHP 8.4.

Zebra helps you track time spent on activities, which belong to projects. It supports both local and remote (Zebra API) project management.

## About

This library is based on [Watson](https://github.com/jazzband/Watson), a wonderful Python-based CLI time tracking tool.
Zebra written as a PHP port/reimplementation in order to provide additional zebra specific features.
Pull requests are welcome!

**Note**: Zebra is intended for use by employees of Liip, who have access to Zebra, Liip's proprietary management system.

## Features

- **Time Tracking**: Start, stop, and manage time tracking sessions (frames)
- **Project Management**: Support for both local and Zebra API projects
- **Activity Tracking**: Track time against activities (activities belong to projects)
- **Timesheet Management**: Create, edit, list, sync, and manage timesheet entries
- **Reporting**: Generate reports and aggregate time spent by project and day
- **Frame Management**: Add, edit, remove, and view time tracking frames
- **Backup & Restore**: Automatic daily backups and manual backup/restore functionality
- **User Management**: Initialize and manage user information and roles
- **Configuration**: Manage application settings via CLI or config file
- **Autocompletion**: Shell autocompletion support for commands
- **PHAR Support**: Can be built as a standalone PHAR executable

## Requirements

- PHP 8.4 or higher
- Composer (for development)
- Zebra API token (for remote project synchronization)
- Valid timezone identifier

## Installation

### Via Composer (Packagist)

**Note**: This package is currently in alpha. To install via Composer, you must specify the stability level:

```bash
composer create-project tcrawf/zebra --stability=alpha
```

Or specify a specific version:

```bash
composer create-project tcrawf/zebra:1.0.6-alpha
```

### From Source

1. Clone the repository:

```bash
git clone <repository-url>
cd zebra
```

2. Install dependencies:

```bash
composer install
```

3. Install the PHAR to your system (optional, but highly recommended):

```bash
./bin/zebra install
```

This will build the PHAR (if needed) and install the `zebra` executable to:

- `~/.local/bin/zebra` on Unix/Linux/macOS
- `~/bin/zebra` on Windows

Make sure the install directory is in your PATH.

### Configuration

Zebra requires three environment variables:

1. **ZEBRA_TOKEN**: Your Zebra API authentication token
2. **ZEBRA_BASE_URI**: The base URI for the Zebra API (e.g., `https://api.example.com`)
3. **TZ**: Your timezone identifier (e.g., `Europe/Zurich`, `America/New_York`)

You can set these in one of two ways:

#### Option 1: Environment Variables

```bash
export ZEBRA_TOKEN=your_token_here
export ZEBRA_BASE_URI=https://api.example.com
export TZ=Europe/Zurich
```

#### Option 2: .env File

Create a `.env` file in the project root:

```env
ZEBRA_TOKEN=your_token_here
ZEBRA_BASE_URI=https://api.example.com
TZ=Europe/Zurich
```

**Note**: Environment variables take precedence over `.env` file values.

For a list of valid timezone identifiers, see: https://en.wikipedia.org/wiki/List_of_tz_database_time_zones

## Usage

Zebra provides a command-line interface for tracking time. Activities belong to projects, and you track time against activities.

### Available Commands

#### Time Tracking

- **start** - Start tracking time for an activity
- **stop** - Stop the currently running time tracking session
- **status** - Display the current tracking status
- **cancel** - Cancel the last start command (time will not be recorded)
- **restart** - Restart tracking time for a previously stopped activity
- **add** - Add time to an activity that was not tracked live

#### Frame Management

- **frames** - Display all frame IDs
- **edit** - Edit an existing frame
- **remove** - Remove a frame

#### Project & Activity Management

- **projects** - Display all available projects or add/edit a local project
- **activities** - Display all available activities or add/edit a local activity
- **delete-project** - Delete a local project
- **delete-activity** - Delete a local activity

#### Timesheet Management

- **timesheet:create** - Create a new timesheet entry
- **timesheet:from-frames** - Create timesheet entries from frames
- **timesheet:edit** - Edit an existing timesheet entry
- **timesheet:list** - List timesheet entries
- **timesheet:push** - Push local timesheets to Zebra API
- **timesheet:pull** - Pull timesheets from Zebra API
- **timesheet:delete** - Delete a timesheet entry
- **timesheet:merge** - Merge multiple timesheet entries

#### Reporting

- **report** - Display a report of time spent on each project
- **aggregate** - Display a report aggregated by day
- **log** - Display each recorded session during a given timespan

#### User & Configuration

- **user** - Display current user information or initialize a new user
- **roles** - Display available roles for the current user
- **config** - Get and set configuration options
- **refresh** - Refresh all data (user data, projects, and activities) from Zebra API

#### Backup & Restore

- **backup** - Backup frames, timesheets, and local projects (automatic daily backups enabled)
- **restore** - Restore from a backup
- **delete-backup** - Delete a backup

#### Installation

- **install** - Build and install the zebra PHAR to a platform-appropriate directory

### Getting Help

For detailed usage information and available options for any command, use:

```bash
zebra <command> --help
```

For example:

```bash
zebra start --help
zebra add --help
zebra report --help
```

### Example Output

Here's an example of time tracking entries displayed by Zebra:

```
+----------+---------------+----------+---------------------+--------------------------------------------------------------------------+
| UUID     | Time frame    | Duration | Activity            | Description                                                              |
+----------+---------------+----------+---------------------+--------------------------------------------------------------------------+
| 673669d4 | 08:32 - 08:40 | 7m       | proj_minor          | PROJ-560/PROJ-602: Kicked off the day by power-reviewing returned        |
|          |               |          |                     | tickets to keep momentum high.                                           |
| 80f79d41 | 08:40 - 08:43 | 3m       | proj_minor          | PROJ-560: Tackled a login glitch for Alex — quick diagnosis, quick fix.  |
| 227fbdb2 | 08:43 - 08:48 | 4m       | proj_support        | Helped Sam jump-start ddev after a few hiccups — rapid-fire support.     |
| 666b2daa | 08:48 - 09:00 | 12m      | administration      | Strategic sync with Lara about a recent incident; captured Kai's sharp   |
|          |               |          |                     | insights on development direction.                                       |
| e36be0ed | 09:00 - 09:40 | 40m      | proj_minor          | PROJ-560: Continued hunting down Alex's login issue — deeper dives,      |
|          |               |          |                     | broader checks.                                                          |
| 476357de | 09:52 - 10:36 | 43m      | proj_minor          | PROJ-560: Investigated a stubborn login failure on dev environment.      |
|          |               |          |                     | Reproduced sessions, probed configs, and narrowed down server-side       |
|          |               |          |                     | suspicions.                                                              |
| b47d080e | 10:36 - 11:27 | 51m      | proj_minor          | PROJ-560: Reconstructed the issue locally using Apache. Identified a     |
|          |               |          |                     | likely conflict with the shield module. Tested alternative approaches    |
|          |               |          |                     | and authentication setups.                                               |
| a09ac150 | 11:27 - 11:37 | 10m      | proj_minor          | PROJ-602: Integrated ticket feedback — polishing edges for smooth UX.    |
| 669a4afb | 11:37 - 12:14 | 36m      | proj_minor          | PROJ-560: Shield disabled, mystery persists. Diving deeper into root     |
|          |               |          |                     | causes.                                                                  |
| 868b7128 | 12:48 - 12:57 | 8m       | proj_minor          | PROJ-560: Experimented with a Symfony component downgrade to rule out    |
|          |               |          |                     | regression issues.                                                       |
| d60d4d90 | 12:57 - 13:27 | 29m      | administration       | High-energy sync with D. Rivera on modern developer workflows and AI's  |
|          |               |          |                     | role in shaping them.                                                    |
| 502f00dd | 13:27 - 13:47 | 20m      | edu                 | Research dive — exploring emerging patterns and tooling.                 |
| e590e4d2 | 13:47 - 14:23 | 35m      | proj_minor          | PROJ-602: Delivered feedback integration with a sharper, cleaner         |
|          |               |          |                     | implementation.                                                          |
| 033c5331 | 14:23 - 15:00 | 37m      | proj_minor          | PROJ-560: Tracked login failure to a custom redirect. Untangled session  |
|          |               |          |                     | flow so users regain seamless access.                                    |
| 4eaa1473 | 15:17 - 17:22 | 2h 5m    | proj_minor          | PROJ-601: Engineered a robust filename override solution — aligning UX,  |
|          |               |          |                     | backend rules, and editor behaviour.                                     |
| 6322b1fe | 17:22 - 17:32 | 9m       | proj_minor          | PROJ-601: Finalised refinements for the filename override mechanism.     |
| 69aadede | 17:32 - 17:40 | 7m       | administration      | Logged and wrapped up the day's achievements — timesheet complete.       |
+----------+---------------+----------+---------------------+--------------------------------------------------------------------------+
```

### Configuration

Configuration is stored in `~/.config/zebra/config.json`. Use the `config` command to manage settings:

```bash
zebra config --help
```

## Development

### Project Structure

```
zebra/
├── bin/                 # CLI entry points
├── src/                 # Source code
│   ├── Activity/        # Activity domain
│   ├── Cache/           # Cache management
│   ├── Client/          # HTTP client and API exceptions
│   ├── Command/         # CLI commands
│   ├── Config/          # Configuration management
│   ├── EntityKey/       # Entity key handling
│   ├── Exception/       # Custom exceptions
│   ├── FileStorage/     # File storage abstractions
│   ├── Frame/           # Frame domain
│   ├── Project/         # Project domain
│   ├── Report/          # Reporting services
│   ├── Role/            # Role domain
│   ├── Timesheet/       # Timesheet domain
│   ├── Timezone/        # Timezone formatting
│   ├── Track/           # Time tracking logic
│   ├── User/            # User domain
│   └── Uuid/            # UUID handling
├── tests/               # Test suite
├── build/               # Built PHAR files
└── vendor/              # Composer dependencies
```

### Architecture

Zebra follows a clean architecture pattern with:

- **Repository Pattern**: Data access abstraction
- **Dependency Injection**: Constructor injection with readonly properties
- **Factory Pattern**: Object creation factories
- **File Storage**: File-based persistence extending `AbstractFileStorage`
- **Interface-First Design**: Interfaces defined before implementations

### Code Style

This project follows **PSR-12 Extended Coding Style Guide**:

- PHP 8.4+ with strict types (`declare(strict_types=1);`)
- PSR-12 formatting standards
- All files must have `declare(strict_types=1);` at the top
- One newline at the end of files (no more, no less)

#### Check Code Style

```bash
composer run phpcs-check
```

#### Auto-fix Code Style Issues

```bash
composer run phpcbf-fix
```

### Static Analysis

Run PHPStan for static analysis:

```bash
composer run phpstan
```

Generate a baseline (for first-time setup):

```bash
composer run phpstan-baseline
```

### Testing

Run the test suite:

```bash
composer run test
```

Generate test coverage report:

```bash
composer run test-coverage
```

Coverage report will be generated in `coverage/` directory.

**Note:** Code coverage requires a PHP coverage driver to be installed. PHPUnit supports:

- **PCOV** (recommended for performance)
- **Xdebug**
- **phpdbg**

Install PCOV (recommended):

```bash
# Debian/Ubuntu
sudo apt-get install php-pcov

# Or via PECL
pecl install pcov
```

Install Xdebug:

```bash
# Debian/Ubuntu
sudo apt-get install php-xdebug

# Or via PECL
pecl install xdebug
```

After installation, restart your PHP CLI or ensure the extension is enabled in your PHP configuration.

### Development Workflow

1. **Make Changes**: Edit source code following PSR-12 standards
2. **Check Style**: Run `composer run phpcs-check`
3. **Fix Style**: Run `composer run phpcbf-fix` if needed
4. **Run Tests**: Run `composer run test` to ensure all tests pass
5. **Test Coverage**: Run `composer run test-coverage` to generate coverage reports
6. **Static Analysis**: Run `composer run phpstan` to catch type errors

## Dependencies

### Runtime Dependencies

- **nesbot/carbon**: Date/time handling (normalized to UTC)
- **guzzlehttp/guzzle**: HTTP client for API communication
- **vlucas/phpdotenv**: Environment variable management
- **symfony/console**: CLI framework

### Development Dependencies

- **phpunit/phpunit**: Testing framework
- **phpstan/phpstan**: Static analysis
- **squizlabs/php_codesniffer**: Code style checking
- **slevomat/coding-standard**: Additional code style rules
- **humbug/box**: PHAR building tool
- **mikey179/vfsstream**: Virtual filesystem for testing

## Configuration Storage

- **Application Config**: `~/.config/zebra/config.json`
- **Cache**: `~/.cache/zebra/` (managed automatically)
- **Frame Storage**: `~/.zebra/` (managed automatically)
- **Timesheet Storage**: `~/.zebra/timesheets.json` (managed automatically)
- **Backups**: `~/.zebra/backups/` (managed automatically)

## Timezone Handling

All timestamps are normalized to UTC internally. The application uses the `TZ` environment variable to display times in your local timezone. Make sure to set `TZ` to a valid timezone identifier.

## API Integration

Zebra integrates with the Zebra API for:

- User authentication and information
- Project synchronization
- Activity management
- Timesheet synchronization (push/pull)

API requests are authenticated using the `ZEBRA_TOKEN` environment variable and use the `ZEBRA_BASE_URI` environment variable to determine the API endpoint. Use the `refresh` command to synchronize user data, projects, and activities from the API.

## Troubleshooting

### Xdebug Warning

If you see a warning about Xdebug being enabled, you can:

- Disable Xdebug: `php -d xdebug.mode=off bin/zebra`
- Suppress the warning: `ZEBRA_SILENT_XDEBUG_WARNING=1 bin/zebra`

### Missing Environment Variables

If you get errors about missing `ZEBRA_TOKEN`, `ZEBRA_BASE_URI`, or `TZ`:

1. Check that your `.env` file exists in the project root
2. Verify the variables are set correctly
3. Ensure environment variables are exported in your shell

**Note**: The application will start without `ZEBRA_TOKEN` or `ZEBRA_BASE_URI`, but will warn when these are needed for API requests. This is a security precaution to prevent accidental API calls.

### PHAR Execution Issues

If running from PHAR:

- Ensure the PHAR file has execute permissions: `chmod +x build/zebra`
- The `.env` file should be in the current working directory when running the PHAR

### Backup Issues

- Backups are automatically created daily when you run any command
- Manual backups can be created with `zebra backup`
- Use `zebra restore` to restore from a backup
- Backup files are stored in `~/.zebra/backups/`

## License

This project is licensed under the GNU General Public License v3.0 (GPL-3.0). See the [LICENSE](LICENSE) file for details.

## Author

Trent Crawford (trent.crawford+zebra@liip.ch)
