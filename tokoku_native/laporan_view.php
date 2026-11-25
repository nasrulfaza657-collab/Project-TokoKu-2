<?php
// Tampilan halaman Laporan (di-include dari index.php). Variabel $pdo, $role, $NAMA_TOKO, dll. berasal dari index.php.
require_once __DIR__ . '/laporan_lib.php';

$per = laporanPeriode($_GET);
$lpAdm = $role === 'admin';
$lpKasirId = 0;
$lpKasirList = [];
$lpKasirTerpilih = null;
if ($lpAdm) {
    // Admin dapat melihat semua akun atau membatasi laporan ke satu kasir.
    $lpKasirList = $pdo->query("SELECT id_user, nama, username FROM tbl_user WHERE role='kasir' ORDER BY nama, username")->fetchAll();
    $requestedKasir = (int)($_GET['kasir'] ?? 0);
    if ($requestedKasir > 0) {
        foreach ($lpKasirList as $akunKasir) {
            if ((int)$akunKasir['id_user'] === $requestedKasir) {
                $lpKasirId = $requestedKasir;
                $lpKasirTerpilih = $akunKasir;
                break;
            }
        }
    }
} else {
    // Kasir tidak boleh mengubah scope melalui URL/query string.
    $lpKasirId = (int)($_SESSION['auth']['id'] ?? 0);
}
$dat = laporanData($pdo, $per, $STOK_MENIPIS, $lpKasirId);
$lpTransaksiTanpaKasir = 0;
if (!$lpAdm) {
    try {
        $cekLegacy = $pdo->query("SELECT COUNT(*) FROM tbl_transaksi WHERE status_pembayaran='dibayar' AND (id_user IS NULL OR id_user=0)");
        $lpTransaksiTanpaKasir = (int)$cekLegacy->fetchColumn();
    } catch (Throwable $e) {
        $lpTransaksiTanpaKasir = 0;
    }
}

$kp    = $dat['kpi'];
$rentang = tglID($dat['dari']) . ($dat['dari'] !== $dat['sampai'] ? ' – ' . tglID($dat['sampai']) : '');

$lpTabs = ['ringkasan' => 'Ringkasan', 'penjualan' => 'Penjualan', 'produk' => 'Produk', 'pelanggan' => 'Pelanggan']
        + ($lpAdm ? ['kasir' => 'Kasir'] : [])
        + ['transaksi' => 'Transaksi'];

$lpChips = ['hari_ini' => 'Hari ini', '7_hari' => '7 hari', '30_hari' => '30 hari', 'bulan_ini' => 'Bulan ini', 'tahun_ini' => 'Tahun ini', 'semua' => 'Semua'];

$exportQs = ['page' => 'laporan', 'export' => 'xlsx', 'periode' => $per['key']];
if ($lpAdm && $lpKasirId > 0) $exportQs['kasir'] = $lpKasirId;
if ($per['key'] === 'kustom') { $exportQs['dari'] = $per['dari']; $exportQs['sampai'] = $per['sampai']; }
$exportUrl = '?' . http_build_query($exportQs);

$slug = trim((string)preg_replace('/[^a-z0-9]+/i', '-', $NAMA_TOKO), '-') ?: 'toko';
$fileBase = 'laporan-' . strtolower($slug) . ($lpKasirTerpilih ? '-kasir-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string)$lpKasirTerpilih['username']) : '') . '-' . $dat['dari'] . '_' . $dat['sampai'];

$lpDelta = static function (float $now, ?float $prev): string {
    if ($prev === null) return '<span class="lp-delta flat">semua waktu</span>';
    if ($prev == 0.0) return $now > 0 ? '<span class="lp-delta up">▲ baru</span>' : '<span class="lp-delta flat">— sama</span>';
    $pct = ($now - $prev) / $prev * 100;
    if (abs($pct) < 0.05) return '<span class="lp-delta flat">— sama</span>';
    return '<span class="lp-delta ' . ($pct > 0 ? 'up' : 'down') . '">' . ($pct > 0 ? '▲' : '▼') . ' ' . number_format(abs($pct), 1, ',', '.') . '%</span>';
};
$lpSpark = static function (array $vals): string {
    $n = count($vals);
    if ($n < 2) return '';
    $max = max($vals) ?: 1;
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($i / ($n - 1) * 100, 1) . ',' . round(26 - ($v / $max) * 24, 1);
    return '<svg class="lp-spark" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true"><polyline points="' . implode(' ', $pts)
         . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/></svg>';
};

$totOmzet = max(0.0001, (float)$kp['omzet']);
$prevN = $kp['prev'] ? (float)$kp['prev']['n'] : null;
$prevO = $kp['prev'] ? (float)$kp['prev']['omzet'] : null;
$palet = ['#8b5cf6', '#3b82f6', '#22c994', '#ffab43', '#f43f5e', '#06b6d4'];
$metodeTot = max(0.0001, array_sum(array_map(static fn($m) => (float)$m['omzet'], $dat['metode'])));
$kosong = $kp['n'] === 0;
?>
<style>
/* Perbaikan tampilan laporan: dibuat scoped agar tidak bentrok dengan CSS halaman lain. */
.lp-head{display:block!important;padding:22px!important;overflow:hidden}
.lp-head-top{display:flex!important;justify-content:space-between!important;align-items:flex-start!important;gap:18px!important;flex-wrap:wrap!important}
.lp-head h2{margin:0 0 6px!important;font-size:21px!important}
.lp-head p{margin:0!important;color:#9eacc7!important;font-size:12px!important}
.lp-export{display:flex!important;gap:10px!important;flex-wrap:wrap!important}
.lp-head .lp-btn{display:inline-flex!important;align-items:center!important;justify-content:center!important;padding:10px 15px!important;border:0!important;border-radius:10px!important;color:#fff!important;text-decoration:none!important;font:700 12px 'DM Sans',sans-serif!important;cursor:pointer!important}
.lp-head .lp-btn.pdf{background:linear-gradient(100deg,#f0476b,#c42b57)!important}
.lp-head .lp-btn.xls{background:linear-gradient(100deg,#1fb87f,#128a5c)!important}
.lp-head .lp-btn.go{background:linear-gradient(100deg,#8055f5,#5936d2)!important}
.lp-filter{display:flex!important;justify-content:space-between!important;align-items:center!important;gap:14px!important;flex-wrap:wrap!important;margin-top:18px!important;padding-top:16px!important;border-top:1px solid #2b3854!important}
.lp-chips{display:flex!important;gap:7px!important;flex-wrap:wrap!important}
.lp-head .lp-chip{display:inline-flex!important;align-items:center!important;padding:8px 14px!important;border:1px solid #2c3b59!important;border-radius:30px!important;background:#121d31!important;color:#aab7d0!important;text-decoration:none!important;font-size:11px!important;font-weight:600!important}
.lp-head .lp-chip.on{background:linear-gradient(100deg,#7b52f2,#5936d2)!important;border-color:transparent!important;color:#fff!important}
.lp-range{display:flex!important;align-items:flex-end!important;gap:10px!important;flex-wrap:wrap!important}
.lp-range label{display:grid!important;gap:5px!important;color:#aab7d0!important;font-size:11px!important}
.lp-range input{max-width:180px!important;padding:8px 10px!important;border:1px solid #30405e!important;border-radius:8px!important;background:#0b1425!important;color:#edf2ff!important;color-scheme:dark!important;font:12px 'DM Sans',sans-serif!important}
.lp-tabs{display:flex!important;gap:6px!important;flex-wrap:wrap!important;margin:14px 0 18px!important;border-bottom:1px solid #2b3854!important}
.lp-tabs a{display:inline-flex!important;padding:11px 16px!important;margin-bottom:-1px!important;border:1px solid transparent!important;border-radius:10px 10px 0 0!important;color:#9eacc7!important;text-decoration:none!important;font-size:12px!important;font-weight:600!important}
.lp-tabs a.on{background:#131e33!important;border-color:#2b3854!important;border-bottom-color:#131e33!important;color:#fff!important}
.lp-pane{display:none!important}
.lp-pane.on{display:block!important}
.lp-kpis{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:16px!important;margin-bottom:18px!important}
.lp-grid{display:grid!important;grid-template-columns:minmax(0,1.7fr) minmax(260px,1fr)!important;gap:18px!important;align-items:start!important}
.lp-grid.split{grid-template-columns:minmax(0,1.8fr) minmax(260px,1fr)!important}
.lp-chart{position:relative!important;width:100%!important;height:290px!important;min-height:290px!important;max-height:290px!important;overflow:hidden!important}
.lp-chart.donut{height:190px!important;min-height:190px!important;max-height:190px!important}
.lp-chart canvas{position:absolute!important;inset:0!important;display:block!important;width:100%!important;height:100%!important;max-width:100%!important;max-height:100%!important}
.lp-search{padding:9px 12px!important;border:1px solid #30405e!important;border-radius:9px!important;background:#0b1425!important;color:#edf2ff!important}
@media(max-width:1050px){.lp-kpis{grid-template-columns:repeat(2,minmax(0,1fr))!important}.lp-grid,.lp-grid.split{grid-template-columns:1fr!important}}
@media(max-width:600px){.lp-kpis{grid-template-columns:1fr!important}.lp-tabs{flex-wrap:nowrap!important;overflow-x:auto!important}.lp-head{padding:15px!important}.lp-range label{width:100%!important}.lp-range input{width:100%!important;max-width:none!important}}
</style>
<div class="panel lp-head">
  <div class="lp-head-top">
    <div>
      <h2>Laporan Penjualan</h2>
      <p><b><?= e($per['label']) ?></b> · <?= e($rentang) ?> · <?= (int)$kp['hari'] ?> hari · hanya transaksi lunas</p>
    </div>
    <div class="lp-export">
      <button type="button" class="btn lp-btn pdf" id="btnPdf">⬇ Unduh PDF</button>
      <a class="btn lp-btn xls" href="<?= e($exportUrl) ?>">⬇ Unduh Excel</a>
    </div>
  </div>
  <div class="lp-filter">
    <div class="lp-chips">
      <?php foreach ($lpChips as $ck => $cl): ?>
      <a class="lp-chip <?= $per['key'] === $ck ? 'on' : '' ?>" href="?<?= e(http_build_query(array_merge(['page' => 'laporan', 'periode' => $ck], ($lpAdm && $lpKasirId > 0) ? ['kasir' => $lpKasirId] : []))) ?>"><?= e($cl) ?></a>
      <?php endforeach ?>
    </div>
    <form method="get" class="lp-range" id="lpRange">
      <input type="hidden" name="page" value="laporan">
      <input type="hidden" name="periode" value="kustom">
      <?php if ($lpAdm): ?>
      <label>Filter kasir
        <select name="kasir" class="lp-search" style="min-width:190px">
          <option value="0">Semua kasir</option>
          <?php foreach ($lpKasirList as $akunKasir): ?>
          <option value="<?= (int)$akunKasir['id_user'] ?>" <?= $lpKasirId === (int)$akunKasir['id_user'] ? 'selected' : '' ?>><?= e($akunKasir['nama']) ?> (<?= e($akunKasir['username']) ?>)</option>
          <?php endforeach ?>
        </select>
      </label>
      <?php else: ?>
      <label>Akun kasir <input type="text" value="<?= e($_SESSION['auth']['nama'] ?? 'Kasir') ?> (akun sendiri)" readonly></label>
      <?php endif ?>
      <label>Dari <input type="date" name="dari" value="<?= e($dat['dari']) ?>" max="<?= date('Y-m-d') ?>" required></label>
      <label>Sampai <input type="date" name="sampai" value="<?= e($dat['sampai']) ?>" max="<?= date('Y-m-d') ?>" required></label>
      <button class="btn lp-btn go">Terapkan</button>
    </form>
  </div>
</div>

<?php if (!$lpAdm && $lpTransaksiTanpaKasir > 0): ?>
<div class="info-banner" style="margin-top:14px">
  <b><?= lapAngka($lpTransaksiTanpaKasir) ?> transaksi lama belum memiliki data kasir</b>
  <span>Transaksi tersebut dibuat sebelum pencatatan akun kasir aktif. Karena ada lebih dari satu akun kasir, sistem tidak menebak pemiliknya agar laporan setiap kasir tetap terpisah. Admin perlu menentukan kasir pemilik transaksi lama.</span>
</div>
<?php endif ?>

<div class="settings-tabs lp-tabs" role="tablist">
  <?php foreach ($lpTabs as $tk => $tl): ?>
  <a href="#<?= $tk ?>" data-tab="<?= $tk ?>" class="<?= $tk === 'ringkasan' ? 'on' : '' ?>" role="tab"><?= e($tl) ?></a>
  <?php endforeach ?>
</div>

<!-- ============================ RINGKASAN ============================ -->
<section class="lp-pane on" data-pane="ringkasan">
  <?php if ($kosong): ?>
  <div class="info-banner"><b>Belum ada transaksi lunas</b><span>Tidak ada penjualan pada <?= e(strtolower($per['label'])) ?>. Coba pilih periode yang lebih panjang.</span></div>
  <?php endif ?>
  <div class="lp-kpis">
    <div class="lp-kpi k1">
      <div class="lp-kpi-top"><span>Total omzet</span><i>◈</i></div>
      <strong class="lp-count" data-v="<?= (float)$kp['omzet'] ?>" data-f="rp"><?= lapRp($kp['omzet']) ?></strong>
      <div class="lp-kpi-bot"><?= $lpDelta((float)$kp['omzet'], $prevO) ?><em>vs periode lalu</em></div>
      <?= $lpSpark($dat['seri']['omzet']) ?>
    </div>
    <div class="lp-kpi k2">
      <div class="lp-kpi-top"><span>Transaksi</span><i>▤</i></div>
      <strong class="lp-count" data-v="<?= (int)$kp['n'] ?>" data-f="int"><?= lapAngka($kp['n']) ?></strong>
      <div class="lp-kpi-bot"><?= $lpDelta((float)$kp['n'], $prevN) ?><em>vs periode lalu</em></div>
      <?= $lpSpark(array_map('floatval', $dat['seri']['trx'])) ?>
    </div>
    <div class="lp-kpi k3">
      <div class="lp-kpi-top"><span>Rata-rata / transaksi</span><i>⌀</i></div>
      <strong class="lp-count" data-v="<?= round($kp['avg']) ?>" data-f="rp"><?= lapRp($kp['avg']) ?></strong>
      <div class="lp-kpi-bot"><em><?= lapAngka($kp['pelanggan']) ?> pelanggan bertransaksi</em></div>
    </div>
    <div class="lp-kpi k4">
      <div class="lp-kpi-top"><span>Item terjual</span><i>▣</i></div>
      <strong class="lp-count" data-v="<?= (int)$kp['items'] ?>" data-f="int"><?= lapAngka($kp['items']) ?></strong>
      <div class="lp-kpi-bot"><em>Diskon member <?= lapRp($kp['diskon']) ?></em></div>
    </div>
  </div>

  <div class="lp-grid">
    <div class="panel">
      <div class="panel-head"><div><h3>Omzet &amp; transaksi</h3><p><?= (int)$kp['hari'] <= 92 ? 'Per hari' : 'Per bulan' ?> pada periode terpilih</p></div></div>
      <div class="lp-chart"><canvas id="lpChart"></canvas></div>
    </div>
    <div class="panel">
      <div class="panel-head"><div><h3>Metode pembayaran</h3><p>Porsi omzet</p></div></div>
      <?php if ($dat['metode']): ?>
      <div class="lp-chart donut"><canvas id="lpDonut"></canvas></div>
      <ul class="lp-legend">
        <?php foreach ($dat['metode'] as $i => $m): ?>
        <li><i style="background:<?= $palet[$i % count($palet)] ?>"></i><span><?= e(lapMetodeLabel((string)$m['m'])) ?></span><b><?= round((float)$m['omzet'] / $metodeTot * 100) ?>%</b></li>
        <?php endforeach ?>
      </ul>
      <?php else: ?><div class="lp-none">Belum ada data</div><?php endif ?>
    </div>
  </div>

  <div class="lp-grid even">
    <div class="panel">
      <div class="panel-head"><div><h3>Produk terlaris</h3><p>5 teratas berdasarkan penjualan</p></div></div>
      <?php $topP = array_slice($dat['produk'], 0, 5); $maxP = $topP ? max(1, (float)$topP[0]['omzet']) : 1; ?>
      <?php foreach ($topP as $r): ?>
      <div class="lp-bar">
        <div class="lp-bar-top"><span><?= e($r['nama_produk']) ?></span><b><?= lapRp($r['omzet']) ?></b></div>
        <div class="lp-bar-track"><i style="--w:<?= round((float)$r['omzet'] / $maxP * 100) ?>%"></i></div>
        <small><?= lapAngka($r['qty']) ?> terjual</small>
      </div>
      <?php endforeach ?>
      <?php if (!$topP): ?><div class="lp-none">Belum ada penjualan produk</div><?php endif ?>
    </div>
    <div class="panel">
      <div class="panel-head"><div><h3>Pelanggan teratas</h3><p>5 teratas berdasarkan total belanja</p></div></div>
      <?php foreach (array_slice($dat['pelanggan'], 0, 5) as $i => $r): ?>
      <div class="lp-rank">
        <span class="lp-no"><?= $i + 1 ?></span>
        <div><b><?= e($r['nama_pelanggan']) ?><?= $r['member'] ? ' <em class="lp-member">MEMBER</em>' : '' ?></b><small><?= e($r['kota']) ?> · <?= lapAngka($r['n']) ?> transaksi</small></div>
        <strong><?= lapRp($r['total']) ?></strong>
      </div>
      <?php endforeach ?>
      <?php if (!$dat['pelanggan']): ?><div class="lp-none">Belum ada pelanggan bertransaksi</div><?php endif ?>
    </div>
  </div>
</section>

<!-- ============================ PENJUALAN ============================ -->
<section class="lp-pane" data-pane="penjualan">
  <div class="lp-grid split">
    <div class="panel">
      <div class="panel-head"><div><h3>Penjualan harian</h3><p><?= count($dat['harian']) ?> hari ada transaksi</p></div></div>
      <?php
      $rows = []; $sn = 0; $si = 0; $sd = 0.0; $so = 0.0;
      foreach ($dat['harian'] as $r) {
          $rows[] = [e(tglID($r['tanggal'])), lapAngka($r['n']), lapAngka($r['items']), lapRp($r['diskon']), '<b>' . lapRp($r['omzet']) . '</b>'];
          $sn += $r['n']; $si += $r['items']; $sd += $r['diskon']; $so += $r['omzet'];
      }
      echo lapTabel(['Tanggal', 'Transaksi', 'Item', 'Diskon member', 'Omzet'], $rows, [3 => 'r', 1 => 'r', 2 => 'r', 4 => 'r'],
          ['Total', lapAngka($sn), lapAngka($si), lapRp($sd), lapRp($so)]);
      ?>
    </div>
    <div class="panel">
      <div class="panel-head"><div><h3>Per metode bayar</h3><p>Transaksi &amp; omzet</p></div></div>
      <?php
      $rows = [];
      foreach ($dat['metode'] as $m) $rows[] = [e(lapMetodeLabel((string)$m['m'])), lapAngka($m['n']), '<b>' . lapRp($m['omzet']) . '</b>'];
      echo lapTabel(['Metode', 'Transaksi', 'Omzet'], $rows, [1 => 'r', 2 => 'r']);
      ?>
    </div>
  </div>
</section>

<!-- ============================ PRODUK ============================ -->
<section class="lp-pane" data-pane="produk">
  <div class="lp-grid split">
    <div class="panel">
      <div class="panel-head"><div><h3>Penjualan per produk</h3><p>Omzet = harga × jumlah (sebelum diskon member)</p></div>
        <input class="lp-search" type="search" placeholder="Cari produk…" data-filter="#tblProduk"></div>
      <?php
      $sumP = 0.0; foreach ($dat['produk'] as $r) $sumP += (float)$r['omzet'];
      $sumP = max(0.0001, $sumP);
      $rows = []; $sq = 0; $so = 0.0;
      foreach ($dat['produk'] as $r) {
          $low = $STOK_MENIPIS > 0 && (int)$r['stok'] <= $STOK_MENIPIS;
          $rows[] = [e($r['nama_produk']), '<span class="lp-tag">' . e($r['kategori']) . '</span>', lapAngka($r['qty']), '<b>' . lapRp($r['omzet']) . '</b>',
                     round((float)$r['omzet'] / $sumP * 100, 1) . '%', '<span class="' . ($low ? 'lp-low' : '') . '">' . lapAngka($r['stok']) . '</span>'];
          $sq += (int)$r['qty']; $so += (float)$r['omzet'];
      }
      echo str_replace('class="lp-table"', 'class="lp-table" id="tblProduk"',
          lapTabel(['Produk', 'Kategori', 'Terjual', 'Penjualan', 'Porsi', 'Stok'], $rows, [2 => 'r', 3 => 'r', 4 => 'r', 5 => 'r'],
              ['Total', '', lapAngka($sq), lapRp($so), '100%', '']));
      ?>
    </div>
    <div>
      <div class="panel">
        <div class="panel-head"><div><h3>Per kategori</h3><p>Penjualan tiap kategori</p></div></div>
        <?php
        $rows = [];
        foreach ($dat['kategori'] as $r) $rows[] = [e($r['kategori']), lapAngka($r['qty']), '<b>' . lapRp($r['omzet']) . '</b>'];
        echo lapTabel(['Kategori', 'Terjual', 'Penjualan'], $rows, [1 => 'r', 2 => 'r']);
        ?>
      </div>
      <?php if ($STOK_MENIPIS > 0): ?>
      <div class="panel lp-stock">
        <div class="panel-head"><div><h3>⚠ Stok menipis</h3><p>Stok ≤ <?= (int)$STOK_MENIPIS ?> pcs</p></div></div>
        <?php foreach (array_slice($dat['stokRendah'], 0, 8) as $r): ?>
        <div class="lp-rank"><div><b><?= e($r['nama_produk']) ?></b><small><?= e($r['kategori']) ?></small></div><strong class="lp-low"><?= (int)$r['stok'] < 1 ? 'Habis' : lapAngka($r['stok']) . ' pcs' ?></strong></div>
        <?php endforeach ?>
        <?php if (!$dat['stokRendah']): ?><div class="lp-none">Semua stok aman 👍</div><?php endif ?>
        <?php if (count($dat['stokRendah']) > 8): ?><a class="text-link" href="?page=produk&stok=menipis">Lihat semua (<?= count($dat['stokRendah']) ?>) ↗</a><?php endif ?>
      </div>
      <?php endif ?>
    </div>
  </div>
</section>

<!-- ============================ PELANGGAN ============================ -->
<section class="lp-pane" data-pane="pelanggan">
  <div class="lp-grid split">
    <div class="panel">
      <div class="panel-head"><div><h3>Pelanggan teratas</h3><p>Diurutkan dari total belanja terbesar</p></div>
        <input class="lp-search" type="search" placeholder="Cari pelanggan…" data-filter="#tblPelanggan"></div>
      <?php
      $rows = []; $sn = 0; $so = 0.0;
      foreach ($dat['pelanggan'] as $i => $r) {
          $rows[] = [(string)($i + 1), e($r['nama_pelanggan']) . ($r['member'] ? ' <em class="lp-member">MEMBER</em>' : ''), e($r['kota']),
                     lapAngka($r['n']), '<b>' . lapRp($r['total']) . '</b>', e(tglID((string)$r['terakhir']))];
          $sn += (int)$r['n']; $so += (float)$r['total'];
      }
      echo str_replace('class="lp-table"', 'class="lp-table" id="tblPelanggan"',
          lapTabel(['#', 'Pelanggan', 'Kota', 'Transaksi', 'Total belanja', 'Terakhir'], $rows, [3 => 'r', 4 => 'r'],
              ['', 'Total', '', lapAngka($sn), lapRp($so), '']));
      ?>
    </div>
    <div class="panel">
      <div class="panel-head"><div><h3>Per kota</h3><p>Asal pelanggan</p></div></div>
      <?php
      $rows = [];
      foreach ($dat['kota'] as $r) $rows[] = [e($r['kota']), lapAngka($r['n']), '<b>' . lapRp($r['total']) . '</b>'];
      echo lapTabel(['Kota', 'Transaksi', 'Total'], $rows, [1 => 'r', 2 => 'r']);
      ?>
    </div>
  </div>
</section>

<!-- ============================ KASIR (admin) ============================ -->
<?php if ($lpAdm): ?>
<section class="lp-pane" data-pane="kasir">
  <div class="panel">
    <div class="panel-head"><div><h3>Kinerja kasir</h3><p>Transaksi lunas yang dilayani tiap akun</p></div></div>
    <?php
    $rows = []; $sn = 0; $so = 0.0;
    $maxK = $dat['kasir'] ? max(1, (float)$dat['kasir'][0]['omzet']) : 1;
    foreach ($dat['kasir'] as $r) {
        $avg = (int)$r['n'] > 0 ? (float)$r['omzet'] / (int)$r['n'] : 0;
        $rows[] = [e($r['nama']) . ' <small class="lp-muted">@' . e($r['username']) . '</small>', lapAngka($r['n']), '<b>' . lapRp($r['omzet']) . '</b>', lapRp($avg),
                   '<div class="lp-mini"><i style="--w:' . round((float)$r['omzet'] / $maxK * 100) . '%"></i></div>'];
        $sn += (int)$r['n']; $so += (float)$r['omzet'];
    }
    echo lapTabel(['Kasir', 'Transaksi', 'Omzet', 'Rata-rata', 'Kontribusi'], $rows, [1 => 'r', 2 => 'r', 3 => 'r'],
        ['Total', lapAngka($sn), lapRp($so), '', '']);
    ?>
  </div>
</section>
<?php endif ?>

<!-- ============================ TRANSAKSI ============================ -->
<section class="lp-pane" data-pane="transaksi">
  <div class="panel">
    <div class="panel-head"><div><h3>Daftar transaksi lunas</h3><p><?= count($dat['transaksi']) ?> transaksi<?= count($dat['transaksi']) >= 1000 ? ' (menampilkan 1.000 terbaru)' : '' ?></p></div>
      <input class="lp-search" type="search" placeholder="Cari invoice / pelanggan…" data-filter="#tblTrx"></div>
    <?php
    $rows = []; $so = 0.0; $sd = 0.0;
    foreach ($dat['transaksi'] as $r) {
        $rows[] = [e($r['kode_invoice'] ?: '#' . $r['id_transaksi']), e(tglID($r['tanggal'])), e($r['pelanggan']), e($r['kasir']),
                   e(lapMetodeLabel((string)$r['metode_pembayaran'])), lapRp($r['diskon_member']), '<b>' . lapRp($r['total_belanja']) . '</b>',
                   '<a class="lp-link" href="?page=bayar&id=' . (int)$r['id_transaksi'] . '">Detail</a>'];
        $so += (float)$r['total_belanja']; $sd += (float)$r['diskon_member'];
    }
    echo str_replace('class="lp-table"', 'class="lp-table" id="tblTrx"',
        lapTabel(['Invoice', 'Tanggal', 'Pelanggan', 'Kasir', 'Metode', 'Diskon member', 'Total', ''], $rows, [5 => 'r', 6 => 'r'],
            ['Total', '', '', '', '', lapRp($sd), lapRp($so), '']));
    ?>
  </div>
</section>

<!-- ===== Dokumen PDF (disembunyikan; dirender jadi file PDF saat tombol Unduh PDF diklik) ===== -->
<div class="pdf-host" aria-hidden="true">
  <div id="pdfDoc" class="pdf-doc">
    <div class="pdf-kop pdf-keep">
      <?php if ($LOGO_TOKO): ?><img src="<?= e($LOGO_TOKO) ?>" alt=""><?php endif ?>
      <div>
        <h1><?= e($NAMA_TOKO) ?></h1>
        <p><?= e($ALAMAT_TOKO) ?><?= $TELEPON_TOKO !== '' ? ' · Telp ' . e($TELEPON_TOKO) : '' ?></p>
      </div>
      <div class="pdf-title"><b>LAPORAN PENJUALAN</b><span><?= e($rentang) ?></span></div>
    </div>
    <div class="pdf-meta">Dibuat <?= date('d-m-Y H:i') ?> oleh <?= e($_SESSION['auth']['nama'] ?? '') ?> · <?= $lpAdm ? ($lpKasirTerpilih ? 'Kasir: ' . e($lpKasirTerpilih['nama']) : 'Semua kasir') : 'Kasir: ' . e($_SESSION['auth']['nama'] ?? 'Akun sendiri') ?> · Hanya transaksi lunas</div>

    <div class="pdf-kpis pdf-keep">
      <div><span>Total omzet</span><b><?= lapRp($kp['omzet']) ?></b></div>
      <div><span>Transaksi</span><b><?= lapAngka($kp['n']) ?></b></div>
      <div><span>Rata-rata / transaksi</span><b><?= lapRp($kp['avg']) ?></b></div>
      <div><span>Item terjual</span><b><?= lapAngka($kp['items']) ?></b></div>
    </div>

    <div class="pdf-sec pdf-keep"><h3>Grafik omzet</h3><img id="pdfChartImg" alt="" style="width:100%"></div>

    <div class="pdf-sec pdf-keep"><h3>Metode pembayaran</h3>
      <?php
      $rows = [];
      foreach ($dat['metode'] as $m) $rows[] = [e(lapMetodeLabel((string)$m['m'])), lapAngka($m['n']), lapRp($m['omzet']), round((float)$m['omzet'] / $metodeTot * 100) . '%'];
      echo lapTabel(['Metode', 'Transaksi', 'Omzet', 'Porsi'], $rows, [1 => 'r', 2 => 'r', 3 => 'r']);
      ?>
    </div>

    <div class="pdf-sec"><h3>Penjualan harian</h3>
      <?php
      $rows = []; $sn = 0; $si = 0; $sd = 0.0; $so = 0.0;
      foreach ($dat['harian'] as $r) {
          $rows[] = [e(tglID($r['tanggal'])), lapAngka($r['n']), lapAngka($r['items']), lapRp($r['diskon']), lapRp($r['omzet'])];
          $sn += $r['n']; $si += $r['items']; $sd += $r['diskon']; $so += $r['omzet'];
      }
      echo lapTabel(['Tanggal', 'Transaksi', 'Item', 'Diskon member', 'Omzet'], $rows, [1 => 'r', 2 => 'r', 3 => 'r', 4 => 'r'],
          ['Total', lapAngka($sn), lapAngka($si), lapRp($sd), lapRp($so)]);
      ?>
    </div>

    <div class="pdf-sec"><h3>Penjualan per produk</h3>
      <?php
      $rows = [];
      foreach (array_slice($dat['produk'], 0, 30) as $r) $rows[] = [e($r['nama_produk']), e($r['kategori']), lapAngka($r['qty']), lapRp($r['omzet']), lapAngka($r['stok'])];
      echo lapTabel(['Produk', 'Kategori', 'Terjual', 'Penjualan', 'Stok'], $rows, [2 => 'r', 3 => 'r', 4 => 'r']);
      ?>
    </div>

    <div class="pdf-sec"><h3>Pelanggan teratas</h3>
      <?php
      $rows = [];
      foreach (array_slice($dat['pelanggan'], 0, 20) as $i => $r) $rows[] = [(string)($i + 1), e($r['nama_pelanggan']), e($r['kota']), lapAngka($r['n']), lapRp($r['total'])];
      echo lapTabel(['#', 'Pelanggan', 'Kota', 'Transaksi', 'Total belanja'], $rows, [3 => 'r', 4 => 'r']);
      ?>
    </div>

    <?php if ($lpAdm): ?>
    <div class="pdf-sec pdf-keep"><h3>Kinerja kasir</h3>
      <?php
      $rows = [];
      foreach ($dat['kasir'] as $r) $rows[] = [e($r['nama']), lapAngka($r['n']), lapRp($r['omzet'])];
      echo lapTabel(['Kasir', 'Transaksi', 'Omzet'], $rows, [1 => 'r', 2 => 'r']);
      ?>
    </div>
    <?php endif ?>

    <div class="pdf-sec"><h3>Daftar transaksi<?= count($dat['transaksi']) > 150 ? ' (150 terbaru)' : '' ?></h3>
      <?php
      $rows = [];
      foreach (array_slice($dat['transaksi'], 0, 150) as $r) {
          $rows[] = [e($r['kode_invoice'] ?: '#' . $r['id_transaksi']), e(tglID($r['tanggal'])), e($r['pelanggan']), e(lapMetodeLabel((string)$r['metode_pembayaran'])), lapRp($r['total_belanja'])];
      }
      echo lapTabel(['Invoice', 'Tanggal', 'Pelanggan', 'Metode', 'Total'], $rows, [4 => 'r']);
      ?>
    </div>
    <div class="pdf-foot">Dokumen dibuat otomatis oleh sistem kasir <?= e($NAMA_TOKO) ?>.</div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" defer></script>
<script>
(function () {
  var seri = <?= json_encode($dat['seri'], JSON_UNESCAPED_UNICODE) ?>;
  var metode = <?= json_encode(array_map(static fn($m) => ['l' => lapMetodeLabel((string)$m['m']), 'v' => (float)$m['omzet']], $dat['metode']), JSON_UNESCAPED_UNICODE) ?>;
  var palet = <?= json_encode($palet) ?>;
  var fileBase = <?= json_encode($fileBase) ?>;
  var charts = [];

  function fmt(n) { return Math.round(n).toLocaleString('id-ID'); }
  function short(v) {
    if (v >= 1e9) return (v / 1e9).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' M';
    if (v >= 1e6) return (v / 1e6).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt';
    if (v >= 1e3) return Math.round(v / 1e3) + ' rb';
    return v;
  }

  // ---------- Tab (tanpa muat ulang halaman)
  var tabs = document.querySelectorAll('[data-tab]'), panes = document.querySelectorAll('[data-pane]');
  function show(name) {
    var found = false;
    panes.forEach(function (p) { if (p.dataset.pane === name) found = true; });
    if (!found) name = 'ringkasan';
    panes.forEach(function (p) { p.classList.toggle('on', p.dataset.pane === name); });
    tabs.forEach(function (t) { t.classList.toggle('on', t.dataset.tab === name); });
    if (name === 'ringkasan') charts.forEach(function (c) { c.resize(); });
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function (e) {
      e.preventDefault();
      history.replaceState(null, '', '#' + t.dataset.tab);
      show(t.dataset.tab);
    });
  });
  show((location.hash || '#ringkasan').slice(1));
  // pertahankan tab saat ganti periode
  document.querySelectorAll('.lp-chip').forEach(function (a) {
    a.addEventListener('click', function () { a.href = a.href.split('#')[0] + location.hash; });
  });
  var rf = document.getElementById('lpRange');
  if (rf) rf.addEventListener('submit', function () { rf.action = location.pathname + location.hash; });

  // ---------- Pencarian dalam tabel
  document.querySelectorAll('[data-filter]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.trim().toLowerCase();
      document.querySelectorAll(inp.dataset.filter + ' tbody tr').forEach(function (tr) {
        if (tr.classList.contains('lp-empty')) return;
        tr.style.display = tr.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
      });
    });
  });

  // ---------- Animasi angka KPI
  document.querySelectorAll('.lp-count').forEach(function (el) {
    var to = parseFloat(el.dataset.v) || 0, rp = el.dataset.f === 'rp', t0 = null;
    if (to === 0) return;
    function step(ts) {
      if (!t0) t0 = ts;
      var p = Math.min(1, (ts - t0) / 900), e = 1 - Math.pow(1 - p, 3), v = to * e;
      el.textContent = (rp ? 'Rp ' : '') + fmt(v);
      if (p < 1) requestAnimationFrame(step);
    }
    el.textContent = rp ? 'Rp 0' : '0';
    requestAnimationFrame(step);
  });

  // ---------- Grafik layar
  if (window.Chart) {
    Chart.defaults.font.family = "'DM Sans', sans-serif";
    var c1 = document.getElementById('lpChart');
    if (c1) charts.push(new Chart(c1, {
      data: {
        labels: seri.labels,
        datasets: [
          { type: 'bar', label: 'Omzet', data: seri.omzet, borderRadius: 6, maxBarThickness: 38, yAxisID: 'y',
            backgroundColor: function (ctx) {
              var a = ctx.chart.chartArea;
              if (!a) return '#8155f6';
              var g = ctx.chart.ctx.createLinearGradient(0, a.top, 0, a.bottom);
              g.addColorStop(0, '#a78bfa'); g.addColorStop(1, '#5936d2');
              return g;
            } },
          { type: 'line', label: 'Transaksi', data: seri.trx, borderColor: '#22c994', backgroundColor: '#22c994', tension: .35, pointRadius: 3, borderWidth: 2, yAxisID: 'y1' }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { labels: { color: '#9caac5', usePointStyle: true, boxWidth: 8 } },
          tooltip: { callbacks: { label: function (c) { return c.dataset.yAxisID === 'y' ? 'Omzet: Rp ' + fmt(c.parsed.y) : 'Transaksi: ' + c.parsed.y; } } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#9caac5', maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
          y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,.1)' }, ticks: { color: '#9caac5', callback: short } },
          y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { color: '#6fe0b8', precision: 0 } }
        }
      }
    }));
    var c2 = document.getElementById('lpDonut');
    if (c2) charts.push(new Chart(c2, {
      type: 'doughnut',
      data: { labels: metode.map(function (m) { return m.l; }), datasets: [{ data: metode.map(function (m) { return m.v; }), backgroundColor: palet, borderWidth: 0, hoverOffset: 6 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '68%',
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.label + ': Rp ' + fmt(c.parsed); } } } } }
    }));
  }

  // ---------- Unduh PDF (langsung jadi file, tanpa dialog cetak)
  function pdfChartImage() {
    if (!window.Chart) return null;
    var cv = document.createElement('canvas');
    cv.width = 1500; cv.height = 520;
    var ch = new Chart(cv, {
      type: 'bar',
      data: { labels: seri.labels, datasets: [{ label: 'Omzet (Rp)', data: seri.omzet, backgroundColor: '#6d4de0', borderRadius: 4 }] },
      options: { animation: false, responsive: false, devicePixelRatio: 1, plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#374151', maxRotation: 0, maxTicksLimit: 14, font: { size: 20 } } },
          y: { beginAtZero: true, grid: { color: '#e5e7eb' }, ticks: { color: '#374151', font: { size: 20 }, callback: short } }
        } },
      plugins: [{ id: 'bg', beforeDraw: function (c) { var x = c.ctx; x.save(); x.globalCompositeOperation = 'destination-over'; x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.restore(); } }]
    });
    var url = cv.toDataURL('image/png');
    ch.destroy();
    return url;
  }
  var btn = document.getElementById('btnPdf');
  btn.addEventListener('click', function () {
    if (!window.html2pdf) { alert('Pustaka PDF belum termuat (butuh koneksi internet). Silakan gunakan Unduh Excel.'); return; }
    var label = btn.innerHTML;
    btn.disabled = true; btn.classList.add('busy'); btn.textContent = 'Menyiapkan PDF…';
    try { var img = pdfChartImage(); if (img) document.getElementById('pdfChartImg').src = img; } catch (e) {}
    setTimeout(function () {
      html2pdf().set({
        margin: [10, 10, 12, 10], filename: fileBase + '.pdf',
        image: { type: 'jpeg', quality: .96 },
        html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', '.pdf-keep'] }
      }).from(document.getElementById('pdfDoc')).save().catch(function (e) {
        alert('Gagal membuat PDF: ' + (e && e.message ? e.message : e));
      }).then(function () {
        btn.disabled = false; btn.classList.remove('busy'); btn.innerHTML = label;
      });
    }, 150);
  });
})();
</script>
