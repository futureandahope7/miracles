<?php
/**
 * Testimonies component: data storage, search, pagination and text helpers.
 * Everything is prefixed tm_ so it can't clash with the host site.
 */

function tm_config($key = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        // Private per-site settings (e.g. the admin password hash), kept out of git.
        if (is_file(__DIR__ . '/config.local.php')) {
            $config = array_replace($config, require __DIR__ . '/config.local.php');
        }
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function tm_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- Storage ---------- */

/**
 * The stories file is created on the server by the first admin save, and is
 * kept out of git and uploads so re-uploading the folder can't overwrite it.
 * Until then, the bundled sample stories are shown.
 */
function tm_load()
{
    $file = tm_config('data_file');
    if (!is_file($file)) {
        $file = __DIR__ . '/data/testimonies.sample.json';
    }
    if (!is_file($file)) {
        return [];
    }
    $items = json_decode((string) file_get_contents($file), true);
    return is_array($items) ? $items : [];
}

/**
 * Load, change and save the stories while holding a lock, so two admins
 * saving at once can't overwrite each other or leave a half-written file.
 * $change receives the current stories and returns the new list.
 */
function tm_update(callable $change)
{
    $file = tm_config('data_file');
    $lock = @fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        return false;
    }

    $items = array_values($change(tm_load()));
    $json = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Write to a temp file first, then swap it in, so readers never see a partial file.
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = $json !== false
        && @file_put_contents($tmp, $json) !== false
        && @rename($tmp, $file);
    if (!$ok && is_file($tmp)) {
        @unlink($tmp);
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    return $ok;
}

function tm_find(array $items, $id)
{
    foreach ($items as $item) {
        if ((int) ($item['id'] ?? 0) === (int) $id) {
            return $item;
        }
    }
    return null;
}

function tm_next_id(array $items)
{
    $max = 0;
    foreach ($items as $item) {
        $max = max($max, (int) ($item['id'] ?? 0));
    }
    return $max + 1;
}

/* ---------- Search and pagination ---------- */

/** Split search text into unique words (at most 10). */
function tm_words($q)
{
    $words = preg_split('/\s+/u', trim((string) $q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $unique = [];
    foreach ($words as $word) {
        $unique[mb_strtolower($word, 'UTF-8')] = $word;
    }
    return array_slice(array_values($unique), 0, 10);
}

function tm_contains_any($text, array $words)
{
    foreach ($words as $word) {
        if (mb_stripos($text, $word, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Stories whose title, name or text contain every search word,
 * optionally limited to one category. Newest first.
 */
function tm_search(array $items, $q = '', $category = '', $publishedOnly = true)
{
    $words = tm_words($q);

    $matches = array_filter($items, function ($item) use ($words, $category, $publishedOnly) {
        if ($publishedOnly && empty($item['published'])) {
            return false;
        }
        if ($category !== '' && ($item['category'] ?? '') !== $category) {
            return false;
        }
        $haystack = ($item['title'] ?? '') . "\n" . ($item['name'] ?? '') . "\n" . ($item['body'] ?? '');
        foreach ($words as $word) {
            if (mb_stripos($haystack, $word, 0, 'UTF-8') === false) {
                return false;
            }
        }
        return true;
    });

    usort($matches, function ($a, $b) {
        return [$b['date'] ?? '', $b['id'] ?? 0] <=> [$a['date'] ?? '', $a['id'] ?? 0];
    });

    return $matches;
}

function tm_paginate(array $items, $page, $perPage)
{
    $perPage = max(1, (int) $perPage);
    $total = count($items);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, (int) $page), $pages);
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($items, $offset, $perPage),
        'page'  => $page,
        'pages' => $pages,
        'total' => $total,
        'from'  => $total ? $offset + 1 : 0,
        'to'    => min($offset + $perPage, $total),
    ];
}

/** Read and clean the public search parameters from the query string. */
function tm_request()
{
    $q = isset($_GET['tm_q']) && is_string($_GET['tm_q']) ? trim($_GET['tm_q']) : '';
    $q = mb_substr($q, 0, (int) tm_config('max_query_length'), 'UTF-8');

    $cat = isset($_GET['tm_cat']) && is_string($_GET['tm_cat']) ? $_GET['tm_cat'] : '';
    if (!in_array($cat, tm_config('categories'), true)) {
        $cat = '';
    }

    $page = isset($_GET['tm_page']) && is_string($_GET['tm_page']) && ctype_digit($_GET['tm_page'])
        ? (int) $_GET['tm_page']
        : 1;

    return ['q' => $q, 'cat' => $cat, 'page' => $page];
}

/* ---------- Text formatting ---------- */

/** Escape text for HTML, wrapping each search word in <mark>. */
function tm_highlight($text, array $words)
{
    if (!$words) {
        return tm_e($text);
    }
    usort($words, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
    $pattern = '/(' . implode('|', array_map(fn($w) => preg_quote($w, '/'), $words)) . ')/iu';

    $parts = preg_split($pattern, (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return tm_e($text);
    }
    $html = '';
    foreach ($parts as $i => $part) {
        $html .= $i % 2 ? '<mark>' . tm_e($part) . '</mark>' : tm_e($part);
    }
    return $html;
}

/** Turn plain text into paragraphs (blank line = new paragraph). */
function tm_paragraphs($text, array $words = [])
{
    $html = '';
    foreach (preg_split('/\R{2,}/u', trim((string) $text)) as $para) {
        $html .= '<p>' . nl2br(tm_highlight(trim($para), $words), false) . '</p>';
    }
    return $html;
}

/** First part of the text, cut at a word boundary, or null if the text is already short. */
function tm_excerpt($text, $length)
{
    $flat = trim(preg_replace('/\s+/u', ' ', (string) $text));
    if (mb_strlen($flat, 'UTF-8') <= $length) {
        return null;
    }
    $cut = mb_substr($flat, 0, $length, 'UTF-8');
    $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
    if ($space !== false && $space > $length * 0.6) {
        $cut = mb_substr($cut, 0, $space, 'UTF-8');
    }
    return rtrim($cut, " ,.;:-") . '…';
}

function tm_valid_date($date)
{
    $d = DateTime::createFromFormat('!Y-m-d', (string) $date);
    return $d && $d->format('Y-m-d') === $date;
}

function tm_format_date($date)
{
    return tm_valid_date($date) ? DateTime::createFromFormat('!Y-m-d', $date)->format('j F Y') : (string) $date;
}

/* ---------- URLs ---------- */

/** Web path to the component folder, e.g. "/miracle/testimonies". */
function tm_base_url()
{
    if (tm_config('base_url')) {
        return rtrim(tm_config('base_url'), '/');
    }
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $dir = realpath(__DIR__);
    if ($docRoot && $dir) {
        $docRoot = rtrim($docRoot, '/\\');
        if (stripos($dir, $docRoot) === 0) {
            return '/' . trim(str_replace('\\', '/', substr($dir, strlen($docRoot))), '/');
        }
    }
    return 'testimonies';
}

function tm_asset_url($file)
{
    $path = __DIR__ . '/assets/' . $file;
    $version = is_file($path) ? filemtime($path) : 0;
    return tm_base_url() . '/assets/' . $file . '?v=' . $version;
}
