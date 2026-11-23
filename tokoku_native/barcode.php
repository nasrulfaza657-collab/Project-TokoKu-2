<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function rp($v): string { return 'Rp ' . number_format((float)$v, 0, ',', '.'); }

if (($_SESSION['auth']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Halaman ini khusus admin. <a href="index.php">Kembali</a>');
}

$tab  = ($_GET['tab'] ?? 'produk') === 'staf' ? 'staf' : 'produk';
$card = null;   // kartu yang baru dibuat (hanya tampil sekali)
$msg  = null;   // pesan hasil daftar RFID: [teks, ok|err]

// ---- Daftarkan / hapus kartu RFID staf ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['set_rfid', 'hapus_rfid'], true)) {
    $tab = 'staf';
    $idu = (int)($_POST['id_user'] ?? 0);
    try {
        if ($_POST['action'] === 'hapus_rfid') {
            $pdo->prepare('UPDATE tbl_user SET kode_rfid=NULL WHERE id_user=?')->execute([$idu]);
            $msg = ['Kartu RFID dihapus dari akun tersebut.', 'ok'];
        } else {
            $uid = strtoupper(trim((string)($_POST['rfid'] ?? '')));
            if ($uid === '') throw new RuntimeException('Tempelkan kartu RFID di reader terlebih dahulu.');
            if (!preg_match('/^[A-Z0-9]{4,32}$/', $uid)) throw new RuntimeException('Kode RFID tidak valid. Pastikan reader dalam mode keyboard (HID).');
            $pdo->prepare('UPDATE tbl_user SET kode_rfid=? WHERE id_user=?')->execute([hash('sha256', $uid), $idu]);
            $msg = ['Kartu RFID berhasil didaftarkan. Sekarang akun ini bisa login dengan tap kartu.', 'ok'];
        }
    } catch (PDOException $x) {
        $msg = ['Kartu RFID itu sudah terdaftar di akun lain.', 'err'];
    } catch (RuntimeException $x) {
        $msg = [$x->getMessage(), 'err'];
    }
}

// ---- Buat / ganti kartu login staf ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buat_kartu') {
    $tab = 'staf';
    $idu = (int)($_POST['id_user'] ?? 0);
    $st  = $pdo->prepare('SELECT id_user,nama,username,role FROM tbl_user WHERE id_user=?');
    $st->execute([$idu]);
    $u = $st->fetch();
    if ($u) {
        $code = '';
        for ($i = 0; $i < 16; $i++) $code .= (string)random_int(0, 9);
        $pdo->prepare('UPDATE tbl_user SET kode_kartu=? WHERE id_user=?')->execute([hash('sha256', $code), $idu]);
        $card = ['nama' => $u['nama'], 'username' => $u['username'], 'role' => $u['role'], 'code' => $code];
    }
}

// ---- Data produk ----
$products = [];
if ($tab === 'produk') {
    $one = (int)($_GET['id'] ?? 0);
    $n   = max(1, min(60, (int)($_GET['n'] ?? 1)));
    if ($one > 0) {
        $st = $pdo->prepare('SELECT id_produk,nama_produk,harga,diskon,barcode FROM tbl_produk WHERE id_produk=?');
        $st->execute([$one]);
        foreach ($st->fetchAll() as $r) for ($i = 0; $i < $n; $i++) $products[] = $r;
    } else {
        $products = $pdo->query('SELECT id_produk,nama_produk,harga,diskon,barcode FROM tbl_produk ORDER BY nama_produk')->fetchAll();
    }
}
$staff = $pdo->query('SELECT id_user,nama,username,role,kode_kartu,kode_rfid FROM tbl_user ORDER BY role,nama')->fetchAll();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cetak Barcode - TokoKu</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Arial, sans-serif; background: #eef2f7; color: #0f172a; }
  .bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 14px 18px; background: #0f172a; color: #fff; }
  .bar a, .bar button { padding: 9px 14px; border: 0; border-radius: 8px; background: #334155; color: #fff; text-decoration: none; font: 600 13px Arial; cursor: pointer; }
  .bar a.on { background: #6d4aff; }
  .bar .sp { flex: 1; }
  .wrap { max-width: 1000px; margin: 18px auto; padding: 0 16px; }
  .note { background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
  .labels { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .label { background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; text-align: center; break-inside: avoid; }
  .label b { display: block; font-size: 13px; line-height: 1.25; margin-bottom: 2px; }
  .label small { display: block; color: #475569; font-size: 11px; }
  .label svg { width: 100%; height: 46px; margin: 4px 0 2px; }
  .label .num { font: 12px "Courier New", monospace; letter-spacing: 1px; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; }
  th, td { padding: 11px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
  th { background: #f1f5f9; }
  td button { padding: 8px 12px; border: 0; border-radius: 7px; background: #6d4aff; color: #fff; font-weight: 700; cursor: pointer; }
  .ok { color: #15803d; font-weight: 700; } .no { color: #94a3b8; }
  .flash { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; font-weight: 700; }
  .flash.ok { background: #dcfce7; color: #166534; } .flash.err { background: #fee2e2; color: #991b1b; }
  .rfid-form { display: flex; gap: 6px; align-items: center; }
  .rfid-form input { width: 170px; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; }
  td button.alt { background: #64748b; }
  .idcard { width: 85.6mm; height: 54mm; margin: 14px auto; padding: 5mm 6mm; background: #fff; border: 1px solid #94a3b8; border-radius: 4mm; display: flex; flex-direction: column; justify-content: space-between; text-align: center; }
  .idcard .top { font-weight: 700; letter-spacing: 2px; font-size: 13px; color: #4c1d95; }
  .idcard .nm { font-size: 18px; font-weight: 700; }
  .idcard .rl { font-size: 11px; letter-spacing: 2px; color: #475569; text-transform: uppercase; }
  .idcard svg { width: 100%; height: 17mm; }
  .idcard .num { font: 10px "Courier New", monospace; letter-spacing: 2px; }
  @media print {
    body { background: #fff; }
    .bar, .note, table, .noprint { display: none !important; }
    .wrap { margin: 0; padding: 0; max-width: none; }
    .labels { gap: 4mm; }
    .label { border: 1px dashed #999; }
    .idcard { margin: 0; border: 1px solid #000; }
    @page { margin: 8mm; }
  }
</style>
</head>
<body>
<div class="bar">
  <a href="index.php">← Kembali ke TokoKu</a>
  <a class="<?= $tab === 'produk' ? 'on' : '' ?>" href="barcode.php?tab=produk">Label Produk</a>
  <a class="<?= $tab === 'staf' ? 'on' : '' ?>" href="barcode.php?tab=staf">Kartu Login Staf</a>
  <span class="sp"></span>
  <?php if ($tab === 'produk' || $card): ?><button onclick="window.print()">🖨 Cetak</button><?php endif ?>
</div>
<div class="wrap">

<?php if ($tab === 'produk'): ?>
  <div class="note">Tempel label pada produk. Scanner membaca angka di bawah barcode. Untuk mencetak lebih dari satu salinan satu produk: <code>barcode.php?id=3&amp;n=12</code> (id produk dan jumlah label).</div>
  <div class="labels">
  <?php foreach ($products as $p): ?>
    <div class="label">
      <b><?= e($p['nama_produk']) ?></b>
      <small><?= rp((float)$p['harga'] * (1 - (float)$p['diskon'] / 100)) ?></small>
      <svg class="bc" data-code="<?= e($p['barcode']) ?>"></svg>
      <div class="num"><?= e($p['barcode']) ?></div>
    </div>
  <?php endforeach ?>
  <?php if (!$products): ?><p>Belum ada produk.</p><?php endif ?>
  </div>

<?php else: ?>
  <?php if ($msg): ?><div class="flash <?= e($msg[1]) ?>"><?= e($msg[0]) ?></div><?php endif ?>
  <div class="note">Kartu RFID: klik kotak <b>RFID</b> pada baris akun, lalu <b>tempelkan kartu di reader</b>. Reader mengetik kode kartu dan menekan Enter, sehingga langsung tersimpan. Kode kartu hanya disimpan sebagai hash.</div>
  <?php if ($card): ?>
    <div class="note">Kartu <b><?= e($card['nama']) ?></b> dibuat. Kode ini <b>hanya tampil sekali</b> dan kartu lama (jika ada) tidak berlaku lagi. Cetak sekarang, lalu simpan kartu di tempat aman: siapa pun yang memegang kartunya bisa masuk sebagai akun ini.</div>
    <div class="idcard">
      <div class="top">✦ TOKOKU · KARTU STAF</div>
      <div><div class="nm"><?= e($card['nama']) ?></div><div class="rl"><?= e($card['role']) ?></div></div>
      <div><svg class="bc" data-code="<?= e($card['code']) ?>"></svg><div class="num"><?= e($card['code']) ?></div></div>
    </div>
  <?php endif ?>
  <table>
    <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Kartu barcode</th><th>Kartu RFID</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($staff as $u): ?>
      <tr>
        <td><?= e($u['nama']) ?></td>
        <td><?= e($u['username']) ?></td>
        <td><?= e($u['role']) ?></td>
        <td><?= $u['kode_kartu'] ? '<span class="ok">Sudah dibuat</span>' : '<span class="no">Belum ada</span>' ?></td>
        <td>
          <form method="post" class="rfid-form">
            <input type="hidden" name="action" value="set_rfid">
            <input type="hidden" name="id_user" value="<?= (int)$u['id_user'] ?>">
            <input type="password" name="rfid" placeholder="<?= $u['kode_rfid'] ? 'Ganti kartu RFID' : 'Tap kartu RFID' ?>" autocomplete="off">
            <button><?= $u['kode_rfid'] ? 'Ganti' : 'Daftar' ?></button>
          </form>
          <?= $u['kode_rfid'] ? '<small class="ok">Terdaftar</small>' : '<small class="no">Belum ada</small>' ?>
          <?php if ($u['kode_rfid']): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Hapus kartu RFID dari akun ini?')">
            <input type="hidden" name="action" value="hapus_rfid">
            <input type="hidden" name="id_user" value="<?= (int)$u['id_user'] ?>">
            <button class="alt" style="padding:4px 8px;font-size:11px">Hapus</button>
          </form>
          <?php endif ?>
        </td>
        <td>
          <form method="post" onsubmit="return confirm('<?= $u['kode_kartu'] ? 'Kartu lama akan tidak berlaku. Lanjut membuat kartu baru?' : 'Buat kartu login untuk akun ini?' ?>')">
            <input type="hidden" name="action" value="buat_kartu">
            <input type="hidden" name="id_user" value="<?= (int)$u['id_user'] ?>">
            <button><?= $u['kode_kartu'] ? 'Buat ulang kartu' : 'Buat kartu' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

</div>
<script src="assets/barcode.js"></script>
</body>
</html>
