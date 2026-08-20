<?php

/**
 * Blade template linter.
 *
 * Blade templates compile to PHP, so a malformed directive or a typo inside {{ }}
 * becomes a PHP parse error at render time -- i.e. a 500 in the widget body, or a
 * broken settings form that cannot be closed.
 *
 * This compiles every template with the real Blade compiler and runs `php -l` over
 * the generated PHP. It does not render anything, so no LibreNMS installation, no
 * database and no view data are needed.
 *
 * Usage:  php tests/blade-lint.php
 * Exit:   0 = all templates compile to valid PHP, 1 = at least one does not
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (! is_file($autoload)) {
    fwrite(STDERR, "vendor/autoload.php is missing. Run: composer install\n");
    exit(1);
}

require $autoload;

if (! class_exists(\Illuminate\View\Compilers\BladeCompiler::class)) {
    fwrite(STDERR, "illuminate/view is not installed; skipping Blade lint.\n");
    exit(0);
}

$viewDir = $root . '/resources/views';

if (! is_dir($viewDir)) {
    fwrite(STDERR, "No resources/views directory.\n");
    exit(0);
}

$cache = sys_get_temp_dir() . '/nmsdw-blade-lint-' . getmypid();
@mkdir($cache, 0777, true);

$compiler = new \Illuminate\View\Compilers\BladeCompiler(
    new \Illuminate\Filesystem\Filesystem(),
    $cache
);

$templates = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($viewDir, FilesystemIterator::SKIP_DOTS)
);

$failures = 0;
$checked = 0;

foreach ($templates as $file) {
    if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
        continue;
    }

    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    $relative = str_replace('\\', '/', $relative);
    $checked++;

    try {
        $php = $compiler->compileString((string) file_get_contents($file->getPathname()));
    } catch (\Throwable $e) {
        $failures++;
        printf("  [FAIL] %s\n         Blade compile error: %s\n", $relative, $e->getMessage());
        continue;
    }

    $tmp = $cache . '/lint-' . $checked . '.php';
    file_put_contents($tmp, $php);

    $output = [];
    $status = 0;
    exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($tmp)), $output, $status);

    if ($status === 0) {
        printf("  [ ok ] %s\n", $relative);
    } else {
        $failures++;
        printf("  [FAIL] %s\n", $relative);
        foreach ($output as $line) {
            if (trim($line) !== '' && ! str_starts_with($line, 'No syntax errors')) {
                printf("         %s\n", str_replace($tmp, '(compiled)', $line));
            }
        }
    }

    @unlink($tmp);
}

// Best-effort cleanup of the scratch directory.
foreach ((array) glob($cache . '/*') as $leftover) {
    @unlink($leftover);
}
@rmdir($cache);

if ($failures > 0) {
    printf("\n%d of %d templates failed to compile.\n", $failures, $checked);
    exit(1);
}

printf("\nAll %d Blade templates compile to valid PHP.\n", $checked);
exit(0);
