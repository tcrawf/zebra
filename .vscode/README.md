# VS Code / Cursor PHP CodeSniffer Setup

This workspace is configured to automatically fix PHP CodeSniffer issues on save.

## Required Extensions

Install one of these extensions in VS Code/Cursor:

### Option 1: PHP Sniffer & Beautifier (Recommended)

- **Extension ID**: `valeryanm.vscode-phpsab`
- **Install**: Open Command Palette (`Ctrl+Shift+P` / `Cmd+Shift+P`) → "Extensions: Install Extensions" → Search for "PHP Sniffer & Beautifier"

This extension will:

- Show PHP CodeSniffer errors and warnings in real-time
- Automatically fix issues on save using `phpcbf`
- Use the project's `phpcs.xml` configuration

### Option 2: PHP CodeSniffer (Alternative)

- **Extension ID**: `IoannisKappas.phpcs`
- Provides linting but may require manual formatting

## Configuration

The workspace settings (`.vscode/settings.json`) are already configured to:

- Use `phpcs.xml` as the coding standard
- Run `phpcbf` automatically on save for PHP files
- Show warnings and errors in the Problems panel

## Manual Tasks

You can also run these tasks manually:

- **PHP: Fix Code Style (phpcbf)**: Fixes all code style issues
- **PHP: Check Code Style (phpcs)**: Checks code style without fixing

To run a task:

1. Press `Ctrl+Shift+P` / `Cmd+Shift+P`
2. Type "Tasks: Run Task"
3. Select the desired task

## Troubleshooting

If auto-fix on save doesn't work:

1. Ensure the extension is installed and enabled
2. Check that `vendor/bin/phpcbf` exists (run `composer install` if needed)
3. Verify `.vscode/settings.json` is properly configured
4. Try reloading the window: `Ctrl+Shift+P` → "Developer: Reload Window"
