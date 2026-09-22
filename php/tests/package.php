<?php

declare(strict_types=1);

// Exercise the archive in a clean Composer consumer, without the source autoloader.
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/kid-package-' . bin2hex(random_bytes(8));
mkdir($temp, 0700, true);
function command(string $command): void
{
    passthru($command, $status);
    if ($status !== 0) {
        throw new RuntimeException('Command failed: ' . $command);
    }
}
function removeTree(string $path): void
{
    foreach (new FilesystemIterator($path) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            removeTree($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}
try {
    command('composer --working-dir=' . escapeshellarg($root) . ' archive --format=tar --file=kid --dir=' . escapeshellarg($temp));
    $archive = new PharData($temp . '/kid.tar');
    foreach (['composer.json', 'README.md', 'src/Kid.php', 'src/InvalidKidException.php'] as $file) {
        if (!isset($archive[$file])) {
            throw new RuntimeException('Archive missing ' . $file);
        }
    }
    if (isset($archive['vendor']) || isset($archive['tests'])) {
        throw new RuntimeException('Archive includes development files');
    }
    mkdir($temp . '/package');
    $archive->extractTo($temp . '/package');
    mkdir($temp . '/consumer');
    file_put_contents($temp . '/consumer/composer.json', json_encode([
        'name' => 'keboola/identifier-smoke-test',
        'repositories' => [[
            'type' => 'path', 'url' => '../package',
            'options' => ['symlink' => false, 'versions' => ['keboola/identifier' => '1.0.0']],
        ]],
        'require' => ['keboola/identifier' => '1.0.0'],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    command('composer --working-dir=' . escapeshellarg($temp . '/consumer') . ' install --no-dev --no-interaction');
    $smoke = <<<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
use Keboola\Identifier\Kid;
use Keboola\Identifier\InvalidKidException;
if ((string) Kid::create('s', ['project' => '1']) !== 'KID:s:project-1') { exit(1); }
if (!Kid::validate('KID:s:project-1') || Kid::validate('KID:s:project-abc')) { exit(1); }
try { Kid::fromString('invalid'); exit(1); }
catch (InvalidKidException $e) { if ($e->code !== 'bad_prefix') { exit(1); } }
echo "OK: archive contents, clean Composer install and public API import\n";
PHP;
    file_put_contents($temp . '/consumer/smoke.php', $smoke);
    command(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($temp . '/consumer/smoke.php'));
} finally {
    removeTree($temp);
}
