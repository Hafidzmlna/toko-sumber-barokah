<?php
require_once __DIR__ . '/../includes/layout.php';
require_perm('users');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['aksi'] ?? '') === 'hapus') {
        try {
            $s = db()->prepare("DELETE FROM users WHERE id=? AND role<>'admin'"); $s->execute([$id]);
            if ($s->rowCount()) log_activity("Menghapus user #$id");
            header('Location: users.php'); exit;
        } catch (PDOException $ex) { $err = 'User ini punya riwayat pesanan, tidak bisa dihapus.'; }
    } else {
        $nama = trim($_POST['nama'] ?? ''); $un = trim($_POST['username'] ?? ''); $pw = $_POST['password'] ?? '';
        $role = in_array($_POST['role'] ?? '', ['pelanggan', 'kasir'], true) ? $_POST['role'] : 'pelanggan';
        if ($nama === '' || $un === '' || (!$id && $pw === '')) $err = 'Nama, username, dan password wajib diisi.';
        else {
            $c = db()->prepare('SELECT id FROM users WHERE username=? AND id<>?'); $c->execute([$un, $id]);
            if ($c->fetch()) $err = 'Username sudah dipakai.';
            elseif ($id) {   // edit: role admin tidak boleh diubah
                db()->prepare("UPDATE users SET nama=?, username=?, role=IF(role='admin','admin',?) WHERE id=?")->execute([$nama, $un, $role, $id]);
                if ($pw !== '') db()->prepare('UPDATE users SET password=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                log_activity("Mengubah user $un"); header('Location: users.php'); exit;
            } else {
                db()->prepare('INSERT INTO users (nama, username, password, role) VALUES (?,?,?,?)')->execute([$nama, $un, password_hash($pw, PASSWORD_DEFAULT), $role]);
                log_activity("Menambah user $un ($role)"); header('Location: users.php'); exit;
            }
        }
    }
}
$edit = null;
if (isset($_GET['edit'])) { $s = db()->prepare('SELECT * FROM users WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit = $s->fetch() ?: null; }
$q = trim($_GET['q'] ?? ''); $like = "%$q%";
$c = db()->prepare('SELECT COUNT(*) FROM users WHERE nama LIKE ? OR username LIKE ?'); $c->execute([$like, $like]);
[$page, $pages, $off, $per] = paginate((int)$c->fetchColumn());
$list = db()->prepare("SELECT * FROM users WHERE nama LIKE ? OR username LIKE ? ORDER BY id LIMIT $per OFFSET $off"); $list->execute([$like, $like]);
page_header('Data User');
?>
<div class="card"><b><?= $edit ? 'Ubah user' : 'Tambah user' ?></b>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
<label>Nama<input name="nama" value="<?= e($edit['nama'] ?? '') ?>" required></label>
<label>Username<input name="username" value="<?= e($edit['username'] ?? '') ?>" required></label>
<label>Password<?= $edit ? ' (kosongkan jika tidak diubah)' : '' ?><input name="password" type="password" autocomplete="new-password"></label>
<label>Role<select name="role" <?= ($edit['role'] ?? '') === 'admin' ? 'disabled' : '' ?>>
<?php foreach (['pelanggan', 'kasir'] as $r): ?><option <?= ($edit['role'] ?? 'pelanggan') === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></label>
<button class="btn"><?= $edit ? 'Simpan perubahan' : 'Tambah user' ?></button><?php if ($edit): ?><a class="btn alt" href="users.php">Batal</a><?php endif; ?></form>
<p class="err"><?= e($err) ?></p></div>
<form method="get" class="row"><input name="q" value="<?= e($q) ?>" placeholder="Cari nama atau username..."> <button class="btn">Cari</button><?php if ($q !== ''): ?><a class="btn alt" href="users.php">Reset</a><?php endif; ?></form><br>
<div class="sc"><table><tr><th>Nama<th>Username<th>Role<th></tr>
<?php foreach ($list as $u): ?><tr><td><?= e($u['nama']) ?><td><?= e($u['username']) ?><td><?= e($u['role']) ?>
<td><a class="btn alt" href="?edit=<?= $u['id'] ?>">Ubah</a>
<?php if ($u['role'] !== 'admin'): ?><form method="post" style="display:inline" onsubmit="return confirm('Hapus user ini?')"><?= csrf_field() ?>
<input type="hidden" name="aksi" value="hapus"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn del">Hapus</button></form><?php endif; ?></tr>
<?php endforeach; ?></table></div><?= pager($page, $pages) ?>
<?php page_footer();
