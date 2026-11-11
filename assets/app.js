// Input harga: tampil dengan titik ribuan (10.000); server membersihkannya lewat num().
document.addEventListener('input', function (e) {
  var t = e.target;
  if (t.classList && t.classList.contains('rp')) {
    var d = t.value.replace(/\D/g, '');
    t.value = d ? Number(d).toLocaleString('id-ID') : '';
  }
});
function toggleMenu(force) {   // sidebar di HP (tombol hamburger)
  var s = document.getElementById('side'), b = document.getElementById('backdrop');
  if (!s) return;
  var open = typeof force === 'boolean' ? force : !s.classList.contains('open');
  s.classList.toggle('open', open); b.classList.toggle('open', open);
  var h = document.querySelector('.top .burger'); if (h) h.setAttribute('aria-expanded', open);
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggleMenu(false); });
function toggleForm(show) {    // form tambah barang
  var f = document.getElementById('formbarang'), b = document.getElementById('btnTambah');
  if (!f) return;
  f.hidden = !show; if (b) b.hidden = show;
  if (show) { f.scrollIntoView({behavior: 'smooth'}); var i = f.querySelector('input[name=nama]'); if (i) i.focus(); }
}
