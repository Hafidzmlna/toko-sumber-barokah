<?php
require_once __DIR__ . '/db.php';

const LOGIN_MAX_PER_USER = 5;    // gagal per username dalam jendela waktu
const LOGIN_MAX_PER_IP   = 15;   // gagal per alamat IP
const LOGIN_WINDOW_MIN   = 15;   // menit

function is_loopback(): bool { return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true); }

// Header proxy (Cloudflare Tunnel) hanya dipercaya jika permintaan datang dari proxy lokal di server ini.
function client_ip(): string {
    if (is_loopback()) {
        $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
        if (filter_var($cf, FILTER_VALIDATE_IP)) return $cf;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    return is_loopback() && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
function login_key(string $un): string { return mb_strtolower(mb_substr(trim($un), 0, 50)); }

function login_blocked(string $un, string $ip): bool {
    $s = db()->prepare('SELECT COALESCE(SUM(username = ?),0) AS u, COALESCE(SUM(ip = ?),0) AS i FROM login_attempts
                        WHERE created_at > NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE');
    $s->execute([login_key($un), $ip]); $r = $s->fetch();
    return (int)$r['u'] >= LOGIN_MAX_PER_USER || (int)$r['i'] >= LOGIN_MAX_PER_IP;
}
function login_failed(string $un, string $ip): void {
    db()->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)')->execute([$ip, login_key($un)]);
    if (random_int(1, 20) === 1) db()->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
}
function login_success(string $un): void {   // hanya reset hitungan username (bukan IP, supaya IP penyerang tidak bisa di-reset)
    db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([login_key($un)]);
}
function log_security(string $aksi, string $un = '-'): void {   // catatan percobaan mencurigakan, tampil di Log Aktivitas admin
    db()->prepare('INSERT INTO activity_logs (user_id, username, aksi) VALUES (NULL, ?, ?)')
        ->execute([mb_substr($un !== '' ? $un : '-', 0, 50), mb_substr($aksi, 0, 255)]);
}
