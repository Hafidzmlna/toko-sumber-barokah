<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/shop.php';
require_perm('pos');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'tambah') {
        $s = db()->prepare('SELECT * FROM products WHERE id=?');
        $s->execute([(int)($_POST['product_id'] ?? 0)]);
        $p = $s->fetch();
        $qty = (int)($_POST['qty'] ?? 0);
        if (!$p || $qty < 1) $err = 'Pilih barang dan isi jumlah minimal 1.';
        elseif ($p['stok'] <= 0) $err = 'Stok barang kosong, tidak bisa ditambahkan.';
        elseif (($_SESSION['cart'][$p['id']] ?? 0) + $qty > $p['stok']) $err = "Stok {$p['nama']} tersisa {$p['stok']} {$p['satuan']}.";
        else {
            $_SESSION['cart'][$p['id']] = ($_SESSION['cart'][$p['id']] ?? 0) + $qty;
            header('Location: kasir.php');
            exit;
        }
    } elseif ($aksi === 'hapus') {
        unset($_SESSION['cart'][(int)($_POST['product_id'] ?? 0)]);
        header('Location: kasir.php');
        exit;
    } elseif ($aksi === 'bayar') {
        $lines = cart_lines();
        $total = 0;
        foreach ($lines as $l) $total += $l['qty'] * $l['harga'];
        $metode = ($_POST['metode'] ?? '') === 'qris' ? 'qris' : 'tunai';
        $bayar = $metode === 'qris' ? $total : num($_POST['bayar'] ?? 0);
        if (!$lines) $err = 'Keranjang kosong.';
        elseif ($bayar < $total) $err = 'Uang pelanggan kurang dari total belanja.';
        else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                foreach ($lines as $l) if (!kurangi_stok($pdo, (int)$l['p']['id'], $l['qty']))
                    throw new RuntimeException("Stok {$l['p']['nama']} tidak cukup (tersisa {$l['p']['stok']}).");
                $kode = 'K' . date('ymdHis') . random_int(10, 99);
                $pdo->prepare("INSERT INTO sales (kode, sumber, metode, kasir_id, total, bayar, kembali) VALUES (?, 'offline', ?, ?, ?, ?, ?)")
                    ->execute([$kode, $metode, user()['id'], $total, $bayar, $bayar - $total]);
                $sid = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare('INSERT INTO sale_items (sale_id, nama_barang, satuan, qty, harga_satuan, harga_beli) VALUES (?,?,?,?,?,?)');
                foreach ($lines as $l) $ins->execute([$sid, $l['p']['nama'], $l['p']['satuan'], $l['qty'], $l['harga'], $l['p']['harga_beli']]);
                $pdo->commit();
                $_SESSION['cart'] = [];
                log_activity("Penjualan offline $kode ($metode) total " . rp($total));
                header('Location: ' . BASE_URL . '/nota.php?id=' . $sid . '&print=1');
                exit;
            } catch (RuntimeException $ex) {
                $pdo->rollBack();
                $err = $ex->getMessage();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $err = 'Transaksi gagal disimpan. Coba lagi.';
            }
        }
    }
}
$q = trim($_GET['q'] ?? '');
$o = db()->prepare("SELECT * FROM products WHERE stok > 0 AND nama LIKE ? ORDER BY nama LIMIT 100");
$o->execute(["%$q%"]);
$lines = cart_lines();
$total = 0;
foreach ($lines as $l) $total += $l['qty'] * $l['harga'];
page_header('Kasir (Penjualan Offline)');
?>
<p class="err"><?= e($err) ?></p>
<div class="card">
    <form method="get" class="row"><label>Cari barang<input name="q" value="<?= e($q) ?>" placeholder="Ketik nama barang..." autofocus></label><button class="btn alt">Cari</button></form><br>
    <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="aksi" value="tambah">
        <label>Barang (stok tersedia)<select name="product_id"><?php foreach ($o as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['nama']) ?> (<?= e($p['satuan']) ?>) - <?= rp($p['harga_jual']) ?> | stok <?= (int)$p['stok'] ?><?= $p['promo_harga'] > 0 ? ' | ' . e(promo_teks($p)) : '' ?></option><?php endforeach; ?></select></label>
        <label>Jumlah<input name="qty" type="number" min="1" value="1" style="width:90px"></label><button class="btn">Tambah</button>
    </form>
</div>
<?php if ($lines): ?>
    <div class="sc">
        <table>
            <tr>
                <th>Barang
                <th>Jumlah
                <th>Harga
                <th>Subtotal
                <th>
            </tr>
            <?php foreach ($lines as $l): $p = $l['p']; ?><tr>
                    <td><?= e($p['nama']) ?><?= $p['stok'] < $l['qty'] ? ' <span class="err">(stok kurang, tersisa ' . (int)$p['stok'] . ')</span>' : '' ?>
                    <td><?= $l['qty'] ?> <?= e($p['satuan']) ?>
                    <td><?= rp($l['harga']) ?><?= $l['harga'] < $p['harga_jual'] ? ' <span class="tag">promo</span>' : '' ?>
                    <td><?= rp($l['qty'] * $l['harga']) ?>
                    <td>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="hapus"><input type="hidden" name="product_id" value="<?= $p['id'] ?>"><button class="btn del">Hapus</button></form>
                </tr>
            <?php endforeach; ?><tr>
                <th colspan="3">Total belanja
                <th colspan="2"><?= rp($total) ?>
            </tr>
        </table>
    </div>
    <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="aksi" value="bayar">
        <b>Metode pembayaran:</b> <label><input type="radio" name="metode" value="tunai" checked> Tunai</label> &nbsp; <label><input type="radio" name="metode" value="qris"> QRIS</label><br><br>
        <div id="tunai">
            <div class="row"><b>Uang dari pelanggan:</b>
                <button type="button" class="btn alt" onclick="setBayar(<?= $total ?>)">Uang pas</button>
                <?php foreach ([10000, 50000, 100000, 500000] as $n): ?><button type="button" class="btn alt" onclick="setBayar(<?= $n ?>)"><?= rp($n) ?></button><?php endforeach; ?>
            </div><br>
            <div class="row"><label>Isi uang lain<input class="rp" id="bayar" name="bayar" inputmode="numeric" placeholder="100.000" oninput="hit()"></label>
                <div>Kembalian<b id="kb" style="display:block;font-size:20px">-</b></div>
            </div>
        </div>
        <div id="qrisbox" hidden style="text-align:center">
            <p>Total <b><?= rp($total) ?></b>. Minta pelanggan scan QRIS di bawah ini, dan pastikan pembayaran sudah masuk sebelum menekan tombol.</p><?= qris_html() ?>
        </div>
        <br><button class="btn">Bayar &amp; cetak nota</button>
    </form>
    <script>
        const total = <?= (int)$total ?>,
            by = document.getElementById('bayar'),
            kb = document.getElementById('kb');
        const f = n => 'Rp ' + n.toLocaleString('id-ID'),
            dig = s => +(String(s).replace(/\D/g, '')) || 0;

        function setBayar(v) {
            by.value = v.toLocaleString('id-ID');
            hit();
        }

        function hit() {
            const k = dig(by.value) - total;
            kb.textContent = by.value === '' ? '-' : (k < 0 ? 'Uang kurang ' + f(-k) : f(k));
        }

        function pilih() {
            const q = document.querySelector('input[name=metode]:checked').value === 'qris';
            document.getElementById('tunai').hidden = q;
            document.getElementById('qrisbox').hidden = !q;
        }
        document.querySelectorAll('input[name=metode]').forEach(r => r.addEventListener('change', pilih));
        pilih();
    </script>
<?php else: ?><p class="note">Keranjang kosong. Cari barang lalu klik Tambah.</p><?php endif; ?>
<?php page_footer();
