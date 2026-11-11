<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/shop.php';
require_perm('qris');
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $f = $_FILES['qris'] ?? null;
    if (!function_exists('imagecreatefromstring')) $err = 'Ekstensi GD di PHP belum aktif. Aktifkan extension=gd di php.ini.';
    elseif (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $err = 'Pilih file gambar QRIS.';
    elseif ($f['size'] > 2 * 1024 * 1024) $err = 'Ukuran file maksimal 2 MB.';
    else {
        $raw = file_get_contents($f['tmp_name']);
        $info = @getimagesizefromstring($raw);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) $err = 'Format harus PNG, JPG, atau WebP.';
        elseif ($info[0] < 100 || $info[1] < 100 || $info[0] > 4000 || $info[1] > 4000) $err = 'Ukuran gambar harus antara 100 dan 4000 piksel.';
        elseif (!($im = @imagecreatefromstring($raw))) $err = 'File gambar tidak valid atau rusak.';
        else {
            // Decode lalu encode ulang sebagai PNG baru: metadata dan data tersembunyi di dalam file ikut terbuang.
            [$w, $h] = [$info[0], $info[1]];
            $scale = min(1, 1200 / max($w, $h)); $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
            $out = imagecreatetruecolor($nw, $nh);
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
            imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $dir = __DIR__ . '/../uploads'; $tmp = "$dir/qris.tmp.png";
            if (is_dir($dir) && imagepng($out, $tmp, 6) && rename($tmp, "$dir/qris.png")) {
                foreach (glob("$dir/qris.*") ?: [] as $old) if (basename($old) !== 'qris.png') @unlink($old);   // bersihkan file lama
                db()->prepare('REPLACE INTO settings (k, v) VALUES (?, ?)')->execute(['qris_image', 'qris.png']);
                log_activity('Mengganti gambar QRIS'); $msg = 'QRIS tersimpan.';
            } else { @unlink($tmp); $err = 'Gagal menyimpan file. Pastikan folder uploads bisa ditulis.'; }
        }
    }
}
page_header('Pengaturan QRIS');
?>
<p class="note">Unggah gambar QRIS toko (dari aplikasi bank atau e-wallet merchant). Gambar ini ditampilkan ke pelanggan saat checkout dan di halaman Kasir.</p>
<?php if ($msg): ?><p class="ok"><?= e($msg) ?></p><?php endif; ?><?php if ($err): ?><p class="err"><?= e($err) ?></p><?php endif; ?>
<div class="card"><form method="post" enctype="multipart/form-data" class="row"><?= csrf_field() ?>
<label>File QRIS (PNG/JPG/WebP, maks 2 MB)<input type="file" name="qris" accept="image/png,image/jpeg,image/webp" required></label><button class="btn">Simpan</button></form></div>
<div class="card" style="text-align:center"><b>QRIS saat ini</b><br><br><?= qris_html() ?></div>
<?php page_footer();
