<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/shop.php';
require_perm('barang');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['aksi'] ?? '') === 'hapus') {
        try {
            db()->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
            log_activity("Menghapus barang #$id"); header('Location: barang.php'); exit;
        } catch (PDOException $ex) { $err = 'Barang sudah pernah dipesan, tidak bisa dihapus. Isi stoknya 0 saja.'; }
    } else {
        $d = [trim($_POST['nama'] ?? ''), $_POST['satuan'] ?? '', num($_POST['harga_beli'] ?? 0), num($_POST['harga_jual'] ?? 0),
              num($_POST['stok'] ?? 0), num($_POST['promo_min'] ?? 0), num($_POST['promo_harga'] ?? 0)];
        if ($d[0] === '' || $d[3] <= 0 || !in_array($d[1], SATUAN, true)) $err = 'Nama, satuan, dan harga jual wajib diisi.';
        elseif ($d[6] > 0 && $d[5] < 1) $err = 'Isi jumlah minimal beli untuk promo.';
        elseif ($d[6] > 0 && $d[6] >= $d[3]) $err = 'Harga promo harus lebih murah dari harga jual.';
        else {
            if ($d[6] <= 0) { $d[5] = 0; $d[6] = 0; }
            if ($id) { db()->prepare('UPDATE products SET nama=?, satuan=?, harga_beli=?, harga_jual=?, stok=?, promo_min=?, promo_harga=? WHERE id=?')->execute([...$d, $id]); log_activity("Mengubah barang {$d[0]}"); }
            else { db()->prepare('INSERT INTO products (nama, satuan, harga_beli, harga_jual, stok, promo_min, promo_harga) VALUES (?,?,?,?,?,?,?)')->execute($d); log_activity("Menambah barang {$d[0]}"); }
            header('Location: barang.php'); exit;
        }
    }
}
$edit = null;
if (isset($_GET['edit'])) { $s = db()->prepare('SELECT * FROM products WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit = $s->fetch() ?: null; }
$q = trim($_GET['q'] ?? '');
$c = db()->prepare('SELECT COUNT(*) FROM products WHERE nama LIKE ?'); $c->execute(["%$q%"]);
[$page, $pages, $off, $per] = paginate((int)$c->fetchColumn());
$list = db()->prepare("SELECT * FROM products WHERE nama LIKE ? ORDER BY nama LIMIT $per OFFSET $off"); $list->execute(["%$q%"]);
page_header('Data Barang');
$nf = fn($k) => $edit && $edit[$k] ? number_format($edit[$k], 0, ',', '.') : '';
?>
<p><button type="button" class="btn" id="btnTambah" onclick="toggleForm(true)" <?= ($edit || $err) ? 'hidden' : '' ?>>Tambah barang</button></p>
<div class="card" id="formbarang" <?= ($edit || $err) ? '' : 'hidden' ?>><form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<label>Nama barang<input name="nama" value="<?= e($edit['nama'] ?? '') ?>" required></label>
<label>Satuan<select name="satuan"><?php foreach (SATUAN as $s): ?><option <?= ($edit['satuan'] ?? 'dus') === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></label>
<label>Harga beli<input class="rp" name="harga_beli" inputmode="numeric" placeholder="10.000" value="<?= $nf('harga_beli') ?>"></label>
<label>Harga jual<input class="rp" name="harga_jual" inputmode="numeric" placeholder="10.000" value="<?= $nf('harga_jual') ?>" required></label>
<label>Jumlah stok<input name="stok" type="number" min="0" value="<?= (int)($edit['stok'] ?? 0) ?>" required></label>
<label>Promo: min. beli<input name="promo_min" type="number" min="0" value="<?= $edit && $edit['promo_min'] ? (int)$edit['promo_min'] : '' ?>"></label>
<label>Promo: harga satuan<input class="rp" name="promo_harga" inputmode="numeric" placeholder="49.000" value="<?= $nf('promo_harga') ?>"></label>
<button class="btn"><?= $edit ? 'Simpan perubahan' : 'Tambah barang' ?></button><?php if ($edit): ?><a class="btn alt" href="barang.php">Batal</a><?php else: ?><button type="button" class="btn alt" onclick="toggleForm(false)">Batal</button><?php endif; ?></form>
<p class="note">Stok 0 = tampil "kosong" di pelanggan, stok 1 atau lebih = "ada". Contoh promo: min. beli 10, harga satuan 49.000, jadi 10 dus Rp 490.000 (normal 500.000).</p>
<p class="err"><?= e($err) ?></p></div>
<form method="get"><input name="q" value="<?= e($q) ?>" placeholder="Cari barang..."> <button class="btn">Cari</button></form><br>
<div class="sc"><table><tr><th>Nama<th>Satuan<th>Beli<th>Jual<th>Stok<th>Promo<th></tr>
<?php foreach ($list as $p): ?><tr><td><?= e($p['nama']) ?><td><?= e($p['satuan']) ?><td><?= rp($p['harga_beli']) ?><td><?= rp($p['harga_jual']) ?>
<td class="<?= $p['stok'] > 0 ? 'ok' : 'err' ?>"><?= (int)$p['stok'] ?><td><?= e(promo_teks($p)) ?>
<td><a class="btn alt" href="?edit=<?= $p['id'] ?>">Ubah</a>
<form method="post" style="display:inline" onsubmit="return confirm('Hapus barang ini?')"><?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn del">Hapus</button></form></tr>
<?php endforeach; ?></table></div><?= pager($page, $pages) ?>
<script>
function toggleForm(show) {
  var f = document.getElementById('formbarang'), b = document.getElementById('btnTambah');
  if (!f) return;
  f.hidden = !show; if (b) b.hidden = show;
  if (show) { f.scrollIntoView({behavior: 'smooth'}); var i = f.querySelector('input[name=nama]'); if (i) i.focus(); }
}
</script>
<?php page_footer();
