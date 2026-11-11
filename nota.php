<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/shop.php';
require_login();
function tolak() { http_response_code(403); exit('Akses ditolak.'); }
if (!(can('pos') || can('laporan') || can('orders'))) tolak();
if (isset($_GET['order'])) {          // nota pesanan online: hanya yang berhak mengelola pesanan masuk
    if (!can('orders')) tolak();
    $oid = (int)$_GET['order'];
    $s = db()->prepare('SELECT o.id, o.status, o.created_at, u.nama AS pelanggan FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');
    $s->execute([$oid]); $o = $s->fetch();
    if (!$o) exit('Pesanan tidak ditemukan.');
    if ($o['status'] === 'keranjang') exit('Pesanan belum di-checkout.');
    $it = db()->prepare('SELECT p.nama AS nama_barang, p.satuan, oi.qty, oi.harga_satuan FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
    $it->execute([$oid]); $items = $it->fetchAll(); $total = 0;
    foreach ($items as $i) $total += $i['qty'] * $i['harga_satuan'];
    $n = ['kode' => 'P' . $oid, 'created_at' => $o['created_at'], 'kasir' => user()['nama'], 'pelanggan' => $o['pelanggan'], 'total' => $total, 'bayar' => null, 'kembali' => null];
} else {                              // nota transaksi: offline untuk kasir/laporan, online untuk laporan/pesanan masuk
    $s = db()->prepare('SELECT s.*, u.nama AS kasir FROM sales s LEFT JOIN users u ON u.id = s.kasir_id WHERE s.id=?');
    $s->execute([(int)($_GET['id'] ?? 0)]); $n = $s->fetch();
    if (!$n) exit('Nota tidak ditemukan.');
    $boleh = $n['sumber'] === 'offline' ? (can('pos') || can('laporan')) : (can('laporan') || can('orders'));
    if (!$boleh) tolak();
    $it = db()->prepare('SELECT * FROM sale_items WHERE sale_id=?'); $it->execute([$n['id']]); $items = $it->fetchAll();
}
?><!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nota <?= e($n['kode']) ?></title>
<style>body{font:14px/1.5 monospace;max-width:320px;margin:16px auto;padding:0 12px}hr{border:0;border-top:1px dashed #000}
button{font:inherit;padding:10px 16px;margin-top:12px}@media print{button{display:none}}</style></head><body>
<b>TOKO SUMBER BAROKAH</b><br>Nota <?= e($n['kode']) ?><br><?= e(date('d/m/Y H:i', strtotime($n['created_at']))) ?><br>Kasir: <?= e($n['kasir'] ?? '-') ?>
<?= $n['pelanggan'] ? '<br>Pelanggan: ' . e($n['pelanggan']) : '' ?><hr>
<?php foreach ($items as $i): ?><?= e($i['nama_barang']) ?><br>&nbsp;<?= $i['qty'] ?> <?= e($i['satuan']) ?> x <?= rp($i['harga_satuan']) ?> = <?= rp($i['qty'] * $i['harga_satuan']) ?><br><?php endforeach; ?>
<hr><b>TOTAL <?= rp($n['total']) ?></b><br>Metode: <?= ($n['metode'] ?? 'qris') === 'qris' ? 'QRIS' : 'Tunai' ?>
<?php if ($n['bayar'] !== null): ?><br>Bayar <?= rp($n['bayar']) ?><br>Kembali <?= rp($n['kembali']) ?><?php endif; ?><br>Terima kasih
<br><button onclick="window.print()">Cetak nota</button> <button onclick="history.back()">Kembali</button>
<?php if (isset($_GET['print'])): ?><script>window.onload = () => window.print();</script><?php endif; ?></body></html>
