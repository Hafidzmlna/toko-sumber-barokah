<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/shop.php';
require_perm('pesan');
$uid = (int)user()['id'];
$err = '';
// keranjang hari sebelumnya (belum checkout, stok belum dikurangi) dibersihkan otomatis
db()->prepare("DELETE FROM orders WHERE user_id=? AND status='keranjang' AND DATE(created_at) < CURDATE()")->execute([$uid]);
$tampil = "(DATE(COALESCE(checkout_at, created_at)) = CURDATE() OR status NOT IN ('keranjang','dikirim'))";   // hari ini + yang belum selesai
function keranjang(int $uid)
{
    $s = db()->prepare("SELECT * FROM orders WHERE user_id=? AND status='keranjang' ORDER BY id DESC LIMIT 1");
    $s->execute([$uid]);
    return $s->fetch() ?: null;
}
function pesanan_baru_id(int $uid)
{   // sudah checkout tapi belum diproses kasir
    $s = db()->prepare("SELECT id FROM orders WHERE user_id=? AND status='baru' LIMIT 1");
    $s->execute([$uid]);
    return $s->fetchColumn();
}
function item_keranjang(int $itemId, int $uid)
{   // hanya item di keranjang (belum checkout) yang boleh diubah
    $s = db()->prepare("SELECT oi.id, oi.order_id, oi.qty, p.stok, p.nama, p.satuan FROM order_items oi
        JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id
        WHERE oi.id=? AND o.user_id=? AND o.status='keranjang'");
    $s->execute([$itemId, $uid]);
    return $s->fetch() ?: null;
}
function order_total(int $oid): int
{
    $s = db()->prepare('SELECT COALESCE(SUM(qty * harga_satuan),0) FROM order_items WHERE order_id=?');
    $s->execute([$oid]);
    return (int)$s->fetchColumn();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'tambah') {
        $s = db()->prepare('SELECT * FROM products WHERE id=?');
        $s->execute([(int)($_POST['product_id'] ?? 0)]);
        $p = $s->fetch();
        $qty = (int)($_POST['qty'] ?? 0);
        if (!$p || $qty < 1) $err = 'Jumlah minimal 1.';
        elseif ($p['stok'] <= 0) $err = 'Barang sedang kosong.';
        else {
            $o = keranjang($uid);
            if (!$o && ($bid = pesanan_baru_id($uid))) $err = "Pesanan #$bid sedang menunggu diproses kasir. Pesanan baru bisa dibuat setelah statusnya akan dikirim.";
            else {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    if ($o) $oid = (int)$o['id'];
                    else {
                        $pdo->prepare('INSERT INTO orders (user_id) VALUES (?)')->execute([$uid]);
                        $oid = (int)$pdo->lastInsertId();
                        log_activity("Membuat keranjang #$oid");
                    }
                    $x = $pdo->prepare('SELECT id, qty FROM order_items WHERE order_id=? AND product_id=?');
                    $x->execute([$oid, $p['id']]);
                    $row = $x->fetch();
                    if ((int)($row['qty'] ?? 0) + $qty > $p['stok']) throw new RuntimeException("Stok {$p['nama']} tersisa {$p['stok']} {$p['satuan']}.");
                    if ($row) $pdo->prepare('UPDATE order_items SET qty = qty + ? WHERE id=?')->execute([$qty, $row['id']]);
                    else $pdo->prepare('INSERT INTO order_items (order_id, product_id, qty, harga_satuan, harga_beli) VALUES (?,?,?,?,?)')->execute([$oid, $p['id'], $qty, $p['harga_jual'], $p['harga_beli']]);
                    $pdo->commit();
                    recalc_order($oid);
                    log_activity("Menambah $qty {$p['satuan']} {$p['nama']} ke keranjang #$oid");
                    $_SESSION['flash'] = "$qty {$p['satuan']} {$p['nama']} ditambahkan ke pesanan #$oid.";
                    header('Location: pesanan.php?tambah=1');
                    exit;
                } catch (RuntimeException $ex) {
                    $pdo->rollBack();
                    $err = $ex->getMessage();
                } catch (Throwable $ex) {
                    $pdo->rollBack();
                    $err = 'Gagal menyimpan pesanan. Coba lagi.';
                }
            }
        }
    } elseif ($aksi === 'simpan' || $aksi === 'hapus') {
        $it = item_keranjang((int)($_POST['item_id'] ?? 0), $uid);
        $qty = (int)($_POST['qty'] ?? 0);
        if (!$it) $err = 'Pesanan sudah di-checkout, tidak bisa diubah.';
        elseif ($aksi === 'simpan') {
            if ($qty < 1) $err = 'Jumlah minimal 1. Gunakan Hapus untuk menghapus barang.';
            elseif ($qty > $it['stok']) $err = "Stok {$it['nama']} tersisa {$it['stok']} {$it['satuan']}.";
            else {
                db()->prepare('UPDATE order_items SET qty=? WHERE id=?')->execute([$qty, $it['id']]);
                recalc_order((int)$it['order_id']);
                log_activity("Mengubah jumlah item di keranjang #{$it['order_id']}");
                header('Location: pesanan.php');
                exit;
            }
        } else {
            db()->prepare('DELETE FROM order_items WHERE id=?')->execute([$it['id']]);
            $c = db()->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=?');
            $c->execute([$it['order_id']]);
            if ((int)$c->fetchColumn() === 0) {
                db()->prepare('DELETE FROM orders WHERE id=?')->execute([$it['order_id']]);
                log_activity("Membatalkan keranjang #{$it['order_id']}");
            } else log_activity("Menghapus barang dari keranjang #{$it['order_id']}");
            header('Location: pesanan.php');
            exit;
        }
    } elseif ($aksi === 'bayar_qris') {   // pelanggan menyatakan sudah membayar -> pesanan dikirim ke kasir
        $oid = (int)($_POST['order_id'] ?? 0);
        $s = db()->prepare("SELECT id FROM orders WHERE id=? AND user_id=? AND status='keranjang'");
        $s->execute([$oid, $uid]);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if (!$s->fetchColumn()) throw new RuntimeException('Pesanan tidak ditemukan atau sudah di-checkout.');
            $li = $pdo->prepare('SELECT product_id, qty FROM order_items WHERE order_id=?');
            $li->execute([$oid]);
            $rows = $li->fetchAll();
            if (!$rows) throw new RuntimeException('Pesanan kosong.');
            foreach ($rows as $r) if (!kurangi_stok($pdo, (int)$r['product_id'], (int)$r['qty'])) throw new RuntimeException('Stok salah satu barang tidak cukup lagi. Kurangi jumlahnya lalu checkout ulang.');
            $pdo->prepare("UPDATE orders SET status='baru', metode_bayar='qris', bayar_status='menunggu', checkout_at=NOW() WHERE id=?")->execute([$oid]);
            $pdo->commit();
            log_activity("Checkout pesanan #$oid (QRIS)");
            $_SESSION['flash'] = "Pesanan #$oid sudah dikirim ke kasir. Menunggu konfirmasi pembayaran QRIS.";
            header('Location: pesanan.php');
            exit;
        } catch (RuntimeException $ex) {
            $pdo->rollBack();
            $err = $ex->getMessage();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $err = 'Checkout gagal. Coba lagi.';
        }
    }
}
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
if ($b = keranjang($uid)) recalc_order((int)$b['id']);   // harga keranjang selalu mengikuti data barang terbaru

$modal = null;   // pop up QRIS muncul otomatis setelah klik Checkout
if (isset($_GET['bayar'])) {
    $s = db()->prepare("SELECT * FROM orders WHERE id=? AND user_id=? AND status='keranjang'");
    $s->execute([(int)$_GET['bayar'], $uid]);
    $mo = $s->fetch();
    if (!$mo) $err = 'Pesanan tidak ditemukan atau sudah di-checkout.';
    else {
        recalc_order((int)$mo['id']);
        $st = db()->prepare('SELECT oi.qty, p.stok, p.nama, p.satuan FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
        $st->execute([$mo['id']]);
        $cek = $st->fetchAll();
        if (!$cek) $err = 'Keranjang kosong.';
        foreach ($cek as $r) if ($r['qty'] > $r['stok']) {
            $err = "Stok {$r['nama']} tersisa {$r['stok']} {$r['satuan']}. Ubah jumlahnya dulu.";
            break;
        }
        if (!$err) $modal = ['id' => (int)$mo['id'], 'total' => order_total((int)$mo['id'])];
    }
}
$tambah = isset($_GET['tambah']);
if ($tambah) {
    $q = trim($_GET['q'] ?? '');
    $c = db()->prepare('SELECT COUNT(*) FROM products WHERE stok > 0 AND nama LIKE ?');
    $c->execute(["%$q%"]);
    [$pp, $ppages, $poff, $pper] = paginate((int)$c->fetchColumn(), 10, 'pp');
    $plist = db()->prepare("SELECT * FROM products WHERE stok > 0 AND nama LIKE ? ORDER BY nama LIMIT $pper OFFSET $poff");
    $plist->execute(["%$q%"]);
}
$c = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id=? AND $tampil");
$c->execute([$uid]);
[$page, $pages, $off, $per] = paginate((int)$c->fetchColumn(), 5);
$os = db()->prepare("SELECT * FROM orders WHERE user_id=? AND $tampil ORDER BY id DESC LIMIT $per OFFSET $off");
$os->execute([$uid]);
$orders = $os->fetchAll();
$items = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $st = db()->prepare('SELECT oi.*, p.nama, p.satuan FROM order_items oi JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY oi.id');
    $st->execute($ids);
    foreach ($st as $i) $items[$i['order_id']][] = $i;
}
page_header('Pesanan Saya');
?>
<p><?php if ($tambah): ?><a class="btn alt" href="pesanan.php">Tutup</a><?php else: ?><a class="btn" href="?tambah=1">Tambah pesanan</a><?php endif; ?></p>
<?php if ($flash): ?><p class="ok"><?= e($flash) ?></p><?php endif; ?>
<?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
<?php if ($tambah): ?>
    <div class="card">
        <form method="get"><input type="hidden" name="tambah" value="1"><input name="q" value="<?= e($q) ?>" placeholder="Cari barang..."> <button class="btn alt">Cari</button></form><br>
        <div class="sc">
            <table>
                <tr>
                    <th>Nama barang
                    <th>Satuan
                    <th>Harga
                    <th>Jumlah
                </tr>
                <?php foreach ($plist as $p): ?><tr>
                        <td><?= e($p['nama']) ?>
                        <td><?= e($p['satuan']) ?>
                        <td><?= rp($p['harga_jual']) ?><?= $p['promo_harga'] > 0 ? '<br><span class="note">' . e(promo_teks($p)) . '</span>' : '' ?>
                        <td>
                            <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="aksi" value="tambah"><input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <input name="qty" type="number" min="1" value="1" style="width:80px"><button class="btn">Tambah</button>
                            </form>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div><?= pager($pp, $ppages, 'pp') ?>
    </div>
<?php endif; ?>
<?php foreach ($orders as $o): $li = $items[$o['id']] ?? [];
    $edit = $o['status'] === 'keranjang';
    $tot = 0;
    foreach ($li as $i) $tot += $i['qty'] * $i['harga_satuan']; ?>
    <div class="card"><b>Pesanan #<?= $o['id'] ?></b> <span class="tag"><?= $edit ? 'keranjang (belum dikirim)' : e($o['status']) ?></span>
        <?php if (!$edit): ?><span class="tag">QRIS: <?= $o['bayar_status'] === 'lunas' ? 'lunas' : 'menunggu konfirmasi kasir' ?></span><?php endif; ?>
        <span class="note"><?= e(date('d/m/Y H:i', strtotime($o['created_at']))) ?></span>
        <div class="sc">
            <table>
                <tr>
                    <th>Barang
                    <th>Jumlah
                    <th>Harga
                    <th>Subtotal<?= $edit ? '<th>Ubah' : '' ?>
                </tr>
                <?php foreach ($li as $i): ?><tr>
                        <td><?= e($i['nama']) ?>
                        <td><?= $i['qty'] ?> <?= e($i['satuan']) ?>
                        <td><?= rp($i['harga_satuan']) ?>
                        <td><?= rp($i['qty'] * $i['harga_satuan']) ?>
                            <?php if ($edit): ?>
                        <td>
                            <form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="item_id" value="<?= $i['id'] ?>">
                                <input name="qty" type="number" min="1" value="<?= $i['qty'] ?>" style="width:80px"><button class="btn alt" name="aksi" value="simpan">Simpan</button>
                                <button class="btn del" name="aksi" value="hapus" onclick="return confirm('Hapus barang ini dari pesanan?')">Hapus</button>
                            </form><?php endif; ?>
                    </tr>
                <?php endforeach; ?><tr>
                    <th colspan="3">Total
                    <th colspan="<?= $edit ? 2 : 1 ?>"><?= rp($tot) ?>
                </tr>
            </table>
        </div>
        <?php if ($edit && $li): ?><p><a class="btn" href="?bayar=<?= $o['id'] ?>">Checkout</a> <span class="note">Pesanan baru dikirim ke kasir setelah checkout dan pembayaran QRIS.</span></p><?php endif; ?>
    </div>
<?php endforeach;
if (!$orders): ?><p class="note">Belum ada pesanan. Klik Tambah pesanan untuk mulai memesan.</p><?php endif; ?>
<?= pager($page, $pages) ?>
<?php if ($modal): ?>
    <div class="modal">
        <div class="modal-box">
            <h3>Pembayaran QRIS</h3>
            <p>Pesanan #<?= $modal['id'] ?> &middot; Total <b><?= rp($modal['total']) ?></b></p><?= qris_html() ?>
            <p class="note">Scan QRIS, bayar sesuai total, lalu klik tombol di bawah. Kasir akan memeriksa pembayaran Anda.</p>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="bayar_qris"><input type="hidden" name="order_id" value="<?= $modal['id'] ?>">
                <button class="btn">Saya sudah membayar</button> <a class="btn alt" href="pesanan.php">Batal</a>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php page_footer();
