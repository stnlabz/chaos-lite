<?php
// admin login — self-contained (no classes, no autoload)
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

// paths
$root = rtrim(dirname(__DIR__, 2), '/\\');
$data = $root . '/data';
if (!is_dir($data)) { @mkdir($data, 0775, true); }
$users_file = $data . '/users.json';

// helpers
function users_all(string $file): array {
    if (is_file($file)) {
        $j = json_decode((string) @file_get_contents($file), true);
        return is_array($j) ? $j : [];
    }
    return [];
}

function users_save(string $file, array $rows): void {
    @file_put_contents($file, json_encode(array_values($rows), 
JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function users_find(array $rows, string $email): ?array {
    $email = strtolower($email);
    foreach ($rows as $u) {
        if (strtolower((string) ($u['email'] ?? '')) === $email) {
            return $u;
        }
    }
    return null;
}

// ensure first-user flow
$users = users_all($users_file);
$error = '';

// handle POST (make sure the quotes are present!)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 
'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');

    if ($email === '' || $pass === '') {
        $error = 'Email and password required.';
    } else {
        if (count($users) === 0) {
            // first user becomes admin
            $users[] = [
                'email'    => $email,
                'password' => password_hash($pass, PASSWORD_DEFAULT),
                'role'     => 'admin',
                'created'  => gmdate('c'),
            ];
            users_save($users_file, $users);
        }

        // reload users in case we just created one
        $users = users_all($users_file);

        // authenticate
        $u = users_find($users, $email);
        if ($u && password_verify($pass, (string) ($u['password'] ?? 
''))) {
            $_SESSION['admin'] = [
                'email' => $u['email'],
                'role'  => (string) ($u['role'] ?? 'editor'),
                'time'  => time(),
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
            ];
            if (!headers_sent()) { header('Location: /admin'); exit; }
            echo '<div class="container my-4"><p>Logged in. <a 
href="/admin">Continue</a></p></div>';
            return;
        }

        $error = 'Invalid credentials.';
    }
}
?>
<div class="container my-4" style="max-width:420px;">
  <h1 class="h5 mb-3">Admin Login</h1>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, 
ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="post" action="/admin?action=login" class="card p-3">
    <div class="mb-3">
      <label class="form-label">Email</label>
      <input class="form-control" type="email" name="email" required 
autofocus>
    </div>
    <div class="mb-3">
      <label class="form-label">Password</label>
      <input class="form-control" type="password" name="password" 
required>
    </div>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>

  <?php if (count($users) === 0): ?>
    <div class="mt-3 text-muted small">
      No users yet. Submitting this form will create the first 
<strong>admin</strong> user.
    </div>
  <?php endif; ?>
</div>

