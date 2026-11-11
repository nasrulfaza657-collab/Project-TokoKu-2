<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function rp($v): string { return number_format((float)$v, 0, ',', '.'); }

// ---- Pengaturan struk (ubah sesuai toko Anda) ----
$NAMA_TOKO = 'TokoKu';
$ALAMAT    = 'Ponorogo, Jawa Timur';
$FOOTER    = 'Terima kasih sudah berbelanja!';

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT t.*, p.nama_pelanggan FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan WHERE t.id_transaksi=?');
$st->execute([$id]);
$o = $st->fetch();

$items = [];
if ($o) {
    $st = $pdo->prepare('SELECT d.jumlah, d.harga_satuan, d.subtotal, pr.nama_produk FROM tbl_detail_transaksi d JOIN tbl_produk pr ON pr.id_produk=d.id_produk WHERE d.id_transaksi=?');
    $st->execute([$id]);
    $items = $st->fetchAll();
}
$lunas = $o && ($o['status_pembayaran'] ?? 'dibayar') === 'dibayar';
$metode = $o ? ($PAYMENT_METHODS[$o['metode_pembayaran'] ?? '']['label'] ?? 'Manual') : '';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Struk <?= e($o['kode_invoice'] ?? '#'.$id) ?></title>
<style>
  /* Kertas thermal 57 mm, panjang mengikuti isi struk */
  @page { size: 57mm auto; margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: "Courier New", Consolas, monospace; font-size: 10px; line-height: 1.35; color: #000; background: #e5e7eb; }
  .struk { width: 57mm; margin: 0 auto; padding: 3mm 4mm 6mm; background: #fff; }
  .c { text-align: center; } .b { font-weight: 700; }
  .nama { font-size: 14px; font-weight: 700; letter-spacing: 1px; }
  .garis { border-top: 1px dashed #000; margin: 4px 0; }
  .row { display: flex; justify-content: space-between; gap: 4px; }
  .item { margin: 2px 0; } .item .n { word-break: break-word; }
  .total { font-size: 12px; font-weight: 700; }
  .noprint { width: 57mm; margin: 10px auto; display: flex; gap: 6px; }
  .noprint button, .noprint a { flex: 1; text-align: center; padding: 8px; font: 12px sans-serif; border: 0; border-radius: 6px; background: #6d4aff; color: #fff; text-decoration: none; cursor: pointer; }
  .noprint a { background: #475569; }
  @media print {
    body { background: #fff; }
    .noprint { display: none !important; }
    .struk { margin: 0; padding: 2mm 4mm 6mm; }
  }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">🖨 Cetak</button><a href="index.php?page=bayar&id=<?= $id ?>">← Kembali</a></div>
<div class="struk">
<?php if (!$o): ?>
  <div class="c b">Transaksi tidak ditemukan</div>
<?php elseif (!$lunas): ?>
  <div class="c b">STRUK BELUM TERSEDIA</div>
  <div class="garis"></div>
  <div class="c">Pesanan <?= e($o['kode_invoice'] ?: '#'.$o['id_transaksi']) ?> belum lunas (status: <?= e($o['status_pembayaran']) ?>).</div>
<?php else: ?>
  <div class="c nama"><?= e($NAMA_TOKO) ?></div>
  <div class="c"><?= e($ALAMAT) ?></div>
  <div class="garis"></div>
  <div class="row"><span>No</span><span><?= e($o['kode_invoice'] ?: '#'.$o['id_transaksi']) ?></span></div>
  <div class="row"><span>Tgl</span><span><?= e($o['dibayar_pada'] ?: $o['tanggal']) ?></span></div>
  <div class="row"><span>Plg</span><span><?= e($o['nama_pelanggan']) ?></span></div>
  <div class="garis"></div>
  <?php foreach ($items as $it): ?>
    <div class="item"><div class="n"><?= e($it['nama_produk']) ?></div>
    <div class="row"><span><?= (int)$it['jumlah'] ?> x <?= rp($it['harga_satuan']) ?></span><span><?= rp($it['subtotal']) ?></span></div></div>
  <?php endforeach ?>
  <?php if (!$items): ?><div class="c">(transaksi manual)</div><?php endif ?>
  <div class="garis"></div>
  <div class="row total"><span>TOTAL</span><span>Rp <?= rp($o['total_belanja']) ?></span></div>
  <div class="row"><span>Bayar</span><span><?= e($metode) ?></span></div>
  <div class="row"><span>Status</span><span>LUNAS</span></div>
  <div class="garis"></div>
  <div class="c"><?= e($FOOTER) ?></div>
<?php endif ?>
</div>
<?php if (isset($_GET['print']) && $lunas): ?><script>window.addEventListener('load',function(){window.print()})</script><?php endif ?>
</body>
</html>
