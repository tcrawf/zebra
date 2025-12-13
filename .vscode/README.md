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

### Error: "Fixer disabled for this workspace or PHPCBF was not found"

If you see this error message:

1. **Verify phpcbf exists**:

   ```bash
   ls -la vendor/bin/phpcbf
   ```

   If missing, run: `composer install`

2. **Check extension output** (most important):

   - View → Output → Select "PHP Sniffer & Beautifier" from dropdown
   - This will show the exact error - usually it can't resolve `${workspaceFolder}` or can't find PHP
   - Look for messages like "Cannot find phpcbf" or path resolution errors

3. **Reload the window**:

   - `Ctrl+Shift+P` → "Developer: Reload Window"
   - The extension needs to reload to pick up configuration changes

4. **Verify extension is installed and enabled**:

   - Open Extensions (`Ctrl+Shift+X`)
   - Search for "PHP Sniffer & Beautifier" by Valeryanm
   - Ensure it's installed and enabled (not disabled)

5. **Check PHP path**:

   - The settings include `phpsab.executablePathPHP` pointing to `/usr/bin/php`
   - If your PHP is in a different location, update `.vscode/settings.json`
   - You can find your PHP path with: `which php`

6. **If the extension still can't find phpcbf**:

   - The extension might have issues resolving `${workspaceFolder}` variables
   - Check the Output panel for the exact error message
   - You may need to restart VS Code/Cursor completely
   - As a last resort, you can manually format files using the task: `Ctrl+Shift+P` → "Tasks: Run Task" → "PHP: Fix Code Style (phpcbf)"

### PHP CodeSniffer errors not showing in IDE

If phpcs errors are not detected in the IDE (only in terminal):

1. **Check the Output panel (MOST IMPORTANT)**:

   - View → Output
   - Select "PHP CodeSniffer" from the dropdown
   - This will show the exact error - usually it can't resolve the path or can't find phpcs
   - Look for messages like "Cannot find phpcs" or path resolution errors
   - The extension might show what path it's trying to use

2. **Install the PHP CodeSniffer extension**:

   - Open Extensions (`Ctrl+Shift+X` / `Cmd+Shift+X`)
   - Search for "PHP CodeSniffer" by Ioannis Kappas
   - Install and enable it

3. **Verify extension is enabled**:

   - Check the Extensions panel - the extension should show as "Enabled"
   - Look for phpcs in the status bar (bottom right) - it should show "phpcs" when active

4. **Check executable path**:

   - Run: `ls -la vendor/bin/phpcs` (should exist)
   - If missing: `composer install`

5. **Try absolute paths (if relative paths don't work)**:

   - The IoannisKappas.phpcs extension may have issues with relative paths
   - If the Output panel shows path resolution errors, you may need to use absolute paths temporarily:
     ```json
     "phpcs.executablePath": "/full/path/to/project/vendor/bin/phpcs",
     "phpcs.standard": "/full/path/to/project/phpcs.xml"
     ```
   - Replace `/full/path/to/project` with your actual project path

6. **Reload the window**:

   - `Ctrl+Shift+P` → "Developer: Reload Window"

7. **Check Problems panel**:

   - Open Problems panel (`Ctrl+Shift+M` / `Cmd+Shift+M`)
   - Errors should appear there if the extension is working

8. **Manual test**:
   - Open a PHP file with known phpcs issues (e.g., `src/Command/Application.php` line 115)
   - Save the file
   - Check if errors appear in Problems panel

### Auto-fix on save doesn't work

1. Ensure the PHP Sniffer & Beautifier extension is installed (`valeryanm.vscode-phpsab`)
2. Check that `vendor/bin/phpcbf` exists (run `composer install` if needed)
3. Verify `.vscode/settings.json` is properly configured
4. Try reloading the window: `Ctrl+Shift+P` → "Developer: Reload Window"
5. Check Output panel: View → Output → Select "PHP Sniffer & Beautifier" from dropdown
