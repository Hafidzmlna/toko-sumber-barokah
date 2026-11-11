<?php
require_once __DIR__ . '/includes/layout.php';
require_login();
$m = menu_items();                       // dashboard sesuai role = menu pertama yang diizinkan
if (!$m) exit('Role ini belum punya akses menu.');
header('Location: ' . $m[0][1]);
