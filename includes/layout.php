<?php
require_once __DIR__ . '/auth.php';
// izin => [label, file]. Menu hanya tampil jika role punya izin DAN filenya ada.
const MENU = [
    'katalog' => ['Daftar Harga', 'pelanggan/katalog.php'],
    'pesan'   => ['Pesanan Saya', 'pelanggan/pesanan.php'],
    'barang'  => ['Data Barang', 'kasir/barang.php'],
    'orders'  => ['Pesanan Masuk', 'kasir/pesanan_masuk.php'],
    'pos'     => ['Kasir', 'kasir/kasir.php'],
    'laporan' => ['Laporan', 'kasir/laporan.php'],
    'qris'    => ['Pengaturan QRIS', 'kasir/qris.php'],
    'users'   => ['User', 'admin/users.php'],
    'roles'   => ['Role', 'admin/roles.php'],
    'log'     => ['Log Aktivitas', 'admin/log.php'],
];
function menu_items() {
    $out = [];
    foreach (MENU as $perm => [$label, $file]) {
        if (can($perm) && file_exists(__DIR__ . '/../' . $file)) $out[] = [$label, BASE_URL . '/' . $file, $file];
    }
    return $out;
}
function page_header($title) {
    require_login();
    $u = user(); $self = ltrim(str_replace(BASE_URL, '', $_SERVER['SCRIPT_NAME']), '/');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($title) . ' - Toko Sumber Barokah</title><link rel="stylesheet" href="' . BASE_URL . '/assets/style.css?v=' . @filemtime(__DIR__ . '/../assets/style.css') . '">'
       . '<script src="' . BASE_URL . '/assets/app.js?v=' . @filemtime(__DIR__ . '/../assets/app.js') . '" defer></script></head><body>'
       . '<header class="top"><button type="button" class="burger" aria-label="Buka menu" aria-expanded="false" aria-controls="side" onclick="toggleMenu()">&#9776;</button>'
       . '<div class="brand"><b>Toko Sumber Barokah</b><small>Dashboard ' . e($u['role']) . ' &middot; ' . e($u['nama']) . '</small></div>'
       . '<form method="post" action="' . BASE_URL . '/logout.php" style="margin:0">' . csrf_field() . '<button class="btn alt">Keluar</button></form></header>'
       . '<div class="layout"><aside class="side" id="side"><button type="button" class="burger sideclose" aria-label="Tutup menu" onclick="toggleMenu(false)">&times;</button><nav>';
    foreach (menu_items() as [$label, $url, $file]) echo '<a class="' . ($file === $self ? 'on' : '') . '" href="' . $url . '">' . e($label) . '</a>';
    echo '</nav></aside><div class="backdrop" id="backdrop" onclick="toggleMenu(false)"></div><div class="content"><main><h2>' . e($title) . '</h2>';
}
function page_footer() {
    echo '</main><footer class="foot">&copy; 2026 Toko Sumber Barokah</footer></div></div></body></html>';
}
