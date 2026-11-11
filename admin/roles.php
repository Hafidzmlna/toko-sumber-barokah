<?php
require_once __DIR__ . '/../includes/layout.php';
require_perm('roles');
const PERMS = [
    'katalog' => 'Lihat daftar harga',
    'pesan' => 'Order & lihat status pesanan',
    'barang' => 'Kelola data barang',
    'orders' => 'Kelola pesanan masuk',
    'pos' => 'Kasir offline & nota',
    'laporan' => 'Laporan penjualan & laba',
    'qris' => 'Pengaturan QRIS toko'
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    foreach (['pelanggan', 'kasir'] as $r) {
        db()->prepare('DELETE FROM role_permissions WHERE role=?')->execute([$r]);
        foreach (array_keys(PERMS) as $p) if (!empty($_POST['p'][$r][$p]))
            db()->prepare('INSERT INTO role_permissions (role, permission) VALUES (?,?)')->execute([$r, $p]);
    }
    log_activity('Mengubah hak akses role');
    header('Location: roles.php?ok=1');
    exit;
}
$has = [];
foreach (db()->query('SELECT role, permission FROM role_permissions') as $x) $has[$x['role']][$x['permission']] = true;
page_header('Manajemen Role');
?>
<p class="note">Centang menu yang boleh dibuka tiap role. Role admin selalu memegang User, Role, dan Log.</p>
<?php if (isset($_GET['ok'])): ?><p class="ok">Hak akses disimpan.</p><?php endif; ?>
<form method="post"><?= csrf_field() ?><div class="sc">
        <table>
            <tr>
                <th>Hak akses
                <th>Pelanggan
                <th>Kasir
            </tr>
            <?php foreach (PERMS as $k => $label): ?><tr>
                    <td><?= e($label) ?>
                        <?php foreach (['pelanggan', 'kasir'] as $r): ?>
                    <td><input type="checkbox" name="p[<?= $r ?>][<?= $k ?>]" value="1" <?= !empty($has[$r][$k]) ? 'checked' : '' ?>><?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <p><button class="btn">Simpan</button></p>
</form>
<?php page_footer();
