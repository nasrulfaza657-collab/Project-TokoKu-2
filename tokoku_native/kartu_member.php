<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['auth'])) {
    http_response_code(403);
    exit('Silakan login dulu. <a href="index.php">Ke halaman login</a>');
}

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT m.kode_member, m.diskon_persen, m.bergabung_pada, p.nama_pelanggan FROM tbl_member m JOIN tbl_pelanggan p ON p.id_pelanggan=m.id_pelanggan WHERE m.id_member=?');
$st->execute([$id]);
$m = $st->fetch();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kartu Member</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Arial, sans-serif; background: #e5e7eb; }
  .bar { display: flex; gap: 8px; padding: 12px 16px; background: #0f172a; }
  .bar a, .bar button { padding: 9px 14px; border: 0; border-radius: 8px; background: #6d4aff; color: #fff; text-decoration: none; font: 600 13px Arial; cursor: pointer; }
  .bar a { background: #334155; }
  .card { width: 85.6mm; height: 54mm; margin: 22px auto; padding: 5mm 6mm; border-radius: 4mm; color: #fff; background: linear-gradient(135deg, #4c1d95, #1e3a8a); display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 10px 30px #0004; }
  .top { display: flex; justify-content: space-between; align-items: center; font-weight: 700; letter-spacing: 2px; font-size: 13px; }
  .top span { color: #fcd34d; font-size: 11px; }
  .nm { font-size: 19px; font-weight: 700; }
  .sub { font-size: 11px; opacity: .85; margin-top: 2px; }
  .bcbox { background: #fff; border-radius: 6px; padding: 4px 8px 2px; text-align: center; color: #000; }
  .bcbox svg { width: 100%; height: 13mm; }
  .bcbox .num { font: 10px "Courier New", monospace; letter-spacing: 2px; }
  @media print {
    body { background: #fff; }
    .bar { display: none; }
    .card { margin: 0; box-shadow: none; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { margin: 8mm; }
  }
</style>
</head>
<body>
<div class="bar"><a href="index.php?page=member">← Kembali</a><button onclick="window.print()">🖨 Cetak kartu</button></div>
<?php if (!$m): ?>
  <p style="text-align:center;margin-top:40px">Member tidak ditemukan.</p>
<?php else: ?>
<div class="card">
  <div class="top"><div>✦ <?= e(strtoupper($NAMA_TOKO)) ?> MEMBER</div><span>DISKON <?= (float)$m['diskon_persen'] ?>%</span></div>
  <div><div class="nm"><?= e($m['nama_pelanggan']) ?></div><div class="sub">Bergabung <?= e(substr((string)$m['bergabung_pada'], 0, 10)) ?></div></div>
  <div class="bcbox"><svg class="bc" data-code="<?= e($m['kode_member']) ?>"></svg><div class="num"><?= e($m['kode_member']) ?></div></div>
</div>
<?php endif ?>
<script src="assets/barcode.js"></script>
</body>
</html>
