<?php
declare(strict_types=1);

/**
 * Pustaka Laporan TokoKu
 * - laporanPeriode(): membaca filter periode dari URL
 * - laporanData():    mengambil semua angka laporan (hanya transaksi LUNAS)
 * - laporanXlsx():    membuat file Excel (.xlsx) asli tanpa library tambahan (butuh ekstensi zip)
 * - lapTabel():       membuat tabel HTML yang sama untuk tampilan layar dan dokumen PDF
 */

const LAP_BULAN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

function tglID(string $ymd, bool $tahun = true): string {
    $t = strtotime($ymd);
    if ($t === false) return $ymd;
    return date('j', $t) . ' ' . LAP_BULAN[(int)date('n', $t) - 1] . ($tahun ? ' ' . date('Y', $t) : '');
}

function lapAngka($v): string {
    return number_format((float)$v, 0, ',', '.');
}

function lapRp($v): string {
    return 'Rp ' . number_format((float)$v, 0, ',', '.');
}

function lapMetodeLabel(?string $k): string {
    global $METODE_POS, $PAYMENT_METHODS;
    $k = (string)$k;
    if ($k === '') return 'Manual';
    return $METODE_POS[$k]['label'] ?? ($PAYMENT_METHODS[$k]['label'] ?? ucfirst($k));
}

function laporanPeriode(array $get): array {
    $hari = new DateTimeImmutable('today');
    $valid = ['hari_ini', '7_hari', '30_hari', 'bulan_ini', 'tahun_ini', 'semua', 'kustom'];
    $key = (string)($get['periode'] ?? '30_hari');
    if (!in_array($key, $valid, true)) $key = '30_hari';

    $dari = $hari;
    $sampai = $hari;
    $label = '';
    switch ($key) {
        case 'hari_ini':  $label = 'Hari ini'; break;
        case '7_hari':    $dari = $hari->modify('-6 days');  $label = '7 hari terakhir'; break;
        case '30_hari':   $dari = $hari->modify('-29 days'); $label = '30 hari terakhir'; break;
        case 'bulan_ini': $dari = $hari->modify('first day of this month'); $label = 'Bulan ini'; break;
        case 'tahun_ini': $dari = new DateTimeImmutable($hari->format('Y') . '-01-01'); $label = 'Tahun ini'; break;
        case 'semua':     $dari = new DateTimeImmutable('2000-01-01'); $label = 'Semua waktu'; break;
        case 'kustom':
            $rawD = (string)($get['dari'] ?? '');
            $rawS = (string)($get['sampai'] ?? '');
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $rawD);
            $errD = DateTimeImmutable::getLastErrors();
            $s = DateTimeImmutable::createFromFormat('!Y-m-d', $rawS);
            $errS = DateTimeImmutable::getLastErrors();
            $validD = $d && $d->format('Y-m-d') === $rawD && ($errD === false || ($errD['warning_count'] === 0 && $errD['error_count'] === 0));
            $validS = $s && $s->format('Y-m-d') === $rawS && ($errS === false || ($errS['warning_count'] === 0 && $errS['error_count'] === 0));
            if (!$validD || !$validS) {
                $key = '30_hari';
                $dari = $hari->modify('-29 days');
                $label = '30 hari terakhir';
                break;
            }
            if ($d > $s) [$d, $s] = [$s, $d];
            $dari = $d;
            $sampai = $s;
            $label = 'Periode kustom';
            break;
    }
    return ['key' => $key, 'label' => $label, 'dari' => $dari->format('Y-m-d'), 'sampai' => $sampai->format('Y-m-d')];
}

function laporanData(PDO $pdo, array $p, int $stokMin = 0, int $kasirId = 0): array {
    $dari = $p['dari'];
    $sampai = $p['sampai'];
    if ($p['key'] === 'semua') {
        if ($kasirId > 0) {
            $stMin = $pdo->prepare("SELECT DATE(MIN(tanggal)) FROM tbl_transaksi WHERE status_pembayaran='dibayar' AND id_user=?");
            $stMin->execute([$kasirId]);
            $min = $stMin->fetchColumn();
        } else {
            $min = $pdo->query("SELECT DATE(MIN(tanggal)) FROM tbl_transaksi WHERE status_pembayaran='dibayar'")->fetchColumn();
        }
        if ($min) $dari = (string)$min;
        if ($dari > $sampai) $dari = $sampai;
    }

    // Gunakan batas akhir eksklusif agar transaksi pada jam berapa pun di
    // tanggal terakhir tetap masuk, termasuk bila kolom tanggal bertipe DATETIME.
    $W = "t.status_pembayaran='dibayar' AND t.tanggal >= ? AND t.tanggal < DATE_ADD(?, INTERVAL 1 DAY)";
    $A = [$dari, $sampai];
    // Semua query laporan memakai scope kasir yang sama, termasuk pembanding dan ekspor.
    if ($kasirId > 0) {
        $W .= ' AND t.id_user = ?';
        $A[] = $kasirId;
    }
    $q = static function (string $sql, array $args) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    };
    $one = static function (string $sql, array $args) use ($q): array {
        return $q($sql, $args)[0] ?? [];
    };

    $d1 = new DateTimeImmutable($dari);
    $d2 = new DateTimeImmutable($sampai);
    $hari = (int)$d1->diff($d2)->days + 1;

    // ---- Angka utama + pembanding periode sebelumnya
    $k = $one("SELECT COUNT(*) n, COALESCE(SUM(t.total_belanja),0) omzet, COALESCE(SUM(t.diskon_member),0) diskon,
                      COUNT(DISTINCT t.id_pelanggan) pelanggan
               FROM tbl_transaksi t WHERE $W", $A);
    $items = (int)($one("SELECT COALESCE(SUM(d.jumlah),0) c FROM tbl_detail_transaksi d
                         JOIN tbl_transaksi t ON t.id_transaksi=d.id_transaksi WHERE $W", $A)['c'] ?? 0);
    $n = (int)($k['n'] ?? 0);
    $omzet = (float)($k['omzet'] ?? 0);
    $prev = null;
    if ($p['key'] !== 'semua') {
        $pS = $d1->modify('-1 day');
        $pD = $pS->modify('-' . ($hari - 1) . ' days');
        $prevArgs = [$pD->format('Y-m-d'), $pS->format('Y-m-d')];
        if ($kasirId > 0) $prevArgs[] = $kasirId;
        $pr = $one("SELECT COUNT(*) n, COALESCE(SUM(t.total_belanja),0) omzet FROM tbl_transaksi t WHERE $W", $prevArgs);
        $prev = ['n' => (int)($pr['n'] ?? 0), 'omzet' => (float)($pr['omzet'] ?? 0)];
    }
    $kpi = [
        'n' => $n, 'omzet' => $omzet, 'items' => $items,
        'diskon' => (float)($k['diskon'] ?? 0), 'pelanggan' => (int)($k['pelanggan'] ?? 0),
        'avg' => $n > 0 ? $omzet / $n : 0.0, 'hari' => $hari, 'prev' => $prev,
    ];

    // ---- Per hari
    $hr = $q("SELECT t.tanggal, COUNT(*) n, COALESCE(SUM(t.total_belanja),0) omzet, COALESCE(SUM(t.diskon_member),0) diskon
              FROM tbl_transaksi t WHERE $W GROUP BY t.tanggal ORDER BY t.tanggal", $A);
    $hi = $q("SELECT t.tanggal, COALESCE(SUM(d.jumlah),0) qty FROM tbl_detail_transaksi d
              JOIN tbl_transaksi t ON t.id_transaksi=d.id_transaksi WHERE $W GROUP BY t.tanggal", $A);
    $qtyByDate = [];
    foreach ($hi as $r) $qtyByDate[(string)$r['tanggal']] = (int)$r['qty'];
    $byDate = [];
    $harian = [];
    foreach ($hr as $r) {
        $tg = (string)$r['tanggal'];
        $row = ['tanggal' => $tg, 'n' => (int)$r['n'], 'items' => $qtyByDate[$tg] ?? 0,
                'diskon' => (float)$r['diskon'], 'omzet' => (float)$r['omzet']];
        $byDate[$tg] = $row;
        $harian[] = $row;
    }

    // ---- Deret grafik (harian; bila rentang > 92 hari dikelompokkan per bulan)
    $labels = [];
    $omz = [];
    $trx = [];
    if ($hari <= 92) {
        for ($i = 0; $i < $hari; $i++) {
            $key = $d1->modify("+$i days")->format('Y-m-d');
            $labels[] = tglID($key, false);
            $omz[] = (float)($byDate[$key]['omzet'] ?? 0);
            $trx[] = (int)($byDate[$key]['n'] ?? 0);
        }
    } else {
        $bln = [];
        foreach ($harian as $r) {
            $mk = substr($r['tanggal'], 0, 7);
            $bln[$mk]['omzet'] = ($bln[$mk]['omzet'] ?? 0) + $r['omzet'];
            $bln[$mk]['n'] = ($bln[$mk]['n'] ?? 0) + $r['n'];
        }
        $c = new DateTimeImmutable($d1->format('Y-m-01'));
        while ($c <= $d2) {
            $mk = $c->format('Y-m');
            $labels[] = LAP_BULAN[(int)$c->format('n') - 1] . ' ' . $c->format('Y');
            $omz[] = (float)($bln[$mk]['omzet'] ?? 0);
            $trx[] = (int)($bln[$mk]['n'] ?? 0);
            $c = $c->modify('+1 month');
        }
    }

    // ---- Metode pembayaran
    $metode = $q("SELECT COALESCE(NULLIF(t.metode_pembayaran,''),'manual') m, COUNT(*) n, COALESCE(SUM(t.total_belanja),0) omzet
                  FROM tbl_transaksi t WHERE $W GROUP BY COALESCE(NULLIF(t.metode_pembayaran,''),'manual') ORDER BY omzet DESC", $A);

    // ---- Produk & kategori
    $produk = $q("SELECT pr.id_produk, pr.nama_produk, COALESCE(pr.kategori,'-') kategori, pr.stok,
                         SUM(d.jumlah) qty, SUM(d.subtotal) omzet
                  FROM tbl_detail_transaksi d
                  JOIN tbl_transaksi t ON t.id_transaksi=d.id_transaksi
                  JOIN tbl_produk pr ON pr.id_produk=d.id_produk
                  WHERE $W GROUP BY pr.id_produk, pr.nama_produk, pr.kategori, pr.stok ORDER BY omzet DESC", $A);
    $kategori = $q("SELECT COALESCE(NULLIF(pr.kategori,''),'-') kategori, SUM(d.jumlah) qty, SUM(d.subtotal) omzet
                    FROM tbl_detail_transaksi d
                    JOIN tbl_transaksi t ON t.id_transaksi=d.id_transaksi
                    JOIN tbl_produk pr ON pr.id_produk=d.id_produk
                    WHERE $W GROUP BY COALESCE(NULLIF(pr.kategori,''),'-') ORDER BY omzet DESC", $A);
    $stokRendah = [];
    if ($stokMin > 0) {
        $stokRendah = $q("SELECT nama_produk, COALESCE(kategori,'-') kategori, stok FROM tbl_produk WHERE stok<=? ORDER BY stok, nama_produk", [$stokMin]);
    }

    // ---- Pelanggan & kota
    $pelanggan = $q("SELECT p.id_pelanggan, p.nama_pelanggan, COALESCE(NULLIF(p.kota,''),'-') kota, COUNT(*) n,
                            SUM(t.total_belanja) total, MAX(t.tanggal) terakhir,
                            MAX(CASE WHEN t.id_member IS NULL THEN 0 ELSE 1 END) member
                     FROM tbl_transaksi t JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan
                     WHERE $W GROUP BY p.id_pelanggan, p.nama_pelanggan, p.kota ORDER BY total DESC", $A);
    $kota = $q("SELECT COALESCE(NULLIF(p.kota,''),'-') kota, COUNT(*) n, SUM(t.total_belanja) total
                FROM tbl_transaksi t JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan
                WHERE $W GROUP BY COALESCE(NULLIF(p.kota,''),'-') ORDER BY total DESC", $A);

    // ---- Kasir
    $kasir = $q("SELECT COALESCE(u.nama,'Tidak tercatat') nama, COALESCE(u.username,'-') username, COUNT(*) n,
                        COALESCE(SUM(t.total_belanja),0) omzet
                 FROM tbl_transaksi t LEFT JOIN tbl_user u ON u.id_user=t.id_user
                 WHERE $W GROUP BY u.id_user, u.nama, u.username ORDER BY omzet DESC", $A);

    // ---- Daftar transaksi
    $transaksi = $q("SELECT t.id_transaksi, t.kode_invoice, t.tanggal, COALESCE(p.nama_pelanggan,'-') pelanggan,
                            COALESCE(u.nama,'-') kasir, t.metode_pembayaran, t.diskon_member, t.total_belanja
                     FROM tbl_transaksi t
                     LEFT JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan
                     LEFT JOIN tbl_user u ON u.id_user=t.id_user
                     WHERE $W ORDER BY t.tanggal DESC, t.id_transaksi DESC LIMIT 1000", $A);

    return [
        'dari' => $dari, 'sampai' => $sampai, 'kpi' => $kpi,
        'seri' => ['labels' => $labels, 'omzet' => $omz, 'trx' => $trx],
        'harian' => array_reverse($harian), 'metode' => $metode,
        'produk' => $produk, 'kategori' => $kategori, 'stokRendah' => $stokRendah,
        'pelanggan' => $pelanggan, 'kota' => $kota, 'kasir' => $kasir, 'transaksi' => $transaksi,
    ];
}

/** Tabel HTML. $rows berisi sel yang sudah di-escape (boleh berisi HTML). $align: 'r' = rata kanan. */
function lapTabel(array $head, array $rows, array $align = [], array $foot = [], string $empty = 'Belum ada data pada periode ini.'): string {
    $cls = static fn(int $i): string => (($align[$i] ?? '') === 'r') ? ' class="r"' : '';
    $h = '<div class="lp-tw"><table class="lp-table"><thead><tr>';
    foreach ($head as $i => $c) $h .= '<th' . $cls($i) . '>' . htmlspecialchars((string)$c, ENT_QUOTES, 'UTF-8') . '</th>';
    $h .= '</tr></thead><tbody>';
    if (!$rows) {
        $h .= '<tr class="lp-empty"><td colspan="' . max(1, count($head)) . '">' . htmlspecialchars($empty, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach ($r as $i => $c) $h .= '<td' . $cls($i) . '>' . $c . '</td>';
        $h .= '</tr>';
    }
    $h .= '</tbody>';
    if ($foot && $rows) {
        $h .= '<tfoot><tr>';
        foreach ($foot as $i => $c) $h .= '<td' . $cls($i) . '>' . $c . '</td>';
        $h .= '</tr></tfoot>';
    }
    return $h . '</table></div>';
}

// ===================================================================== Excel (.xlsx)

function xlsxEsc(string $s): string {
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsxCol(int $i): string {
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

// Gaya sel: 0 biasa, 1 judul kolom, 2 rupiah, 3 tebal, 4 judul laporan, 5 total rupiah, 6 catatan, 7 teks bergaris, 8 angka bergaris, 9 total teks
function xlsxSheetXml(array $rows, array $widths): string {
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    if ($widths) {
        $x .= '<cols>';
        foreach ($widths as $i => $w) {
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $x .= '</cols>';
    }
    $x .= '<sheetData>';
    foreach ($rows as $ri => $row) {
        $r = $ri + 1;
        $x .= '<row r="' . $r . '">';
        foreach ($row as $ci => $cell) {
            $v = is_array($cell) ? ($cell['v'] ?? null) : $cell;
            $s = is_array($cell) ? (int)($cell['s'] ?? 0) : 0;
            if ($v === null || $v === '') continue;
            $ref = xlsxCol((int)$ci) . $r;
            if (is_int($v) || is_float($v)) {
                $x .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxEsc((string)$v) . '</t></is></c>';
            }
        }
        $x .= '</row>';
    }
    return $x . '</sheetData></worksheet>';
}

function xlsxStylesXml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;Rp&quot;\ #,##0"/></numFmts>'
    . '<fonts count="5">'
    . '<font><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="15"/><color rgb="FF3B2A8F"/><name val="Calibri"/></font>'
    . '<font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="4">'
    . '<fill><patternFill patternType="none"/></fill>'
    . '<fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF5B3FD0"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF0FA"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="2">'
    . '<border><left/><right/><top/><bottom/><diagonal/></border>'
    . '<border><left/><right/><top/><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>'
    . '</borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="10">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>'
    . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
    . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '<xf numFmtId="164" fontId="2" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
    . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
    . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
    . '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';
}

/** Susun satu sheet berisi judul, catatan, tabel, dan baris total (opsional). */
function xlsxTable(string $judul, string $catatan, array $head, array $rows, array $tipe, array $total = []): array {
    $out = [[['v' => $judul, 's' => 4]]];
    if ($catatan !== '') $out[] = [['v' => $catatan, 's' => 6]];
    $out[] = [];
    $hr = [];
    foreach ($head as $h) $hr[] = ['v' => $h, 's' => 1];
    $out[] = $hr;
    foreach ($rows as $r) {
        $line = [];
        foreach ($r as $i => $v) {
            $t = $tipe[$i] ?? 't';
            $line[] = ['v' => $v, 's' => $t === 'rp' ? 2 : ($t === 'n' ? 8 : 7)];
        }
        $out[] = $line;
    }
    if ($total && $rows) {
        $line = [];
        foreach ($total as $i => $v) {
            $t = $tipe[$i] ?? 't';
            $line[] = ['v' => $v, 's' => $t === 'rp' ? 5 : 9];
        }
        $out[] = $line;
    }
    return $out;
}

function laporanXlsx(array $p, array $d, string $toko, bool $admin): string {
    $periode = tglID($d['dari']) . ' s/d ' . tglID($d['sampai']);
    $k = $d['kpi'];
    $kop = 'Laporan Penjualan ' . $toko;
    $cat = 'Periode: ' . $periode . ' | Hanya transaksi lunas | Dibuat ' . date('d-m-Y H:i');
    $sheets = [];

    // 1. Ringkasan
    $rs = [[['v' => $kop, 's' => 4]], [['v' => $cat, 's' => 6]], []];
    $rs[] = [['v' => 'Indikator', 's' => 1], ['v' => 'Nilai', 's' => 1]];
    $kv = [
        ['Total omzet', $k['omzet'], 2], ['Jumlah transaksi', $k['n'], 8], ['Rata-rata per transaksi', round($k['avg']), 2],
        ['Item terjual', $k['items'], 8], ['Total diskon member', $k['diskon'], 2], ['Pelanggan bertransaksi', $k['pelanggan'], 8],
    ];
    foreach ($kv as [$l, $v, $s]) $rs[] = [['v' => $l, 's' => 7], ['v' => $v, 's' => $s]];
    $rs[] = [];
    $rs[] = [['v' => 'Metode pembayaran', 's' => 1], ['v' => 'Transaksi', 's' => 1], ['v' => 'Omzet', 's' => 1]];
    foreach ($d['metode'] as $m) {
        $rs[] = [['v' => lapMetodeLabel((string)$m['m']), 's' => 7], ['v' => (int)$m['n'], 's' => 8], ['v' => (float)$m['omzet'], 's' => 2]];
    }
    $sheets[] = ['Ringkasan', $rs, [30, 18, 20]];

    // 2. Penjualan harian
    $rows = [];
    $sn = 0; $si = 0; $sd = 0.0; $so = 0.0;
    foreach ($d['harian'] as $r) {
        $rows[] = [tglID($r['tanggal']), $r['n'], $r['items'], $r['diskon'], $r['omzet']];
        $sn += $r['n']; $si += $r['items']; $sd += $r['diskon']; $so += $r['omzet'];
    }
    $sheets[] = ['Penjualan Harian', xlsxTable($kop, $cat, ['Tanggal', 'Transaksi', 'Item terjual', 'Diskon member', 'Omzet'], $rows,
        ['t', 'n', 'n', 'rp', 'rp'], ['TOTAL', $sn, $si, $sd, $so]), [18, 12, 14, 18, 20]];

    // 3. Produk
    $rows = [];
    $sq = 0; $so = 0.0;
    foreach ($d['produk'] as $r) {
        $rows[] = [$r['nama_produk'], $r['kategori'], (int)$r['qty'], (float)$r['omzet'], (int)$r['stok']];
        $sq += (int)$r['qty']; $so += (float)$r['omzet'];
    }
    $sheets[] = ['Produk', xlsxTable('Penjualan per Produk', $cat . ' | Omzet = harga x jumlah sebelum diskon member',
        ['Produk', 'Kategori', 'Terjual', 'Penjualan', 'Stok sekarang'], $rows, ['t', 't', 'n', 'rp', 'n'], ['TOTAL', '', $sq, $so, '']), [34, 18, 12, 20, 14]];

    // 4. Pelanggan
    $rows = [];
    $sn = 0; $so = 0.0;
    foreach ($d['pelanggan'] as $r) {
        $rows[] = [$r['nama_pelanggan'], $r['kota'], (int)$r['n'], (float)$r['total'], tglID((string)$r['terakhir']), $r['member'] ? 'Member' : 'Umum'];
        $sn += (int)$r['n']; $so += (float)$r['total'];
    }
    $sheets[] = ['Pelanggan', xlsxTable('Pelanggan Teratas', $cat, ['Pelanggan', 'Kota', 'Transaksi', 'Total belanja', 'Terakhir belanja', 'Status'],
        $rows, ['t', 't', 'n', 'rp', 't', 't'], ['TOTAL', '', $sn, $so, '', '']), [28, 18, 12, 20, 18, 12]];

    // 5. Kasir (admin)
    if ($admin) {
        $rows = [];
        $sn = 0; $so = 0.0;
        foreach ($d['kasir'] as $r) {
            $rows[] = [$r['nama'], $r['username'], (int)$r['n'], (float)$r['omzet'], $r['n'] > 0 ? round((float)$r['omzet'] / (int)$r['n']) : 0];
            $sn += (int)$r['n']; $so += (float)$r['omzet'];
        }
        $sheets[] = ['Kasir', xlsxTable('Kinerja Kasir', $cat, ['Nama', 'Username', 'Transaksi', 'Omzet', 'Rata-rata'], $rows,
            ['t', 't', 'n', 'rp', 'rp'], ['TOTAL', '', $sn, $so, '']), [26, 16, 12, 20, 18]];
    }

    // 6. Transaksi
    $rows = [];
    $so = 0.0; $sd = 0.0;
    foreach ($d['transaksi'] as $r) {
        $rows[] = [$r['kode_invoice'] ?: ('#' . $r['id_transaksi']), tglID($r['tanggal']), $r['pelanggan'], $r['kasir'],
                   lapMetodeLabel((string)$r['metode_pembayaran']), (float)$r['diskon_member'], (float)$r['total_belanja']];
        $so += (float)$r['total_belanja']; $sd += (float)$r['diskon_member'];
    }
    $sheets[] = ['Transaksi', xlsxTable('Daftar Transaksi Lunas', $cat, ['Invoice', 'Tanggal', 'Pelanggan', 'Kasir', 'Metode', 'Diskon member', 'Total'],
        $rows, ['t', 't', 't', 't', 't', 'rp', 'rp'], ['TOTAL', '', '', '', '', $sd, $so]), [22, 14, 26, 20, 14, 16, 18]];

    // ---- Rakit paket .xlsx
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $wr = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheets as $i => $sh) {
        $n = $i + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $wb .= '<sheet name="' . xlsxEsc($sh[0]) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $wr .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    }
    $ct .= '</Types>';
    $wb .= '</sheets></workbook>';
    $wr .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'lap');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Gagal membuat file Excel.');
    }
    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wr);
    $zip->addFromString('xl/styles.xml', xlsxStylesXml());
    foreach ($sheets as $i => $sh) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', xlsxSheetXml($sh[1], $sh[2]));
    }
    $zip->close();
    $bin = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
