/**
 * Testimonies live search. The form works without this script;
 * with it, results update as you type, without reloading the page.
 */
(function () {
    'use strict';

    var root = document.getElementById('tm');
    if (!root || !window.fetch || !window.URLSearchParams) {
        return;
    }

    var endpoint = root.getAttribute('data-endpoint');
    var form = root.querySelector('.tm-form');
    var input = document.getElementById('tm-q');
    var select = document.getElementById('tm-cat');
    var results = document.getElementById('tm-results');
    var live = document.getElementById('tm-live');
    var timer = null;
    var controller = null;
    var lastQuery = null;

    function searchParams(page) {
        var params = new URLSearchParams();
        var q = input.value.trim();
        if (q) params.set('tm_q', q);
        if (select.value) params.set('tm_cat', select.value);
        if (page > 1) params.set('tm_page', String(page));
        return params;
    }

    // Keep the address bar in step, so the results can be bookmarked or shared.
    function updateAddress(params) {
        var url = new URL(window.location.href);
        ['tm_q', 'tm_cat', 'tm_page'].forEach(function (key) {
            url.searchParams.delete(key);
        });
        params.forEach(function (value, key) {
            url.searchParams.set(key, value);
        });
        window.history.replaceState(null, '', url.toString());
    }

    function load(page, scroll) {
        var params = searchParams(page);
        var query = params.toString();
        if (query === lastQuery) return;
        lastQuery = query;

        if (controller) controller.abort();
        controller = window.AbortController ? new AbortController() : null;
        root.classList.add('tm-loading');

        fetch(endpoint + (query ? '?' + query : ''), {
            headers: { 'X-Requested-With': 'fetch' },
            signal: controller ? controller.signal : undefined
        })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            })
            .then(function (html) {
                results.innerHTML = html;
                updateAddress(params);
                var status = results.querySelector('.tm-status');
                live.textContent = status ? status.textContent : '';
                if (scroll) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
                root.classList.remove('tm-loading');
            })
            .catch(function (error) {
                if (error.name === 'AbortError') return;
                // Fall back to a normal page load if the live request fails.
                root.classList.remove('tm-loading');
                lastQuery = null;
                form.submit();
            });
    }

    lastQuery = searchParams(1).toString();

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { load(1, false); }, 250);
    });

    select.addEventListener('change', function () {
        clearTimeout(timer);
        load(1, false);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        clearTimeout(timer);
        load(1, false);
    });

    results.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-page]');
        if (!link) return;
        event.preventDefault();
        load(parseInt(link.getAttribute('data-page'), 10), true);
    });
})();
