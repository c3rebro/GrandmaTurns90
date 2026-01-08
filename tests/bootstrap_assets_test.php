<?php

declare(strict_types=1);

// Verify that locally vendored Bootstrap assets exist for template usage.
$basePath = __DIR__ . '/../public/assets/bootstrap';

$expectedFiles = [
    $basePath . '/css/bootstrap.min.css',
    $basePath . '/js/bootstrap.bundle.min.js',
    $basePath . '/LICENSE.md',
];

foreach ($expectedFiles as $path) {
    assert(file_exists($path), sprintf('Expected Bootstrap asset missing: %s', $path));
}
