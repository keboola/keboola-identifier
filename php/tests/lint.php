<?php

declare(strict_types=1);

foreach (['src', 'tests', 'examples'] as $directory) {
    foreach (glob(__DIR__ . '/../' . $directory . '/*.php') as $file) {
        passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $status);
        if ($status !== 0) {
            exit($status);
        }
    }
}
