<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function get_client_ip(): string
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = array_map('trim', explode(',', $forwarded));
        if ($parts[0] !== '') {
            return $parts[0];
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function render_rich_text(string $content): string
{
    $trimmed = trim($content);
    if ($trimmed === '') {
        return '';
    }

    $html = (str_contains($trimmed, '<') && str_contains($trimmed, '>'))
        ? $trimmed
        : convert_basic_markdown($trimmed);

    return sanitize_rich_html($html);
}

function convert_basic_markdown(string $text): string
{
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $parts = [];
    $inList = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            if ($inList) {
                $parts[] = '</ul>';
                $inList = false;
            }
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $matches)) {
            if ($inList) {
                $parts[] = '</ul>';
                $inList = false;
            }
            $level = strlen($matches[1]);
            $parts[] = sprintf('<h%d>%s</h%d>', $level, parse_inline_markdown($matches[2]), $level);
            continue;
        }

        if (preg_match('/^[-*]\s+(.*)$/', $line, $matches)) {
            if (!$inList) {
                $parts[] = '<ul>';
                $inList = true;
            }
            $parts[] = sprintf('<li>%s</li>', parse_inline_markdown($matches[1]));
            continue;
        }

        if ($inList) {
            $parts[] = '</ul>';
            $inList = false;
        }

        $parts[] = sprintf('<p>%s</p>', parse_inline_markdown($line));
    }

    if ($inList) {
        $parts[] = '</ul>';
    }

    return implode("\n", $parts);
}

function parse_inline_markdown(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);

    return preg_replace_callback(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        static function (array $matches): string {
            $label = $matches[1];
            $url = $matches[2];
            if (!preg_match('/^(mailto:|https?:\/\/)/i', $url)) {
                return $label;
            }
            $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return sprintf('<a href="%s">%s</a>', $safeUrl, $label);
        },
        $escaped
    );
}

function sanitize_rich_html(string $html): string
{
    $allowed = '<strong><b><em><h1><h2><h3><ul><ol><li><p><br><a>';
    $sanitized = strip_tags($html, $allowed);

    return preg_replace_callback(
        '/<a\s+[^>]*>/i',
        static function (array $matches): string {
            $tag = $matches[0];
            if (preg_match('/href\s*=\s*("|\')(.*?)\1/i', $tag, $hrefMatch)) {
                $href = $hrefMatch[2];
                if (preg_match('/^(mailto:|https?:\/\/)/i', $href)) {
                    $safeHref = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
                    return '<a href="' . $safeHref . '">';
                }
            }
            return '<a>';
        },
        $sanitized
    );
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
