<?php
/**
 * Admin » Account (self-service profile with display name + close account)
 *
 * Features:
 *  - Update display name (stored as "name"), username, email
 *  - Change password (requires current password)
 *  - Create self entry if missing (manual or from session)
 *  - Close account (self-delete): requires password; blocks if last active admin
 *
 * Storage:
 *  - Prefers /app/admin/data/users.json
 *  - Fallback /app/data/users.json
 */

declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
error_reporting(E_ALL); ini_set('display_errors', '1');

/** Ensure dir helper if not loaded */
if (!function_exists('ensure_dir')) {
    function ensure_dir(string $d): void { if (!is_dir($d)) { @mkdir($d, 0775, true); } }
}
/** Minimal JSON helpers if not loaded already */
if (!function_exists('jread')) {
    function jread(string $file, $default = []) {
        if (!is_file($file)) return $default;
        $raw = (string)@file_get_contents($file);
        $j   = json_decode($raw, true);
        return is_array($j) ? $j : $default;
    }
}
if (!function_exists('jwrite')) {
    function jwrite(string $file, $data): bool {
        $tmp = $file . '.tmp';
        $enc = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($enc === false) return false;
        if (@file_put_contents($tmp, $enc) === false) return false;
        return @rename($tmp, $file);
    }
}
if (!function_exists('e')) {
    function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('csrf_token')) {
    function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
}
if (!function_exists('csrf_ok')) {
    function csrf_ok(string $t): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t); }
}
if (!function_exists('is_post')) {
    function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
}

/** Paths */
function account_admin_data_dir(): string {
    $adminRoot = dirname(__DIR__, 1); // /app/admin
    $dir = $adminRoot . '/data';
    ensure_dir($dir);
    return $dir;
}
function account_users_file(): string {
    $primary = rtrim(account_admin_data_dir(), '/\\') . '/users.json';
    $appRoot = dirname(account_admin_data_dir(), 1); // /app
    $legacy  = rtrim($appRoot, '/\\') . '/data/users.json';
    if (!is_file($primary) && is_file($legacy)) return $legacy;
    return $primary;
}

/** Load/save + normalization */
function account_users_load(): array {
    $rows = jread(account_users_file(), []);
    $rows = is_array($rows) ? $rows : [];
    foreach ($rows as &$u) {
        $role = strtolower((string)($u['role'] ?? 'user'));
        $u['role'] = ($role === 'admin') ? 'admin' : 'user';
        $act = $u['active'] ?? true;
        $u['active'] = !in_array(strtolower((string)$act), ['0','false','no','off',''], true);
        $u['name'] = (string)($u['name'] ?? ''); // display name
    }
    unset($u);
    return $rows;
}
function account_users_save(array $rows): bool {
    return jwrite(account_users_file(), array_values($rows));
}

/** Utils */
function acc_now(): string { $dt = new DateTime('now', new DateTimeZone('UTC')); return $dt->format('c'); }
function acc_norm(string $v): string { return mb_strtolower(trim($v)); }
/** @return array{0:int,1:array<string,mixed>} */
function acc_find_current(array $users): array {
    $su = (string)($_SESSION['admin_user']  ?? '');
    $se = (string)($_SESSION['admin_email'] ?? '');
    $nu = acc_norm($su); $ne = acc_norm($se);
    foreach ($users as $i => $u) {
        $uu = acc_norm((string)($u['username'] ?? ''));
        $ue = acc_norm((string)($u['email'] ?? ''));
        if ($nu !== '' && $uu === $nu) return [$i, $u];
        if ($ne !== '' && $ne === $ue) return [$i, $u];
    }
    return [-1, []];
}
function acc_disp(string $v): string { return $v !== '' ? e($v) : '—'; }
function acc_date(?string $iso): string { $iso=(string)($iso??''); if($iso==='')return '—'; $ts=strtotime($iso); return $ts?date('Y-m-d H:i',$ts):e($iso); }
function acc_count_active_admins(array $users): int {
    $c = 0;
    foreach ($users as $u) {
        if (!empty($u['active']) && strtolower((string)($u['role'] ?? 'user')) === 'admin') { $c++; }
    }
    return $c;
}

/** State */
$notice = '';
$error  = '';
$users  = account_users_load();

/** Auto-bootstrap if there are absolutely no users */
if (!$users) {
    $uname = trim((string)($_SESSION['admin_user']  ?? 'admin'));
    $email = trim((string)($_SESSION['admin_email'] ?? ''));
    $dname = trim((string)($_SESSION['admin_name']  ?? ''));
    $role  = (!empty($_SESSION['is_admin']) || strtolower((string)($_SESSION['admin_role'] ?? ''))==='admin') ? 'admin' : 'admin';
    $users[] = [
        'id'         => 'u_' . substr(bin2hex(random_bytes(8)), 0, 12),
        'username'   => $uname !== '' ? $uname : 'admin',
        'email'      => $email,
        'name'       => $dname,
        'password'   => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT), // random
        'role'       => $role,
        'active'     => true,
        'created_at' => acc_now(),
        'updated_at' => acc_now(),
    ];
    account_users_save($users);
}

/** Manual self-create (when session lacks username/email) */
if (is_post() && ($_POST['op'] ?? '') === 'create_self_manual') {
    $tok = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_ok($tok)) {
        $error = 'Invalid CSRF token.';
    } else {
        $uname = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $name  = trim((string)($_POST['name'] ?? ''));
        $pw1   = (string)($_POST['password'] ?? '');
        $pw2   = (string)($_POST['password2'] ?? '');
        if ($uname === '' || $pw1 === '' || $pw2 === '') {
            $error = 'Username and password are required.';
        } elseif ($pw1 !== $pw2) {
            $error = 'Passwords do not match.';
        } else {
            // uniqueness
            $nu = acc_norm($uname); $ne = acc_norm($email);
            foreach ($users as $u) {
                if ($nu !== '' && acc_norm((string)($u['username'] ?? '')) === $nu) { $error='Username already exists.'; break; }
                if ($ne !== '' && $ne === acc_norm((string)($u['email'] ?? '')))     { $error='Email already exists.'; break; }
            }
            if ($error === '') {
                $role = (!empty($_SESSION['is_admin']) || strtolower((string)($_SESSION['admin_role'] ?? ''))==='admin') ? 'admin' : 'user';
                $users[] = [
                    'id'         => 'u_' . substr(bin2hex(random_bytes(8)), 0, 12),
                    'username'   => $uname,
                    'email'      => $email,
                    'name'       => $name,
                    'password'   => password_hash($pw1, PASSWORD_BCRYPT),
                    'role'       => $role,
                    'active'     => true,
                    'created_at' => acc_now(),
                    'updated_at' => acc_now(),
                ];
                if (account_users_save($users)) {
                    $_SESSION['admin_user']  = $uname;
                    $_SESSION['admin_email'] = $email;
                    $_SESSION['admin_name']  = $name;
                    if ($role === 'admin') $_SESSION['is_admin'] = true;
                    $notice = 'Your account entry has been created.';
                } else {
                    $error = 'Failed to write users.json.';
                }
            }
        }
    }
}

/** One-click self-create using session values */
if (is_post() && ($_POST['op'] ?? '') === 'create_self') {
    $tok = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_ok($tok)) {
        $error = 'Invalid CSRF token.';
    } else {
        $uname = trim((string)($_SESSION['admin_user']  ?? ''));
        $email = trim((string)($_SESSION['admin_email'] ?? ''));
        $name  = trim((string)($_SESSION['admin_name']  ?? ''));
        if ($uname === '' && $email === '') {
            $error = 'Session is missing username/email; cannot create account entry.';
        } else {
            $nu = acc_norm($uname); $ne = acc_norm($email);
            foreach ($users as $u) {
                if ($nu !== '' && acc_norm((string)($u['username'] ?? '')) === $nu) { $error='Username already exists.'; break; }
                if ($ne !== '' && $ne === acc_norm((string)($u['email'] ?? '')))     { $error='Email already exists.'; break; }
            }
            if ($error === '') {
                $role = (!empty($_SESSION['is_admin']) || strtolower((string)($_SESSION['admin_role'] ?? ''))==='admin') ? 'admin' : 'user';
                $users[] = [
                    'id'         => 'u_' . substr(bin2hex(random_bytes(8)), 0, 12),
                    'username'   => $uname !== '' ? $uname : ($email !== '' ? $email : 'user'),
                    'email'      => $email,
                    'name'       => $name,
                    'password'   => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
                    'role'       => $role,
                    'active'     => true,
                    'created_at' => acc_now(),
                    'updated_at' => acc_now(),
                ];
                if (account_users_save($users)) {
                    $notice = 'Your account entry has been created.';
                } else {
                    $error = 'Failed to write users.json.';
                }
            }
        }
    }
}

/** Reload & locate self */
$users  = account_users_load();
[$meIdx, $me] = acc_find_current($users);

/** Handle profile update (incl. display name) */
if (is_post() && ($_POST['op'] ?? '') === 'save' && $meIdx >= 0) {
    $tok = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_ok($tok)) { $error = 'Invalid CSRF token.'; }
    else {
        $newName = trim((string)($_POST['name'] ?? ''));
        $newUser = trim((string)($_POST['username'] ?? ''));
        $newMail = trim((string)($_POST['email'] ?? ''));
        $curPw   = (string)($_POST['current_password'] ?? '');
        $pw1     = (string)($_POST['new_password'] ?? '');
        $pw2     = (string)($_POST['new_password2'] ?? '');

        if ($newUser === '') { $error = 'Username is required.'; }
        else {
            // uniqueness vs others
            $myId = (string)($users[$meIdx]['id'] ?? '');
            foreach ($users as $u) {
                if (($u['id'] ?? '') === $myId) continue;
                if ($newUser !== '' && acc_norm((string)($u['username'] ?? '')) === acc_norm($newUser)) { $error='Username is already taken.'; break; }
                if ($newMail !== '' && acc_norm((string)($u['email'] ?? '')) === acc_norm($newMail))     { $error='Email is already in use.'; break; }
            }
            if ($error === '') {
                $users[$meIdx]['name']       = $newName; // display name
                $users[$meIdx]['username']   = $newUser;
                $users[$meIdx]['email']      = $newMail;
                $users[$meIdx]['updated_at'] = acc_now();

                if ($pw1 !== '' || $pw2 !== '') {
                    if ($pw1 !== $pw2) {
                        $error = 'New passwords do not match.';
                    } else {
                        $stored = (string)($users[$meIdx]['password'] ?? '');
                        if ($stored === '' || password_verify($curPw, $stored)) {
                            $users[$meIdx]['password']   = password_hash($pw1, PASSWORD_BCRYPT);
                            $users[$meIdx]['updated_at'] = acc_now();
                        } else {
                            $error = 'Current password is incorrect.';
                        }
                    }
                }

                if ($error === '') {
                    if (account_users_save($users)) {
                        $_SESSION['admin_user']  = $newUser;
                        $_SESSION['admin_email'] = $newMail;
                        $_SESSION['admin_name']  = $newName;
                        $notice = 'Account updated successfully.';
                    } else {
                        $error = 'Failed to save account changes.';
                    }
                }
            }
        }
    }
    // refresh snapshot
    $users  = account_users_load();
    [$meIdx, $me] = acc_find_current($users);
}

/** Handle close account (self-delete) */
if (is_post() && ($_POST['op'] ?? '') === 'close' && $meIdx >= 0) {
    $tok = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_ok($tok)) {
        $error = 'Invalid CSRF token.';
    } else {
        // Block if last active admin
        $isAdmin = strtolower((string)($users[$meIdx]['role'] ?? 'user')) === 'admin';
        if ($isAdmin && acc_count_active_admins($users) <= 1) {
            $error = 'You are the last active admin. Create another admin before closing this account.';
        } else {
            $pw = (string)($_POST['confirm_password'] ?? '');
            $stored = (string)($users[$meIdx]['password'] ?? '');
            if ($stored !== '' && !password_verify($pw, $stored)) {
                $error = 'Password is incorrect.';
            } else {
                array_splice($users, $meIdx, 1);
                if (account_users_save($users)) {
                    // Destroy session and redirect home
                    unset($_SESSION['admin_user'], $_SESSION['admin_email'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['is_admin']);
                    @session_regenerate_id(true);
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', [
                        'expires'  => time() - 42000,
                        'path'     => $params['path'] ?? '/',
                        'domain'   => $params['domain'] ?? '',
                        'secure'   => !empty($params['secure']),
                        'httponly' => !empty($params['httponly']),
                        'samesite' => $params['samesite'] ?? 'Lax',
                    ]);
                    @session_unset();
                    @session_destroy();
                    header('Location: /', true, 302);
                    exit;
                } else {
                    $error = 'Failed to remove account.';
                }
            }
        }
    }
    // refresh snapshot if not exited
    $users  = account_users_load();
    [$meIdx, $me] = acc_find_current($users);
}

/** View bits */
$role    = strtolower((string)($me['role'] ?? ($_SESSION['admin_role'] ?? 'user'))) === 'admin' ? 'admin' : 'user';
$active  = !empty($me['active']);
$when    = acc_date((string)($me['updated_at'] ?? ($me['created_at'] ?? '')));

$valName = $meIdx >= 0 ? (string)($me['name']     ?? '') : (string)($_SESSION['admin_name']  ?? '');
$valUser = $meIdx >= 0 ? (string)($me['username'] ?? '') : (string)($_SESSION['admin_user']  ?? '');
$valMail = $meIdx >= 0 ? (string)($me['email']    ?? '') : (string)($_SESSION['admin_email'] ?? '');
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">My Account</h1>
    <div class="small text-muted">
      Role:
      <?php if ($role === 'admin'): ?>
        <span class="badge text-bg-primary">Admin</span>
      <?php else: ?>
        <span class="badge text-bg-secondary">User</span>
      <?php endif; ?>
      · Status:
      <?php if ($active): ?>
        <span class="badge text-bg-success">Enabled</span>
      <?php else: ?>
        <span class="badge text-bg-warning text-dark">Disabled</span>
      <?php endif; ?>
      · Updated: <?= e($when) ?>
    </div>
  </div>

  <?php if ($notice !== ''): ?><div class="alert alert-success"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($error  !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <?php if ($meIdx < 0): ?>
    <div class="card">
      <div class="card-body">
        <p class="mb-3">We couldn’t find your account in <code>users.json</code>.</p>

        <?php $sessHasIdentity = (trim((string)($_SESSION['admin_user'] ?? '')) !== '') || (trim((string)($_SESSION['admin_email'] ?? '')) !== ''); ?>

        <?php if ($sessHasIdentity): ?>
          <!-- One-click creation using session identity -->
          <form method="post" action="/admin?action=account" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="create_self">
            <button class="btn btn-primary" type="submit">Create my account entry</button>
            <a class="btn btn-outline-secondary" href="/admin?action=dashboard">Cancel</a>
          </form>
          <hr>
        <?php endif; ?>

        <!-- Manual creation when session lacks username/email -->
        <p class="mb-2">Or create it manually:</p>
        <form method="post" action="/admin?action=account">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="op" value="create_self_manual">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Display Name</label>
              <input type="text" name="name" class="form-control" maxlength="120">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Username</label>
              <input type="text" name="username" class="form-control" required maxlength="100">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Email (optional)</label>
              <input type="email" name="email" class="form-control" maxlength="200">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Password</label>
              <input type="password" name="password" class="form-control" required>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Confirm Password</label>
              <input type="password" name="password2" class="form-control" required>
            </div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary" type="submit">Create Account</button>
            <a class="btn btn-outline-secondary" href="/admin?action=dashboard">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-body">
        <form method="post" action="/admin?action=account">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="op" value="save">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Display Name</label>
              <input type="text" name="name" class="form-control" maxlength="120" value="<?= e($valName) ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Username</label>
              <input type="text" name="username" class="form-control" required maxlength="100" value="<?= e($valUser) ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control" maxlength="200" value="<?= e($valMail) ?>">
            </div>
          </div>

          <hr class="my-4">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Current Password</label>
              <input type="password" name="current_password" class="form-control" placeholder="Required to change password">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">New Password</label>
              <input type="password" name="new_password" class="form-control">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Confirm New Password</label>
              <input type="password" name="new_password2" class="form-control">
            </div>
          </div>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary" type="submit">Save Changes</button>
            <a class="btn btn-outline-secondary" href="/admin?action=dashboard">Cancel</a>
          </div>
        </form>
      </div>
    </div>

    <!-- Close account -->
    <div class="card mt-3 border-danger">
      <div class="card-body">
        <div class="fw-semibold text-danger mb-2">Close Account</div>
        <p class="text-muted small mb-3">
          This will permanently remove your user account from the site. You will be signed out immediately.
          You cannot close your account if you are the last active admin.
        </p>
        <form method="post" action="/admin?action=account" onsubmit="return confirm('This will delete your account. Continue?');">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="op" value="close">
          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Confirm Password</label>
              <input type="password" name="confirm_password" class="form-control" required>
            </div>
          </div>
          <div class="mt-3">
            <button class="btn btn-outline-danger" type="submit">Delete My Account</button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

