#!/usr/bin/env php
<?php

/**
 * Checks that the kipchak.dev page shows examples/authenticated-route.php exactly, so that the code T13 runs and the
 * code the documentation shows cannot drift apart:
 *
 *     php bin/check-doc-example.php <kipchak.dev checkout>/src/app/docs/driver-mcp/page.md
 *
 * The page marks the block with <!-- example:authenticated-route.php --> and <!-- /example -->. Exits 0 when the
 * block equals the file, and 1 when it differs, is missing, or either file cannot be read.
 */

$page = $argv[1] ?? '';
$example = __DIR__ . '/../examples/authenticated-route.php';
$markdown = is_file($page) ? file_get_contents($page) : false;
$code = is_file($example) ? file_get_contents($example) : false;
if ($markdown === false || $code === false) {
    fwrite(STDERR, "Cannot read the page ({$page}) or the example.\n");
    exit(1);
}
if (preg_match('/<!-- example:authenticated-route\.php -->\n```php\n(.*?)```\n<!-- \/example -->/s', $markdown, $m) !== 1) {
    fwrite(STDERR, "The page has no marked authenticated-route.php block.\n");
    exit(1);
}
if ($m[1] !== $code) {
    fwrite(STDERR, "The page's block differs from examples/authenticated-route.php.\n");
    exit(1);
}
echo "The page shows examples/authenticated-route.php exactly.\n";
