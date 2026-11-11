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
// Pengaturan pembayaran
// GANTI nomor rekening / e-wallet di bawah ini dengan milik toko Anda.
// ---------------------------------------------------------------------------
date_default_timezone_set('Asia/Jakarta');

$PAYMENT_TIMEOUT_HOURS = 24; // batas waktu bayar, setelah itu pesanan otomatis kedaluwarsa & stok dikembalikan

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
