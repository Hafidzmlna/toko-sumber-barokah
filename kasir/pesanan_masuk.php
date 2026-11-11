<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/shop.php';
require_perm('orders');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'konfirmasi') {
        $s = db()->prepare("UPDATE orders SET bayar_status='lunas' WHERE id=? AND status<>'keranjang' AND bayar_status='menunggu'");
        $s->execute([$id]);
        if ($s->rowCount()) log_activity("Pembayaran QRIS pesanan #$id dikonfirmasi");
    } elseif ($aksi === 'proses') {
        $s = db()->prepare("UPDATE orders SET status='akan dikirim' WHERE id=? AND status='baru' AND bayar_status='lunas'");
        $s->execute([$id]);
        if ($s->rowCount()) log_activity("Pesanan #$id diubah ke akan dikirim");
    } elseif ($aksi === 'kirim') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare("UPDATE orders SET status='dikirim' WHERE id=? AND status='akan dikirim' AND bayar_status='lunas'");
            $s->execute([$id]);
            if ($s->rowCount()) {   // masuk laporan penjualan online sekali saja, saat ditandai terkirim
                $o = $pdo->prepare('SELECT u.nama FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=?');
                $o->execute([$id]);
                $nama = $o->fetchColumn();
                $it = $pdo->prepare('SELECT oi.qty, oi.harga_satuan, oi.harga_beli, p.nama, p.satuan FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
                $it->execute([$id]);
                $rows = $it->fetchAll();
                $total = 0;
                foreach ($rows as $r) $total += $r['qty'] * $r['harga_satuan'];
                $pdo->prepare("INSERT INTO sales (kode, sumber, metode, order_id, kasir_id, pelanggan, total, bayar, kembali) VALUES (?, 'online', 'qris', ?, ?, ?, ?, ?, 0)")
                    ->execute(['O' . date('ymdHis') . $id, $id, user()['id'], $nama, $total, $total]);
                $sid = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare('INSERT INTO sale_items (sale_id, nama_barang, satuan, qty, harga_satuan, harga_beli) VALUES (?,?,?,?,?,?)');
                foreach ($rows as $r) $ins->execute([$sid, $r['nama'], $r['satuan'], $r['qty'], $r['harga_satuan'], $r['harga_beli']]);
                log_activity("Pesanan #$id terkirim");
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
        }
    }
    header('Location: pesanan_masuk.php?page=' . (int)($_GET['page'] ?? 1));
    exit;
}
// Tampil: pesanan hari ini + pesanan hari sebelumnya yang belum selesai (supaya pesanan yang sudah dibayar tidak hilang sebelum dikirim).
$cond = "o.status <> 'keranjang' AND (DATE(COALESCE(o.checkout_at, o.created_at)) = CURDATE() OR o.status <> 'dikirim')";
$total = (int)db()->query("SELECT COUNT(*) FROM orders o WHERE $cond")->fetchColumn();
[$page, $pages, $off, $per] = paginate($total);
$orders = db()->query("SELECT o.*, u.nama AS pelanggan FROM orders o JOIN users u ON u.id=o.user_id WHERE $cond ORDER BY o.id DESC LIMIT $per OFFSET $off")->fetchAll();
$items = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $st = db()->prepare('SELECT oi.*, p.nama, p.satuan FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY oi.id');
    $st->execute($ids);
    foreach ($st as $i) $items[$i['order_id']][] = $i;
}
page_header('Pesanan Masuk');
foreach ($orders as $o): $li = $items[$o['id']] ?? [];
    $tot = 0;
    foreach ($li as $i) $tot += $i['qty'] * $i['harga_satuan']; ?>
    <div class="card"><b>#<?= $o['id'] ?> &middot; <?= e($o['pelanggan']) ?></b> <span class="tag"><?= e($o['status']) ?></span>
        <span class="tag">QRIS: <?= $o['bayar_status'] === 'lunas' ? 'lunas' : 'menunggu konfirmasi' ?></span>
        <span class="note"><?= e(date('d/m/Y H:i', strtotime($o['checkout_at'] ?? $o['created_at']))) ?></span>
        <?= date('Y-m-d', strtotime($o['checkout_at'] ?? $o['created_at'])) !== date('Y-m-d') ? '<span class="tag">belum selesai, dari hari sebelumnya</span>' : '' ?><br>
        <?php foreach ($li as $i): ?><?= $i['qty'] ?> <?= e($i['satuan']) ?> <?= e($i['nama']) ?> @ <?= rp($i['harga_satuan']) ?><br><?php endforeach; ?>
    <b>Total <?= rp($tot) ?></b><br><br>
    <form method="post" class="row" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
        <?php if ($o['bayar_status'] === 'menunggu'): ?><button class="btn" name="aksi" value="konfirmasi">Konfirmasi pembayaran QRIS</button>
        <?php elseif ($o['status'] === 'baru'): ?><button class="btn" name="aksi" value="proses">Ubah ke Akan dikirim</button>
        <?php elseif ($o['status'] === 'akan dikirim'): ?><button class="btn" name="aksi" value="kirim">Tandai Terkirim</button>
        <?php else: ?><span class="ok">Pesanan sudah terkirim</span><?php endif; ?></form>
    <a class="btn alt" target="_blank" href="<?= BASE_URL ?>/nota.php?order=<?= $o['id'] ?>&print=1">Cetak nota</a>
    </div>
<?php endforeach;
if (!$orders): ?><p class="note">Belum ada pesanan masuk.</p><?php endif; ?>
<?= pager($page, $pages) ?>
<?php page_footer();
