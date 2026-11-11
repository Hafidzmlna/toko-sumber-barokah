<?php
require_once __DIR__ . '/security.php';
ini_set('session.use_strict_mode', '1');      // tolak session ID buatan penyerang
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure'   => is_https(),                 // aktif otomatis saat diakses lewat HTTPS (hosting / tunnel)
    'httponly' => true,                       // tidak bisa dibaca JavaScript
    'samesite' => 'Lax',                      // cookie tidak ikut permintaan lintas situs
]);
session_start();
require_once __DIR__ . '/db.php';

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function rp($n)
{
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}
function user()
{
    return $_SESSION['user'] ?? null;
}
function require_login()
{
    if (!user()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function can($perm)
{
    $u = user();
    if (!$u) return false;
    static $cache = [];
    if (!isset($cache[$u['role']])) {
        $s = db()->prepare('SELECT permission FROM role_permissions WHERE role=?');
        $s->execute([$u['role']]);
        $cache[$u['role']] = $s->fetchAll(PDO::FETCH_COLUMN);
    }
    return in_array($perm, $cache[$u['role']], true);
}
function require_perm($perm)
{
    require_login();
    if (!can($perm)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
}
function log_activity($aksi)
{
    $u = user();
    db()->prepare('INSERT INTO activity_logs (user_id, username, aksi) VALUES (?,?,?)')
        ->execute([$u['id'] ?? null, $u['username'] ?? '-', $aksi]);
}
function csrf_field()
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}
function check_csrf()
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419);
        exit('Token tidak valid. Muat ulang halaman.');
    }
}
function paginate($total, $per = 10, $key = 'page')
{
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, (int)($_GET[$key] ?? 1)), $pages);
    return [$page, $pages, ($page - 1) * $per, $per];
}
function pager($page, $pages, $key = 'page')
{
    if ($pages < 2) return '';
    $link = function ($n) use ($key) {
        $q = $_GET;
        $q[$key] = $n;
        return '?' . http_build_query($q);
    };
    $h = '<div class="pager">';
    if ($page > 1) $h .= '<a class="btn alt" href="' . e($link($page - 1)) . '">Sebelumnya</a>';
    $h .= '<span>Halaman ' . $page . ' dari ' . $pages . '</span>';
    if ($page < $pages) $h .= '<a class="btn alt" href="' . e($link($page + 1)) . '">Berikutnya</a>';
    return $h . '</div>';
}
