<?php
/**
 * Testimonies component.
 *
 * Usage in any PHP page:
 *     <?php require_once __DIR__ . '/testimonies/testimonies.php'; tm_render(); ?>
 *
 * Requested directly, this file returns just the results HTML (used by the live search).
 */

require_once __DIR__ . '/lib.php';

/** Output the whole component: search form, results and pager. */
function tm_render()
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;

    $state = tm_request();
    $endpoint = tm_base_url() . '/testimonies.php';
    ?>
<link rel="stylesheet" href="<?= tm_e(tm_asset_url('testimonies.css')) ?>">
<section class="tm" id="tm" data-endpoint="<?= tm_e($endpoint) ?>">
    <form class="tm-form" method="get" role="search">
        <?= tm_host_query_fields() ?>
        <div class="tm-field tm-field-q">
            <label for="tm-q">Search stories</label>
            <input type="search" id="tm-q" name="tm_q" value="<?= tm_e($state['q']) ?>"
                   maxlength="<?= (int) tm_config('max_query_length') ?>" placeholder="e.g. healing, job, prayer" autocomplete="off">
        </div>
        <div class="tm-field tm-field-cat">
            <label for="tm-cat">Category</label>
            <select id="tm-cat" name="tm_cat">
                <option value="">All categories</option>
                <?php foreach (tm_config('categories') as $cat): ?>
                    <option value="<?= tm_e($cat) ?>"<?= $cat === $state['cat'] ? ' selected' : '' ?>><?= tm_e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="tm-button">Search</button>
    </form>
    <p class="tm-sr-only" id="tm-live" aria-live="polite"></p>
    <div class="tm-results" id="tm-results">
        <?= tm_render_results($state) ?>
    </div>
</section>
<script src="<?= tm_e(tm_asset_url('testimonies.js')) ?>" defer></script>
    <?php
}

/** Hidden fields so the host page's own query parameters survive a search. */
function tm_host_query_fields()
{
    $html = '';
    foreach ($_GET as $key => $value) {
        if (is_string($value) && strpos((string) $key, 'tm_') !== 0) {
            $html .= '<input type="hidden" name="' . tm_e($key) . '" value="' . tm_e($value) . '">';
        }
    }
    return $html;
}

/** Link to a page of results, keeping the search, category and host parameters. */
function tm_page_url(array $state, $page)
{
    $params = array_filter($_GET, fn($v, $k) => strpos((string) $k, 'tm_') !== 0, ARRAY_FILTER_USE_BOTH);
    if ($state['q'] !== '') {
        $params['tm_q'] = $state['q'];
    }
    if ($state['cat'] !== '') {
        $params['tm_cat'] = $state['cat'];
    }
    if ($page > 1) {
        $params['tm_page'] = $page;
    }
    return '?' . http_build_query($params) . '#tm';
}

/** The status line, story cards and pager (also the live-search response). */
function tm_render_results(array $state)
{
    $words = tm_words($state['q']);
    $result = tm_paginate(tm_search(tm_load(), $state['q'], $state['cat']), $state['page'], tm_config('per_page'));
    $state['page'] = $result['page'];

    $filters = '';
    if ($state['q'] !== '') {
        $filters .= ' matching “' . $state['q'] . '”';
    }
    if ($state['cat'] !== '') {
        $filters .= ' in ' . $state['cat'];
    }

    if ($result['total'] === 0) {
        $status = 'No stories found' . $filters . '.';
    } elseif ($result['pages'] === 1) {
        $status = $result['total'] . ($result['total'] === 1 ? ' story' : ' stories') . $filters . '.';
    } else {
        $status = 'Showing ' . $result['from'] . '–' . $result['to'] . ' of ' . $result['total'] . ' stories' . $filters . '.';
    }

    ob_start();
    ?>
    <p class="tm-status"><?= tm_e($status) ?></p>
    <?php if ($result['total'] === 0): ?>
        <p class="tm-empty">Try fewer or different words, or <a href="<?= tm_e(tm_page_url(['q' => '', 'cat' => ''], 1)) ?>">show all stories</a>.</p>
    <?php else: ?>
        <div class="tm-list">
            <?php foreach ($result['items'] as $item): ?>
                <?= tm_render_card($item, $words) ?>
            <?php endforeach; ?>
        </div>
        <?= tm_render_pager($state, $result) ?>
    <?php endif;
    return ob_get_clean();
}

function tm_render_card(array $item, array $words)
{
    $body = (string) ($item['body'] ?? '');
    $excerpt = tm_excerpt($body, (int) tm_config('excerpt_length'));
    // Open the full story if the search words only appear in the hidden part.
    $open = $excerpt !== null && $words && !tm_contains_any($excerpt, $words) && tm_contains_any($body, $words);

    ob_start();
    ?>
    <article class="tm-card">
        <header class="tm-card-head">
            <h3 class="tm-title"><?= tm_highlight($item['title'] ?? '', $words) ?></h3>
            <p class="tm-meta">
                <?php if (!empty($item['category'])): ?>
                    <span class="tm-tag"><?= tm_e($item['category']) ?></span>
                <?php endif; ?>
                <span class="tm-name"><?= tm_highlight($item['name'] ?? 'Anonymous', $words) ?></span>
                <?php if (!empty($item['date'])): ?>
                    <time datetime="<?= tm_e($item['date']) ?>"><?= tm_e(tm_format_date($item['date'])) ?></time>
                <?php endif; ?>
            </p>
        </header>
        <?php if ($excerpt === null): ?>
            <div class="tm-body"><?= tm_paragraphs($body, $words) ?></div>
        <?php else: ?>
            <p class="tm-excerpt"><?= tm_highlight($excerpt, $words) ?></p>
            <details class="tm-more"<?= $open ? ' open' : '' ?>>
                <summary>Read the full story</summary>
                <div class="tm-body"><?= tm_paragraphs($body, $words) ?></div>
            </details>
        <?php endif; ?>
    </article>
    <?php
    return ob_get_clean();
}

function tm_render_pager(array $state, array $result)
{
    if ($result['pages'] < 2) {
        return '';
    }
    $page = $result['page'];
    $pages = $result['pages'];

    // Page numbers to show: first, last and two either side of the current page.
    $numbers = [];
    for ($n = 1; $n <= $pages; $n++) {
        if ($n === 1 || $n === $pages || abs($n - $page) <= 2) {
            $numbers[] = $n;
        }
    }

    $link = function ($n, $label, $rel = '') use ($state) {
        return '<a class="tm-page" href="' . tm_e(tm_page_url($state, $n)) . '" data-page="' . $n . '"'
            . ($rel ? ' rel="' . $rel . '"' : '') . '>' . $label . '</a>';
    };

    $html = '<nav class="tm-pager" aria-label="Story pages">';
    $html .= $page > 1 ? $link($page - 1, '‹ Previous', 'prev') : '<span class="tm-page tm-disabled">‹ Previous</span>';
    $previous = 0;
    foreach ($numbers as $n) {
        if ($n - $previous > 1) {
            $html .= '<span class="tm-gap">…</span>';
        }
        $html .= $n === $page
            ? '<span class="tm-page tm-current" aria-current="page">' . $n . '</span>'
            : $link($n, (string) $n);
        $previous = $n;
    }
    $html .= $page < $pages ? $link($page + 1, 'Next ›', 'next') : '<span class="tm-page tm-disabled">Next ›</span>';
    return $html . '</nav>';
}

// Requested directly by the live search: send only the results.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo tm_render_results(tm_request());
    exit;
}
