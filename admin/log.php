<?php
require_once __DIR__ . '/../includes/layout.php';
require_perm('log');
$q = trim($_GET['q'] ?? ''); $like = "%$q%";
$c = db()->prepare('SELECT COUNT(*) FROM activity_logs WHERE username LIKE ? OR aksi LIKE ?'); $c->execute([$like, $like]);
[$page, $pages, $off, $per] = paginate((int)$c->fetchColumn(), 20);
$rows = db()->prepare("SELECT * FROM activity_logs WHERE username LIKE ? OR aksi LIKE ? ORDER BY id DESC LIMIT $per OFFSET $off"); $rows->execute([$like, $like]);
page_header('Log Aktivitas');
?>
<form method="get" class="row"><input name="q" value="<?= e($q) ?>" placeholder="Cari user atau aktivitas..."> <button class="btn">Cari</button><?php if ($q !== ''): ?><a class="btn alt" href="log.php">Reset</a><?php endif; ?></form><br>
<div class="sc"><table><tr><th>Waktu<th>User<th>Aktivitas</tr>
<?php foreach ($rows as $l): ?><tr><td><?= e($l['created_at']) ?><td><?= e($l['username']) ?><td><?= e($l['aksi']) ?></tr><?php endforeach; ?>
</table></div><?= pager($page, $pages) ?>
<?php page_footer();
