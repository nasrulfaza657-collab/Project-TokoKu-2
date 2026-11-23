<?php
declare(strict_types=1);

$host = 'localhost';
$db   = 'db_tokoku2';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Koneksi database gagal. Periksa MySQL, nama database, username, dan password di config.php. Detail: ' . htmlspecialchars($e->getMessage()));
}

// ---------------------------------------------------------------------------
// Pengaturan aplikasi (disimpan di tabel tbl_pengaturan, diubah lewat menu Pengaturan)
// ---------------------------------------------------------------------------
const SETTING_DEFAULT = [
    'nama_toko'             => 'TokoKu',
    'alamat_toko'           => 'Ponorogo, Jawa Timur',
    'telepon_toko'          => '',
    'footer_struk'          => 'Terima kasih sudah berbelanja!',
    'lebar_struk'           => '80',
    'member_diskon_default' => '5',
    'payment_timeout_hours' => '24',
    'metode_aktif'          => 'tunai,qris,debit',
    'stok_menipis'          => '5',
    'qris_gambar'           => '',
    'logo_toko'             => '',
    'struk_barcode'         => '1',
];

function pengaturan_muat(PDO $pdo, bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    try {
        $rows = $pdo->query('SELECT kunci, nilai FROM tbl_pengaturan')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $x) {
        // Tabel belum ada (instalasi lama): buat sekali, lalu pakai nilai bawaan.
        $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_pengaturan (
            kunci varchar(60) NOT NULL,
            nilai text DEFAULT NULL,
            diubah_pada timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (kunci)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $rows = [];
    }
    $cache = array_merge(SETTING_DEFAULT, array_map(static fn($v) => (string)($v ?? ''), $rows));
    return $cache;
}

function setting(string $key): string {
    global $pdo;
    return pengaturan_muat($pdo)[$key] ?? (SETTING_DEFAULT[$key] ?? '');
}

function pengaturan_simpan(PDO $pdo, array $pairs): void {
    $st = $pdo->prepare('INSERT INTO tbl_pengaturan (kunci, nilai) VALUES (?, ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)');
    foreach ($pairs as $k => $v) {
        if (!array_key_exists($k, SETTING_DEFAULT)) continue; // hanya kunci yang dikenal
        $st->execute([$k, (string)$v]);
    }
    pengaturan_muat($pdo, true);
}

$NAMA_TOKO    = setting('nama_toko');
$ALAMAT_TOKO  = setting('alamat_toko');
$TELEPON_TOKO = setting('telepon_toko');
$FOOTER_STRUK = setting('footer_struk');
$LEBAR_STRUK  = setting('lebar_struk') === '58' ? 58 : 80;
$STRUK_BARCODE = setting('struk_barcode') !== '0';
$STOK_MENIPIS = max(0, (int)setting('stok_menipis'));
$LOGO_TOKO    = (setting('logo_toko') !== '' && is_file(__DIR__ . '/' . setting('logo_toko'))) ? setting('logo_toko') : '';

// ---------------------------------------------------------------------------
// Pengaturan pembayaran
// GANTI nomor rekening / e-wallet di bawah ini dengan milik toko Anda.
// ---------------------------------------------------------------------------
date_default_timezone_set('Asia/Jakarta');

// Batas waktu bayar (jam) diatur di menu Pengaturan > Transaksi & Member.
$PAYMENT_TIMEOUT_HOURS = max(1, (int)setting('payment_timeout_hours'));

$PAYMENT_METHODS = [
    // QRIS: letakkan gambar QRIS toko Anda (dari bank / PJP) di assets/qris.png.
    // Jika file itu tidak ada, halaman bayar menampilkan QR contoh (demo).
    'qris'      => ['label' => 'QRIS',               'hint' => 'Scan dengan e-wallet / m-banking apa pun', 'type' => 'qris',    'image' => 'assets/qris.png'],
    'bca'       => ['label' => 'Transfer Bank BCA',      'hint' => 'Transfer manual ke rekening toko', 'type' => 'bank',    'tujuan' => '1234567890',   'atas_nama' => 'TokoKu'],
    'bri'       => ['label' => 'Transfer Bank BRI',      'hint' => 'Transfer manual ke rekening toko', 'type' => 'bank',    'tujuan' => '098765432101', 'atas_nama' => 'TokoKu'],
    'mandiri'   => ['label' => 'Transfer Bank Mandiri',  'hint' => 'Transfer manual ke rekening toko', 'type' => 'bank',    'tujuan' => '1230009876543','atas_nama' => 'TokoKu'],
    'gopay'     => ['label' => 'GoPay',              'hint' => 'Kirim ke nomor GoPay toko',        'type' => 'ewallet', 'tujuan' => '081234567890', 'atas_nama' => 'TokoKu'],
    'dana'      => ['label' => 'DANA',               'hint' => 'Kirim ke nomor DANA toko',         'type' => 'ewallet', 'tujuan' => '081234567890', 'atas_nama' => 'TokoKu'],
    'shopeepay' => ['label' => 'ShopeePay',          'hint' => 'Kirim ke nomor ShopeePay toko',    'type' => 'ewallet', 'tujuan' => '081234567890', 'atas_nama' => 'TokoKu'],
];

// ---------------------------------------------------------------------------
// Member: potongan harga default (persen) untuk member baru.
// Admin dapat mengubah persen per member di menu Member.
// ---------------------------------------------------------------------------
// Nilai awal diatur di menu Pengaturan > Transaksi & Member.
$MEMBER_DISKON_DEFAULT = (float)setting('member_diskon_default');

// Gambar QRIS yang diunggah lewat menu Pengaturan menggantikan assets/qris.png
$qrisUnggahan = setting('qris_gambar');
if ($qrisUnggahan !== '' && is_file(__DIR__ . '/' . $qrisUnggahan)) {
    $PAYMENT_METHODS['qris']['image'] = $qrisUnggahan;
}

// ---------------------------------------------------------------------------
// Metode pembayaran kasir (POS). Yang tampil di checkout diatur di menu Pengaturan.
// ---------------------------------------------------------------------------
$METODE_POS = [
    'tunai' => ['label' => 'Tunai',       'hint' => 'Pembayaran langsung di kasir'],
    'qris'  => ['label' => 'QRIS',        'hint' => 'Pembayaran QR di toko'],
    'debit' => ['label' => 'Kartu Debit', 'hint' => 'Pembayaran melalui EDC'],
];
$METODE_AKTIF = array_values(array_filter(
    explode(',', setting('metode_aktif')),
    static fn($m) => isset($METODE_POS[$m])
));
if (!$METODE_AKTIF) $METODE_AKTIF = ['tunai']; // pengaman: minimal satu metode
