#!/usr/bin/env php
<?php
/**
 * Checks that the kipchak.dev page shows every file in examples/ exactly, so that the code the tests run and the code
 * the documentation shows cannot drift apart:
 *
 *     php bin/check-doc-example.php <kipchak.dev checkout>/src/app/docs/driver-mcp/page.md
 *
 * The page marks each block with <!-- example:<file name> --> and <!-- /example -->. Exits 0 when every block equals
 * its file, and 1 when one differs or is missing, or a file cannot be read.
 */
$page = $argv[1] ?? '';
$markdown = is_file($page) ? file_get_contents($page) : false;
$examples = glob(__DIR__ . '/../examples/*.php') ?: [];
if ($markdown === false || $examples === []) {
    fwrite(STDERR, "Cannot read the page ({$page}) or find the examples.\n");
    exit(1);
}

$failed = false;
foreach ($examples as $example) {
    $name = basename($example);
    $code = file_get_contents($example);
    if (preg_match('/<!-- example:' . preg_quote($name, '/') . ' -->\n```php\n(.*?)```\n<!-- \/example -->/s', $markdown, $m) !== 1) {
        fwrite(STDERR, "The page has no marked {$name} block.\n");
        $failed = true;
        continue;
    }
    if ($m[1] !== $code) {
        fwrite(STDERR, "The page's block differs from examples/{$name}.\n");
        $failed = true;
        continue;
    }
    echo "The page shows examples/{$name} exactly.\n";
}
exit($failed ? 1 : 0);
