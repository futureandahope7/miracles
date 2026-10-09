# Progress of Project

## Tasks to complete
- Set the admin password: open /miracle/testimonies/admin.php and save the generated text as testimonies/config.local.php (kept out of git)
- Replace the sample stories with real testimonies (via the admin page)
- Test live search in a browser
- Ideas for later: public story submission form (held for approval), back-button support for live search pages, story photos

## ALready completed tasks
- Component folder `testimonies/` that can be added to any PHP page with `require_once` + `tm_render()`
- JSON storage (`testimonies/data/testimonies.json`) with 15 sample stories, safe locked writes, raw file blocked by .htaccess
- Server-side search (all words must match, highlighted with <mark>), category filter, pagination (works without JavaScript)
- Vanilla JS live search as you type (`assets/testimonies.js`)
- Card layout CSS, all scoped under `.tm` (`assets/testimonies.css`)
- Admin page (`testimonies/admin.php`): password login, add/edit/hide/delete stories, CSRF protection
- Settings in `testimonies/config.php` (stories per page, categories, excerpt length, password hash)
- Admin story list: optional category selector next to the search box ("All categories" by default), works alone or combined with a text search
