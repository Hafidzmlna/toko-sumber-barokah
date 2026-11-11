<?php
require_once __DIR__ . '/includes/auth.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $un = trim($_POST['username'] ?? ''); $pw = $_POST['password'] ?? ''; $ip = client_ip();
    if (login_blocked($un, $ip)) {
        usleep(random_int(300000, 600000));
        log_security("Login diblokir sementara (terlalu banyak percobaan) dari IP $ip", $un);
        $err = 'Terlalu banyak percobaan login. Coba lagi dalam ' . LOGIN_WINDOW_MIN . ' menit.';
    } else {
        $s = db()->prepare('SELECT * FROM users WHERE username=?'); $s->execute([$un]); $u = $s->fetch();
        if ($u) $ok = password_verify($pw, $u['password']);
        else { password_hash($pw, PASSWORD_DEFAULT); $ok = false; }    // waktu proses sama, agar akun yang ada/tidak ada tidak bisa dibedakan
        if ($ok) {
            login_success($un);
            $csrf = $_SESSION['csrf'];
            session_regenerate_id(true);
            $_SESSION['csrf'] = $csrf;
            $_SESSION['user'] = ['id' => $u['id'], 'nama' => $u['nama'], 'username' => $u['username'], 'role' => $u['role']];
            log_activity('Login');
            header('Location: ' . BASE_URL . '/index.php'); exit;
        }
        login_failed($un, $ip);
        log_security("Login gagal dari IP $ip", $un);
        usleep(random_int(300000, 600000));                          // jeda kecil di setiap kegagalan
        $err = 'Username atau password salah.';                      // pesan sama untuk semua kasus
    }
}
?><!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk - Toko Sumber Barokah</title><link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>"></head>
<body><form class="login" method="post"><h1>Toko Sumber Barokah</h1><p class="note">Masuk untuk melanjutkan.</p>
<?= csrf_field() ?><input name="username" placeholder="Username" required autofocus autocomplete="username">
<input name="password" type="password" placeholder="Password" required autocomplete="current-password">
<button class="btn">Masuk</button><p class="err"><?= e($err) ?></p>
<p class="note" style="text-align:center">&copy; 2026 Toko Sumber Barokah</p></form></body></html>
