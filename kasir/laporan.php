<?php
require_once __DIR__ . '/../includes/layout.php';
require_perm('laporan');
$sumber = ($_GET['s'] ?? 'offline') === 'online' ? 'online' : 'offline';
$m = in_array($_GET['m'] ?? '', ['tunai', 'qris'], true) ? $_GET['m'] : 'semua';
$tgl = $_GET['tgl'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || !strtotime($tgl)) $tgl = date('Y-m-d');
$wm = $m === 'semua' ? '' : ' AND s.metode = ?';
$pm = $m === 'semua' ? [] : [$m];
$rentang = [
    'Hari ini'  => [date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))],
    'Bulan ini' => [date('Y-m-01 00:00:00'), date('Y-m-01 00:00:00', strtotime('first day of next month'))],
    'Tahun ini' => [date('Y-01-01 00:00:00'), date('Y-01-01 00:00:00', strtotime('+1 year'))],
];
$sum = db()->prepare('SELECT COUNT(DISTINCT s.id) n, COALESCE(SUM(i.qty),0) q, COALESCE(SUM(i.qty*i.harga_satuan),0) omzet,
    COALESCE(SUM(i.qty*(i.harga_satuan-i.harga_beli)),0) laba FROM sales s JOIN sale_items i ON i.sale_id=s.id
    WHERE s.sumber=? AND s.created_at>=? AND s.created_at<?' . $wm);
// Data transaksi hanya untuk satu tanggal (default hari ini); hari sebelumnya otomatis masuk ke ringkasan bulan/tahun ini.
$a0 = date('Y-m-d 00:00:00', strtotime($tgl));
$b0 = date('Y-m-d 00:00:00', strtotime("$tgl +1 day"));
$c = db()->prepare('SELECT COUNT(*) FROM sales s WHERE s.sumber=? AND s.created_at>=? AND s.created_at<?' . $wm);
$c->execute([$sumber, $a0, $b0, ...$pm]);
[$page, $pages, $off, $per] = paginate((int)$c->fetchColumn());
$tx = db()->prepare("SELECT s.* FROM sales s WHERE s.sumber=? AND s.created_at>=? AND s.created_at<?$wm ORDER BY s.id DESC LIMIT $per OFFSET $off");
$tx->execute([$sumber, $a0, $b0, ...$pm]);
$tx = $tx->fetchAll();
$items = [];
if ($tx) {
    $ids = array_column($tx, 'id');
    $st = db()->prepare('SELECT * FROM sale_items WHERE sale_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $st->execute($ids);
    foreach ($st as $i) $items[$i['sale_id']][] = $i;
}
$u = fn($o) => '?' . http_build_query(array_merge(['s' => $sumber, 'm' => $m, 'tgl' => $tgl], $o));
page_header('Laporan');
?>
<p><a class="btn <?= $sumber === 'offline' ? '' : 'alt' ?>" href="<?= e($u(['s' => 'offline'])) ?>">Penjualan Offline</a> <a class="btn <?= $sumber === 'online' ? '' : 'alt' ?>" href="<?= e($u(['s' => 'online'])) ?>">Penjualan Online</a></p>
<h3>Ringkasan transaksi</h3>
<div class="row"><?php foreach ($rentang as $label => [$a, $b]): $sum->execute([$sumber, $a, $b, ...$pm]);
                        $r = $sum->fetch(); ?>
        <div class="card" style="flex:1 1 200px;background:#fdf0b8"><?= $label ?><br><b style="font-size:18px"><?= rp($r['omzet']) ?></b><br><?= $r['n'] ?> transaksi, <?= $r['q'] ?> barang<br>Laba <?= rp($r['laba']) ?></div>
    <?php endforeach; ?>
</div>
<h3>Data transaksi tanggal <?= e(date('d/m/Y', strtotime($tgl))) ?></h3>
<form method="get" class="row"><input type="hidden" name="s" value="<?= e($sumber) ?>"><input type="hidden" name="m" value="<?= e($m) ?>">
    <label>Pilih tanggal<input type="date" name="tgl" value="<?= e($tgl) ?>" max="<?= date('Y-m-d') ?>"></label><button class="btn alt">Tampilkan</button><a class="btn alt" href="<?= e($u(['tgl' => date('Y-m-d')])) ?>">Hari ini</a>
    <span style="align-self:center;margin-left:12px"><b>Pembayaran:</b></span><?php foreach (['semua' => 'Semua', 'tunai' => 'Tunai', 'qris' => 'QRIS'] as $k => $label): ?><a class="btn <?= $m === $k ? '' : 'alt' ?>" href="<?= e($u(['m' => $k])) ?>"><?= $label ?></a><?php endforeach; ?>
</form><br>
<div class="sc">
    <table>
        <tr>
            <th>Waktu
            <th>Nama barang
            <th>Jumlah
            <th>Harga
            <th>Subtotal
            <th>Metode
            <th>Uang
            <th>Kembalian
            <th>
        </tr>
        <?php foreach ($tx as $t): $li = $items[$t['id']] ?? [];
            $col = fn($f) => implode('<br>', array_map($f, $li)); ?>
            <tr>
                <td><?= e(date('H:i', strtotime($t['created_at']))) ?><br><span class="note"><?= e($t['kode']) ?><?= $t['pelanggan'] ? ' &middot; ' . e($t['pelanggan']) : '' ?></span>
                <td><?= $col(fn($i) => e($i['nama_barang'])) ?>
                <td><?= $col(fn($i) => $i['qty'] . ' ' . e($i['satuan'])) ?>
                <td><?= $col(fn($i) => rp($i['harga_satuan'])) ?>
                <td><?= $col(fn($i) => rp($i['qty'] * $i['harga_satuan'])) ?><div style="border-top:1px solid var(--ink);margin-top:4px;padding-top:2px"><b><?= rp($t['total']) ?></b></div>
                <td><?= $t['metode'] === 'qris' ? 'QRIS' : 'Tunai' ?>
                <td><b><?= rp($t['bayar']) ?></b>
                <td><?= $t['kembali'] > 0 ? rp($t['kembali']) : '-' ?>
                <td><a class="btn alt" target="_blank" href="<?= BASE_URL ?>/nota.php?id=<?= $t['id'] ?>&print=1">Cetak</a>
            </tr>
        <?php endforeach;
        if (!$tx): ?><tr>
                <td colspan="9" class="note">Tidak ada transaksi pada tanggal ini.
            </tr><?php endif; ?>
    </table>
</div>
<?= pager($page, $pages) ?>
<p class="note">Transaksi hari-hari sebelumnya otomatis tersimpan di ringkasan bulan ini dan tahun ini, dan bisa dilihat atau dicetak ulang lewat pilihan tanggal.<?= $sumber === 'online' ? ' Penjualan online dihitung saat pesanan ditandai terkirim.' : '' ?></p>
<?php page_footer();
