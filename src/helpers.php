<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function load_config(string $configPath): ?array
{
    if (!file_exists($configPath)) {
        return null;
    }

    return require $configPath;
}
