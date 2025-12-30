<?php
/**
 * Admin » Users (list + delete)
 *
 * Storage: /app/admin/data/users.json (relative to this view)
 * - Primary line: Display Name (fallback to username/email)
 * - Secondary: @username + email (muted)
 * - Badges: Role, Status, Subscription
 * - Actions: Delete (not shown for the currently logged-in user)
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/**
 * HTML escape wrapper (uses global e() if present).
 *
 * @param string $s
 * @return string
 */
function esc_html(string $s): string
{
    if (function_exists('e')) {
        return e($s);
    }
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Absolute path to users.json in the admin context.
 *
 * @return string
 */
function admin_users_file(): string
{
    $adminDir = dirname(__DIR__, 1); // /app/admin
    return rtrim($adminDir, '/\\') . '/data/users.json';
}

/**
 * Read users.json and normalize to a list of associative arrays.
 *
 * @param string $file
 * @return array<int, array<string, mixed>>
 */
function admin_users_load_all(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = (string) @file_get_contents($file);
    if ($raw === '') {
        return [];
    }
    // strip BOM
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
        $raw = substr($raw, 3);
    }

    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return [];
    }

    // Accept: list | {"users":[...]} | {"items":[...]} | map
    if (array_is_list($j)) {
        $rows = $j;
    } elseif (isset($j['users']) && is_array($j['users'])) {
        $rows = $j['users'];
    } elseif (isset($j['items']) && is_array($j['items'])) {
        $rows = $j['items'];
    } else {
        $rows = array_values($j);
    }

    foreach ($rows as &$u) {
        if (!is_array($u)) {
            $u = [];
        }
        $u['id']         = (string) ($u['id'] ?? '');
        $u['name']       = (string) ($u['name'] ?? '');
        $u['username']   = (string) ($u['username'] ?? '');
        $u['email']      = (string) ($u['email'] ?? '');
        $u['role']       = strtolower((string) ($u['role'] ?? 'user')) === 'admin' ? 'admin' : 'user';
        $act             = (string) ($u['active'] ?? 'true');
        $u['active']     = !in_array(strtolower($act), ['0', 'false', 'no', 'off', ''], true);
        $u['subscribed'] = array_key_exists('subscribed', $u) ? $u['subscribed'] : true;
        $u['updated_at'] = (string) ($u['updated_at'] ?? ($u['created_at'] ?? ''));
    }
    unset($u);

    // Sort: admins first, then by name, then by username
    usort($rows, static function (array $a, array $b): int {
        $ra = ($a['role'] ?? 'user') === 'admin' ? 0 : 1;
        $rb = ($b['role'] ?? 'user') === 'admin' ? 0 : 1;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $na = mb_strtolower((string) ($a['name'] ?? ''));
        $nb = mb_strtolower((string) ($b['name'] ?? ''));
        if ($na !== '' || $nb !== '') {
            $cmp = $na <=> $nb;
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return mb_strtolower((string) ($a['username'] ?? '')) <=> mb_strtolower((string) ($b['username'] ?? ''));
    });

    return $rows;
}

/**
 * Write list of users back to users.json (pretty-printed array).
 *
 * @param string                                $file
 * @param array<int, array<string, mixed>>      $rows
 * @return bool
 */
function admin_users_write_all(string $file, array $rows): bool
{
    $enc = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($enc === false) {
        return false;
    }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $enc) === false) {
        return false;
    }
    return @rename($tmp, $file);
}

/**
 * CSRF helpers (scoped to admin/users page).
 *
 * @return string
 */
function admin_users_csrf_token(): string
{
    if (empty($_SESSION['csrf_admin_users'])) {
        $_SESSION['csrf_admin_users'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['csrf_admin_users'];
}

/**
 * @param string $t
 * @return bool
 */
function admin_users_csrf_ok(string $t): bool
{
    return isset($_SESSION['csrf_admin_users']) && hash_equals((string) $_SESSION['csrf_admin_users'], $t);
}

/**
 * Determine if the given user row refers to the current session user.
 *
 * @param array<string,mixed> $u
 * @return bool
 */
function admin_users_is_me(array $u): bool
{
    $su = mb_strtolower(trim((string) ($_SESSION['admin_user']  ?? '')));
    $se = mb_strtolower(trim((string) ($_SESSION['admin_email'] ?? '')));
    $uu = mb_strtolower(trim((string) ($u['username'] ?? '')));
    $ue = mb_strtolower(trim((string) ($u['email']    ?? '')));
    if ($su !== '' && $uu !== '' && $su === $uu) {
        return true;
    }
    if ($se !== '' && $ue !== '' && $se === $ue) {
        return true;
    }
    return false;
}

/** ------------------------------------------------------------------
 * Handle POST: delete user (except yourself)
 * ----------------------------------------------------------------- */
$notice = '';
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['op'] ?? '') === 'delete_user') {
    $token = (string) ($_POST['csrf'] ?? '');
    $key   = trim((string) ($_POST['key'] ?? '')); // we accept username or email or id

    if (!admin_users_csrf_ok($token)) {
        $error = 'Invalid CSRF token.';
    } else {
        $file  = admin_users_file();
        $rows  = admin_users_load_all($file);
        $found = -1;

        foreach ($rows as $i => $u) {
            $id = (string) ($u['id'] ?? '');
            $un = mb_strtolower((string) ($u['username'] ?? ''));
            $em = mb_strtolower((string) ($u['email'] ?? ''));
            $k  = mb_strtolower($key);

            if ($k !== '' && ($k === $id || $k === $un || $k === $em)) {
                // Disallow deleting yourself
                if (admin_users_is_me($u)) {
                    $error = 'You cannot delete your own account.';
                    $found = -2;
                    break;
                }
                $found = $i;
                break;
            }
        }

        if ($found >= 0) {
            array_splice($rows, $found, 1);
            if (admin_users_write_all($file, $rows)) {
                $notice = 'User deleted.';
            } else {
                $error = 'Failed to write users file.';
            }
        } elseif ($found === -1 && $error === '') {
            $error = 'User not found.';
        }
    }
}

/** Load table data for rendering */
$USERS = admin_users_load_all(admin_users_file());
?>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 mb-0">Users</h1>
    <a class="btn btn-sm btn-outline-secondary" href="/admin?action=account">My account</a>
  </div>

  <?php if ($notice !== ''): ?>
    <div class="alert alert-success"><?= esc_html($notice) ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= esc_html($error) ?></div>
  <?php endif; ?>

  <?php if (!$USERS): ?>
    <div class="alert alert-info">No users found.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th style="width:38%">User</th>
            <th>Role</th>
            <th>Status</th>
            <th>Subscription</th>
            <th>Updated</th>
            <th style="width:12%"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($USERS as $u): ?>
          <?php
            $name     = (string) ($u['name'] ?? '');
            $username = (string) ($u['username'] ?? '');
            $email    = (string) ($u['email'] ?? '');
            $role     = (string) ($u['role'] ?? 'user');
            $active   = !empty($u['active']);
            $subbed   = ($u['subscribed'] !== false);
            $whenIso  = (string) ($u['updated_at'] ?? '');
            $whenTxt  = $whenIso !== '' ? date('Y-m-d H:i', (int) (strtotime($whenIso) ?: time())) : '—';
            $primary  = $name !== '' ? $name : ($username !== '' ? $username : ($email !== '' ? $email : '—'));
            $isMe     = admin_users_is_me($u);
            $keyPref  = (string) ($u['id'] ?? '');
            if ($keyPref === '') { $keyPref = $username !== '' ? $username : $email; }
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= esc_html($primary) ?></div>
              <div class="text-muted small">
                <?php if ($username !== ''): ?>
                  <span>@<?= esc_html($username) ?></span>
                <?php endif; ?>
                <?php if ($email !== ''): ?>
                  <span class="ms-2"><?= esc_html($email) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <?= $role === 'admin'
                ? '<span class="badge text-bg-primary">Admin</span>'
                : '<span class="badge text-bg-secondary">User</span>' ?>
            </td>
            <td>
              <?= $active
                ? '<span class="badge text-bg-success">Enabled</span>'
                : '<span class="badge text-bg-warning text-dark">Disabled</span>' ?>
            </td>
            <td>
              <?= $subbed
                ? '<span class="badge text-bg-info">Subscribed</span>'
                : '<span class="badge text-bg-light text-dark">Unsubscribed</span>' ?>
            </td>
            <td class="text-muted small"><?= esc_html($whenTxt) ?></td>
            <td class="text-end">
              <?php if (!$isMe): ?>
                <form method="post" action="/admin?action=users"
                      onsubmit="return confirm('Delete this user? This cannot be undone.');"
                      class="d-inline">
                  <input type="hidden" name="csrf" value="<?= esc_html(admin_users_csrf_token()) ?>">
                  <input type="hidden" name="op" value="delete_user">
                  <input type="hidden" name="key" value="<?= esc_html((string) $keyPref) ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              <?php else: ?>
                <span class="text-muted small">This is you</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

