<?php

declare(strict_types=1);

namespace Tcrawf\Zebra\Tests\Helper;

/**
 * Helper for creating test editor scripts in command tests.
 */
class TestEditorHelper
{
    /**
     * Create a test editor script that returns predefined content.
     *
     * @param string $mode 'no-change' to return input as-is, 'custom' to return custom content,
     *                     'sequence' for multiple edits
     * @param string|array|null $content Custom content or array of content for sequence mode
     * @return string Path to the test editor script
     */
    public static function createTestEditorScript(string $mode, string|array|null $content = null): string
    {
        $scriptPath = sys_get_temp_dir() . '/zebra_test_editor_' . uniqid() . '.sh';
        $scriptContent = "#!/bin/bash\n";

        if ($mode === 'no-change') {
            // Return input file as-is (no changes)
            $scriptContent .= "cat \"\$1\" > \"\$1.tmp\"\n";
            $scriptContent .= "mv \"\$1.tmp\" \"\$1\"\n";
            $scriptContent .= "exit 0\n";
        } elseif ($mode === 'custom' && is_string($content)) {
            // Return custom content
            $escapedContent = addslashes($content);
            $scriptContent .= "cat > \"\$1\" << 'EOF'\n";
            $scriptContent .= $content . "\n";
            $scriptContent .= "EOF\n";
            $scriptContent .= "exit 0\n";
        } elseif ($mode === 'sequence' && is_array($content)) {
            // Return content from sequence, tracking via a state file
            $stateFile = $scriptPath . '.state';
            $scriptContent .= "STATE_FILE=\"$stateFile\"\n";
            $scriptContent .= "if [ ! -f \"\$STATE_FILE\" ]; then\n";
            $scriptContent .= "  echo 0 > \"\$STATE_FILE\"\n";
            $scriptContent .= "fi\n";
            $scriptContent .= "INDEX=\$(cat \"\$STATE_FILE\")\n";
            $scriptContent .= "INDEX=\$((INDEX + 1))\n";
            $scriptContent .= "echo \$INDEX > \"\$STATE_FILE\"\n";
            $scriptContent .= "case \$INDEX in\n";
            foreach ($content as $i => $item) {
                $idx = $i + 1;
                if ($item === null) {
                    // No change
                    $scriptContent .= "  $idx) cat \"\$1\" > \"\$1.tmp\" && mv \"\$1.tmp\" \"\$1\" ;;\n";
                } else {
                    $escapedItem = addslashes($item);
                    $scriptContent .= "  $idx) cat > \"\$1\" << 'EOF$idx'\n";
                    $scriptContent .= $item . "\n";
                    $scriptContent .= "EOF$idx\n";
                    $scriptContent .= "    ;;\n";
                }
            }
            $scriptContent .= "  *) cat \"\$1\" > \"\$1.tmp\" && mv \"\$1.tmp\" \"\$1\" ;;\n";
            $scriptContent .= "esac\n";
            $scriptContent .= "exit 0\n";
        }

        file_put_contents($scriptPath, $scriptContent);
        chmod($scriptPath, 0755);

        return $scriptPath;
    }
}
