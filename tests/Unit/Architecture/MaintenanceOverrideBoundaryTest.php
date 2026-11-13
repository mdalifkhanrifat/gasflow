<?php

/**
 * Design 04, "V7.12 Maintenance override contract": no HTTP route, controller,
 * job or API endpoint may set app.maintenance_override or use the operator
 * connection. Only the gasflow:maintenance command may.
 */
const PROJECT_ROOT = __DIR__.'/../../..';
const SCANNED_DIRECTORIES = ['app', 'bootstrap', 'config', 'routes'];
const MAINTENANCE_COMMAND = 'app/Shared/Lifecycle/Maintenance/MaintenanceOverrideCommand.php';

/**
 * PHP files (relative paths) whose code, comments left out, matches the pattern.
 *
 * @return list<string>
 */
function phpFilesMatching(string $pattern): array
{
    $matches = [];

    foreach (SCANNED_DIRECTORIES as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PROJECT_ROOT.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($token) ? $token[1] : $token;
            }

            if (preg_match($pattern, $code) === 1) {
                $matches[] = $directory.'/'.substr($file->getPathname(), strlen(PROJECT_ROOT.'/'.$directory.'/'));
            }
        }
    }

    sort($matches);

    return $matches;
}

describe('maintenance override boundary', function () {
    it('lets only the maintenance command set the override', function () {
        // Act
        $files = phpFilesMatching('~maintenance_override(?!_logs)~');

        // Assert
        expect($files)->toBe([MAINTENANCE_COMMAND]);
    });

    it('lets only the maintenance command use the operator connection', function () {
        // Act
        $files = phpFilesMatching('~pgsql_maintenance~');

        // Assert
        expect($files)->toBe([MAINTENANCE_COMMAND, 'config/database.php']);
    });
});
