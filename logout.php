<?php
require_once __DIR__ . '/includes/auth.php';
// Logout hanya lewat POST + token CSRF, supaya situs lain tidak bisa memaksa pengguna keluar.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !user()) { header('Location: ' . BASE_URL . '/index.php'); exit; }
check_csrf();
log_activity('Logout');
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
