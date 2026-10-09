<?php
/**
 * Testimonies admin: add, edit, publish and delete stories.
 */

require_once __DIR__ . '/lib.php';

session_name('tm_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

if (empty($_SESSION['tm_csrf'])) {
    $_SESSION['tm_csrf'] = bin2hex(random_bytes(32));
}

function tm_admin_csrf_field()
{
    return '<input type="hidden" name="tm_csrf" value="' . tm_e($_SESSION['tm_csrf']) . '">';
}

function tm_admin_check_csrf()
{
    $token = $_POST['tm_csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['tm_csrf'], $token)) {
        http_response_code(400);
        exit('This form has expired. Go back, reload the page and try again.');
    }
}

function tm_admin_flash($message, $error = false)
{
    $_SESSION['tm_flash'] = ['message' => $message, 'error' => $error];
}

function tm_admin_redirect($query = '')
{
    header('Location: admin.php' . $query);
    exit;
}

function tm_admin_post($key)
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
}

/** Check a submitted story. Returns [story, errors]. */
function tm_admin_validate()
{
    $story = [
        'title'     => tm_admin_post('title'),
        'name'      => tm_admin_post('name'),
        'date'      => tm_admin_post('date'),
        'category'  => tm_admin_post('category'),
        'body'      => str_replace("\r\n", "\n", tm_admin_post('body')),
        'published' => isset($_POST['published']),
    ];
    $errors = [];

    if ($story['title'] === '') {
        $errors['title'] = 'Please give the story a title.';
    } elseif (mb_strlen($story['title'], 'UTF-8') > 200) {
        $errors['title'] = 'The title must be 200 characters or fewer.';
    }
    if (mb_strlen($story['name'], 'UTF-8') > 100) {
        $errors['name'] = 'The name must be 100 characters or fewer.';
    }
    if ($story['name'] === '') {
        $story['name'] = 'Anonymous';
    }
    if ($story['date'] === '') {
        $story['date'] = date('Y-m-d');
    } elseif (!tm_valid_date($story['date'])) {
        $errors['date'] = 'Please enter a valid date.';
    }
    if (!in_array($story['category'], tm_config('categories'), true)) {
        $errors['category'] = 'Please choose a category.';
    }
    if ($story['body'] === '') {
        $errors['body'] = 'Please enter the story.';
    } elseif (mb_strlen($story['body'], 'UTF-8') > 20000) {
        $errors['body'] = 'The story must be 20,000 characters or fewer.';
    }

    return [$story, $errors];
}

/* ---------- Handle form submissions ---------- */

$hash = (string) tm_config('admin_password_hash');
$loggedIn = !empty($_SESSION['tm_admin']);
$view = $hash === '' ? 'setup' : ($loggedIn ? 'list' : 'login');
$error = '';
$errors = [];
$story = null;
$generatedHash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tm_admin_check_csrf();
    $action = tm_admin_post('tm_action');

    if ($action === 'setup' && $hash === '') {
        $password = $_POST['password'] ?? '';
        if (!is_string($password) || strlen($password) < 8) {
            $error = 'Please choose a password of at least 8 characters.';
        } elseif ($password !== ($_POST['confirm'] ?? '')) {
            $error = 'The two passwords don’t match.';
        } else {
            $generatedHash = password_hash($password, PASSWORD_DEFAULT);
        }
    } elseif ($action === 'login' && $hash !== '') {
        $password = $_POST['password'] ?? '';
        if (is_string($password) && password_verify($password, $hash)) {
            session_regenerate_id(true);
            $_SESSION['tm_admin'] = true;
            tm_admin_redirect();
        }
        sleep(1); // slow down password guessing
        $error = 'That password isn’t right.';
    } elseif (!$loggedIn) {
        http_response_code(403);
        exit('Please log in first.');
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        tm_admin_redirect();
    } elseif ($action === 'save') {
        $id = (int) tm_admin_post('id');
        [$story, $errors] = tm_admin_validate();
        if ($errors) {
            $story['id'] = $id;
            $view = 'form';
        } else {
            $saved = tm_update(function ($items) use ($story, $id) {
                if ($id && tm_find($items, $id)) {
                    foreach ($items as $i => $item) {
                        if ((int) $item['id'] === $id) {
                            $items[$i] = ['id' => $id] + $story;
                        }
                    }
                } else {
                    $items[] = ['id' => tm_next_id($items)] + $story;
                }
                return $items;
            });
            tm_admin_flash($saved ? '“' . $story['title'] . '” was saved.' : 'Could not save. Check that the data folder is writable.', !$saved);
            tm_admin_redirect();
        }
    } elseif ($action === 'delete' || $action === 'toggle') {
        $id = (int) tm_admin_post('id');
        $saved = tm_update(function ($items) use ($action, $id) {
            foreach ($items as $i => $item) {
                if ((int) $item['id'] === $id) {
                    if ($action === 'delete') {
                        unset($items[$i]);
                    } else {
                        $items[$i]['published'] = empty($item['published']);
                    }
                }
            }
            return $items;
        });
        $done = $action === 'delete' ? 'Story deleted.' : 'Story updated.';
        tm_admin_flash($saved ? $done : 'Could not save. Check that the data folder is writable.', !$saved);
        tm_admin_redirect();
    }
}

/* ---------- Choose what to show ---------- */

if ($view === 'list' && isset($_GET['new'])) {
    $view = 'form';
    $story = ['id' => 0, 'title' => '', 'name' => '', 'date' => date('Y-m-d'), 'category' => '', 'body' => '', 'published' => true];
} elseif ($view === 'list' && isset($_GET['edit'])) {
    $story = tm_find(tm_load(), (int) $_GET['edit']);
    if (!$story) {
        tm_admin_flash('That story could not be found.', true);
        tm_admin_redirect();
    }
    $view = 'form';
}

$flash = $_SESSION['tm_flash'] ?? null;
unset($_SESSION['tm_flash']);

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$cat = isset($_GET['cat']) && is_string($_GET['cat']) && in_array($_GET['cat'], tm_config('categories'), true) ? $_GET['cat'] : '';

header('X-Robots-Tag: noindex');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Stories admin</title>
    <link rel="stylesheet" href="<?= tm_e(tm_asset_url('testimonies.css')) ?>">
</head>
<body>
<main class="tm tm-admin">

<?php if ($flash): ?>
    <p class="tm-flash<?= $flash['error'] ? ' tm-error' : '' ?>" role="status"><?= tm_e($flash['message']) ?></p>
<?php endif; ?>
<?php if ($error): ?>
    <p class="tm-flash tm-error" role="alert"><?= tm_e($error) ?></p>
<?php endif; ?>

<?php if ($view === 'setup'): ?>
    <h1>Set up the admin password</h1>
    <?php if ($generatedHash): ?>
        <p>Create the file <code>testimonies/config.local.php</code> (or replace its contents) with this. It is kept out of git so the hash stays private:</p>
        <pre><?= tm_e("<?php\nreturn [\n    'admin_password_hash' => '" . $generatedHash . "',\n];") ?></pre>
        <p>Save the file, then <a href="admin.php">reload this page</a> to log in.</p>
    <?php else: ?>
        <p>No admin password has been set yet. Choose one below. This page will create the text to save as <code>config.local.php</code>. Your password itself is not stored.</p>
        <form method="post" class="tm-edit-form">
            <?= tm_admin_csrf_field() ?>
            <input type="hidden" name="tm_action" value="setup">
            <div class="tm-field">
                <label for="password">New password (at least 8 characters)</label>
                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
            </div>
            <div class="tm-field">
                <label for="confirm">Type it again</label>
                <input type="password" id="confirm" name="confirm" required minlength="8" autocomplete="new-password">
            </div>
            <div><button type="submit" class="tm-button">Create password line</button></div>
        </form>
    <?php endif; ?>

<?php elseif ($view === 'login'): ?>
    <h1>Stories admin</h1>
    <form method="post" class="tm-edit-form">
        <?= tm_admin_csrf_field() ?>
        <input type="hidden" name="tm_action" value="login">
        <div class="tm-field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autofocus autocomplete="current-password">
        </div>
        <div><button type="submit" class="tm-button">Log in</button></div>
    </form>

<?php elseif ($view === 'form'): ?>
    <div class="tm-admin-bar">
        <h1><?= $story['id'] ? 'Edit story' : 'Add a story' ?></h1>
        <a class="tm-button-quiet" href="admin.php">Back to all stories</a>
    </div>
    <form method="post" class="tm-edit-form" novalidate>
        <?= tm_admin_csrf_field() ?>
        <input type="hidden" name="tm_action" value="save">
        <input type="hidden" name="id" value="<?= (int) $story['id'] ?>">

        <div class="tm-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" value="<?= tm_e($story['title']) ?>" maxlength="200" required>
            <?php if (isset($errors['title'])): ?><p class="tm-field-error"><?= tm_e($errors['title']) ?></p><?php endif; ?>
        </div>

        <div class="tm-row">
            <div class="tm-field">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" value="<?= tm_e($story['name']) ?>" maxlength="100">
                <p class="tm-hint">Leave blank to show “Anonymous”.</p>
                <?php if (isset($errors['name'])): ?><p class="tm-field-error"><?= tm_e($errors['name']) ?></p><?php endif; ?>
            </div>
            <div class="tm-field">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" value="<?= tm_e($story['date']) ?>">
                <?php if (isset($errors['date'])): ?><p class="tm-field-error"><?= tm_e($errors['date']) ?></p><?php endif; ?>
            </div>
            <div class="tm-field">
                <label for="category">Category</label>
                <select id="category" name="category" required>
                    <option value="">Choose…</option>
                    <?php foreach (tm_config('categories') as $cat): ?>
                        <option value="<?= tm_e($cat) ?>"<?= $cat === $story['category'] ? ' selected' : '' ?>><?= tm_e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['category'])): ?><p class="tm-field-error"><?= tm_e($errors['category']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="tm-field">
            <label for="body">Story</label>
            <textarea id="body" name="body" required><?= tm_e($story['body']) ?></textarea>
            <p class="tm-hint">Leave a blank line between paragraphs.</p>
            <?php if (isset($errors['body'])): ?><p class="tm-field-error"><?= tm_e($errors['body']) ?></p><?php endif; ?>
        </div>

        <label><input type="checkbox" name="published" value="1"<?= !empty($story['published']) ? ' checked' : '' ?>> Published (visible to visitors)</label>

        <div class="tm-admin-actions">
            <button type="submit" class="tm-button">Save story</button>
            <a class="tm-button-quiet" href="admin.php">Cancel</a>
        </div>
    </form>

<?php else: ?>
    <?php
    $all = tm_load();
    $items = tm_search($all, $q, $cat, false);
    ?>
    <div class="tm-admin-bar">
        <h1>Stories (<?= count($all) ?>)</h1>
        <div class="tm-admin-actions">
            <a class="tm-button" href="admin.php?new=1">Add a story</a>
            <form method="post" class="tm-inline">
                <?= tm_admin_csrf_field() ?>
                <input type="hidden" name="tm_action" value="logout">
                <button type="submit" class="tm-button-quiet">Log out</button>
            </form>
        </div>
    </div>

    <form method="get" class="tm-form" role="search">
        <div class="tm-field tm-field-q">
            <label for="q">Find a story</label>
            <input type="search" id="q" name="q" value="<?= tm_e($q) ?>">
        </div>
        <div class="tm-field tm-field-cat">
            <label for="cat">Category</label>
            <select id="cat" name="cat">
                <option value="">All categories</option>
                <?php foreach (tm_config('categories') as $option): ?>
                    <option value="<?= tm_e($option) ?>"<?= $option === $cat ? ' selected' : '' ?>><?= tm_e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="tm-button">Search</button>
        <?php if ($q !== '' || $cat !== ''): ?><a class="tm-button-quiet" href="admin.php">Clear</a><?php endif; ?>
    </form>

    <?php if (!$items): ?>
        <p class="tm-status"><?= $q !== '' || $cat !== '' ? 'No stories match your search.' : 'No stories yet. Use “Add a story” to create the first one.' ?></p>
    <?php else: ?>
        <div class="tm-table-wrap">
            <table class="tm-table">
                <thead>
                    <tr><th>Title</th><th>Category</th><th>Date</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><strong><?= tm_e($item['title']) ?></strong><br><span class="tm-hint"><?= tm_e($item['name'] ?? '') ?></span></td>
                        <td><?= tm_e($item['category'] ?? '') ?></td>
                        <td><?= tm_e($item['date'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($item['published'])): ?>
                                <span class="tm-status-pill">Published</span>
                            <?php else: ?>
                                <span class="tm-status-pill tm-draft">Hidden</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="tm-admin-actions">
                                <a class="tm-button-quiet" href="admin.php?edit=<?= (int) $item['id'] ?>">Edit</a>
                                <form method="post" class="tm-inline">
                                    <?= tm_admin_csrf_field() ?>
                                    <input type="hidden" name="tm_action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit" class="tm-button-quiet"><?= !empty($item['published']) ? 'Hide' : 'Publish' ?></button>
                                </form>
                                <form method="post" class="tm-inline" onsubmit="return confirm('Delete this story? This cannot be undone.');">
                                    <?= tm_admin_csrf_field() ?>
                                    <input type="hidden" name="tm_action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit" class="tm-button-quiet tm-button-danger">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

</main>
</body>
</html>
