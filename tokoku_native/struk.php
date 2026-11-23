<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function rp($v): string { return number_format((float)$v, 0, ',', '.'); }

// ---- Pengaturan struk: diatur di menu Pengaturan > Toko & Struk ----
$ALAMAT  = $ALAMAT_TOKO;
$TELEPON = $TELEPON_TOKO;
$FOOTER  = $FOOTER_STRUK;
// Lebar kertas thermal: 80 mm (isi 72 mm) atau 58 mm (isi 52 mm)
$isiMm   = $LEBAR_STRUK === 58 ? 52 : 72;
$kertasMm = $LEBAR_STRUK;

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
  /* Lebar mengikuti kertas yang dipilih di dialog cetak (58 mm / 80 mm), isi selalu di tengah */
  @page { margin: 0; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: "Courier New", Consolas, monospace; font-size: 13px; line-height: 1.4; color: #000; background: #e5e7eb; }
  .struk { width: <?= $isiMm ?>mm; max-width: 100%; margin: 0 auto; padding: 4mm 5mm 8mm; background: #fff; }
  .c { text-align: center; } .b { font-weight: 700; }
  .logo { display: block; max-width: 38mm; max-height: 18mm; margin: 0 auto 4px; filter: grayscale(1) contrast(1.4); }
  .nama { font-size: 20px; font-weight: 700; letter-spacing: 1px; }
  .garis { border-top: 1px dashed #000; margin: 6px 0; }
  .row { display: flex; justify-content: space-between; gap: 6px; }
  .item { margin: 3px 0; } .item .n { word-break: break-word; font-weight: 700; }
  .total { font-size: 16px; font-weight: 700; }
  .bcode { text-align: center; margin-top: 8px; }
  .bcode svg { width: 100%; height: 13mm; display: block; }
  .bcode .num { font-size: 11px; letter-spacing: 1px; margin-top: 2px; }
  .noprint { width: <?= $isiMm ?>mm; max-width: 100%; margin: 10px auto; display: flex; gap: 6px; }
  .noprint button, .noprint a { flex: 1; text-align: center; padding: 10px; font: 13px sans-serif; border: 0; border-radius: 6px; background: #6d4aff; color: #fff; text-decoration: none; cursor: pointer; }
  .noprint a { background: #475569; }
  @media print {
    html, body { width: 100%; background: #fff; }
    .noprint { display: none !important; }
    /* penuhi lebar kertas, maksimal 80 mm, dan otomatis di tengah */
    .struk { width: 100%; max-width: <?= $kertasMm ?>mm; margin: 0 auto; padding: 3mm 4mm 8mm; }
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
  <?php if ($LOGO_TOKO): ?><img class="logo" src="<?= e($LOGO_TOKO) ?>" alt=""><?php endif ?>
  <div class="c nama"><?= e($NAMA_TOKO) ?></div>
  <?php if ($ALAMAT !== ''): ?><div class="c"><?= e($ALAMAT) ?></div><?php endif ?>
  <?php if ($TELEPON !== ''): ?><div class="c">Telp <?= e($TELEPON) ?></div><?php endif ?>
  <div class="garis"></div>
  <div class="row"><span>No</span><span><?= e($o['kode_invoice'] ?: '#'.$o['id_transaksi']) ?></span></div>
  <div class="row"><span>Tgl</span><span><?= e($o['dibayar_pada'] ?: $o['tanggal']) ?></span></div>
  <div class="row"><span>Plg</span><span><?= e($o['nama_pelanggan']) ?><?= !empty($o['id_member']) ? ' (MEMBER)' : '' ?></span></div>
  <div class="garis"></div>
  <?php foreach ($items as $it): ?>
    <div class="item"><div class="n"><?= e($it['nama_produk']) ?></div>
    <div class="row"><span><?= (int)$it['jumlah'] ?> x <?= rp($it['harga_satuan']) ?></span><span><?= rp($it['subtotal']) ?></span></div></div>
  <?php endforeach ?>
  <?php if (!$items): ?><div class="c">(transaksi manual)</div><?php endif ?>
  <div class="garis"></div>
  <?php if ((float)($o['diskon_member'] ?? 0) > 0): ?>
  <div class="row"><span>Subtotal</span><span>Rp <?= rp((float)$o['total_belanja'] + (float)$o['diskon_member']) ?></span></div>
  <div class="row"><span>Diskon Member <?= (float)$o['diskon_member_persen'] ?>%</span><span>-<?= rp($o['diskon_member']) ?></span></div>
  <?php endif ?>
  <div class="row total"><span>TOTAL</span><span>Rp <?= rp($o['total_belanja']) ?></span></div>
  <div class="row"><span>Bayar</span><span><?= e($metode) ?></span></div>
  <div class="row"><span>Status</span><span>LUNAS</span></div>
  <?php if ($STRUK_BARCODE): $kodeBc = $o['kode_invoice'] ?: ('TRX' . $o['id_transaksi']); ?>
  <div class="bcode"><svg class="bc" data-code="<?= e($kodeBc) ?>"></svg><div class="num"><?= e($kodeBc) ?></div></div>
  <?php endif ?>
  <div class="garis"></div>
  <?php if ($FOOTER !== ''): ?><div class="c"><?= e($FOOTER) ?></div><?php endif ?>
<?php endif ?>
</div>
<script src="assets/barcode.js"></script>
<?php if (isset($_GET['print']) && $lunas): ?><script>window.addEventListener('load',function(){window.print()})</script><?php endif ?>
</body>
</html>
