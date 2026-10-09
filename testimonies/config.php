<?php
/**
 * Testimonies component settings.
 */

return [
    // Stories shown per page on the public list.
    'per_page' => 6,

    // Characters shown before a story is collapsed behind "Read the full story".
    'excerpt_length' => 240,

    // Longest search text accepted (anything longer is cut off).
    'max_query_length' => 100,

    // Allowed categories, in the order they appear in the filter.
    'categories' => [
        'Healing',
        'Provision',
        'Salvation',
        'Restoration',
        'Answered Prayer',
        'Guidance',
    ],

    // Where the stories are stored. Must be writable by the web server for the admin page.
    'data_file' => __DIR__ . '/data/testimonies.json',

    // Web path to this folder, e.g. '/miracle/testimonies'. Leave null to work it out automatically.
    'base_url' => null,

    // Admin password hash. Don't set it here: put it in config.local.php, which is kept out of git.
    // Open admin.php to generate that file's contents, or from a terminal:
    // php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
    'admin_password_hash' => '',
];
