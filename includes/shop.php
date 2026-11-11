<?php
const SATUAN = ['dus', 'pak', 'sak', 'slop', 'kt', 'bal', 'tali', 'lsn', 'kg', 'st', 'pcs'];

// Harga satuan: promo berlaku untuk seluruh jumlah jika qty >= promo_min, selain itu harga normal.
function harga_satuan(array $p, int $qty): int
{
    return ($p['promo_harga'] > 0 && $qty >= max(1, (int)$p['promo_min'])) ? (int)$p['promo_harga'] : (int)$p['harga_jual'];
}
function promo_teks(array $p): string
{
    return $p['promo_harga'] > 0 ? 'Beli ' . max(1, (int)$p['promo_min']) . '+ ' . $p['satuan'] . ': ' . rp($p['promo_harga']) . '/' . $p['satuan'] : '-';
}
// Isi keranjang kasir ($_SESSION['cart'] = [id_barang => qty]) dengan data & harga terbaru dari database.
function cart_lines(): array
{
    $c = $_SESSION['cart'] ?? [];
    if (!$c) return [];
    $s = db()->prepare('SELECT * FROM products WHERE id IN (' . implode(',', array_fill(0, count($c), '?')) . ')');
    $s->execute(array_map('intval', array_keys($c)));
    $out = [];
    foreach ($s as $p) {
        $q = (int)$c[$p['id']];
        $out[] = ['p' => $p, 'qty' => $q, 'harga' => harga_satuan($p, $q)];
    }
    return $out;
}

// Hitung ulang harga semua item pesanan dari data barang terbaru (promo bergantung pada jumlah).
function recalc_order(int $oid): void
{
    $s = db()->prepare('SELECT oi.id AS item_id, oi.qty, p.harga_jual, p.promo_min, p.promo_harga, p.harga_beli
                        FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
    $s->execute([$oid]);
    $up = db()->prepare('UPDATE order_items SET harga_satuan = ?, harga_beli = ? WHERE id = ?');
    foreach ($s as $r) $up->execute([harga_satuan($r, (int)$r['qty']), $r['harga_beli'], $r['item_id']]);
}

function num($v): int
{
    return (int)preg_replace('/\D/', '', (string)$v);
}   // "10.000" -> 10000
function setting(string $k, $d = '')
{
    $s = db()->prepare('SELECT v FROM settings WHERE k=?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? $d : $v;
}
function qris_html(): string
{
    $f = setting('qris_image');
    if (!$f) return '<p class="err">QRIS toko belum diatur. Hubungi kasir.</p>';
    return '<img class="qris" alt="QRIS Toko Sumber Barokah" src="' . BASE_URL . '/uploads/' . e($f) . '?v=' . (int)@filemtime(__DIR__ . '/../uploads/' . $f) . '">';
}
// Kurangi stok secara atomik; false jika stok tidak cukup.
function kurangi_stok(PDO $pdo, int $pid, int $qty): bool
{
    $s = $pdo->prepare('UPDATE products SET stok = stok - ? WHERE id = ? AND stok >= ?');
    $s->execute([$qty, $pid, $qty]);
    return $s->rowCount() === 1;
}
