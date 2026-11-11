<?php
require_once __DIR__ . '/../includes/layout.php';
require_perm('katalog');
$q = trim($_GET['q'] ?? '');
$c = db()->prepare('SELECT COUNT(*) FROM products WHERE nama LIKE ?');
$c->execute(["%$q%"]);
[$page, $pages, $off, $per] = paginate((int)$c->fetchColumn());
$s = db()->prepare("SELECT * FROM products WHERE nama LIKE ? ORDER BY nama LIMIT $per OFFSET $off");
$s->execute(["%$q%"]);
page_header('Daftar Harga');
?>
<form method="get"><input name="q" value="<?= e($q) ?>" placeholder="Cari barang..."> <button class="btn">Cari</button></form><br>
<div class="sc">
    <table>
        <tr>
            <th>Nama barang
            <th>Satuan
            <th>Harga jual
            <th>Stok
            <th>Promo
        </tr>
        <?php foreach ($s as $p): ?>
            <tr>
                <td><?= e($p['nama']) ?>
                <td><?= e($p['satuan']) ?>
                <td><?= rp($p['harga_jual']) ?>
                <td class="<?= $p['stok'] > 0 ? 'ok' : 'err' ?>"><?= $p['stok'] > 0 ? 'ada' : 'kosong' ?>
                <td><?= $p['promo_harga'] > 0 ? '<span class="tag">Beli ' . (int)$p['promo_min'] . '+ ' . e($p['satuan']) . ': ' . rp($p['promo_harga']) . '</span>' : '-' ?>
            </tr>
        <?php endforeach; ?>
    </table>
</div>
<?= pager($page, $pages) ?>
<?php page_footer();
