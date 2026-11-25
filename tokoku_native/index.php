<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

// Pastikan kolom katalog ada di tbl_produk (untuk database lama seperti db_tokoku2).
// Aman dijalankan berulang: hanya menambah kolom yang belum ada.
(function (PDO $pdo): void {
    $has = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='tbl_produk'")->fetchColumn();
    if (!$has) {
        $pdo->exec("CREATE TABLE tbl_produk (
            id_produk int(11) NOT NULL AUTO_INCREMENT,
            nama_produk varchar(150) NOT NULL,
            kategori varchar(100) DEFAULT NULL,
            harga decimal(12,2) NOT NULL DEFAULT 0.00,
            stok int(11) NOT NULL DEFAULT 0,
            deskripsi text DEFAULT NULL,
            dibuat_pada timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id_produk)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    $st = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tbl_produk'");
    $cols = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    $add = [
        'diskon'  => "ADD COLUMN diskon decimal(5,2) NOT NULL DEFAULT 0",
        'gambar'  => "ADD COLUMN gambar varchar(500) DEFAULT NULL",
        'terjual' => "ADD COLUMN terjual int(11) NOT NULL DEFAULT 0",
        'rating'  => "ADD COLUMN rating decimal(2,1) NOT NULL DEFAULT 5.0",
        'barcode' => "ADD COLUMN barcode varchar(32) DEFAULT NULL",
    ];
    $todo = [];
    foreach ($add as $c => $ddl) {
        if (!in_array($c, $cols, true)) $todo[] = $ddl;
    }
    if ($todo) $pdo->exec('ALTER TABLE tbl_produk ' . implode(', ', $todo));

    // Barcode produk: isi otomatis (12 digit) untuk produk yang belum punya, lalu pasang unique index
    $pdo->exec("UPDATE tbl_produk SET barcode=NULL WHERE barcode=''");
    $pdo->exec("UPDATE tbl_produk SET barcode=CONCAT('20', LPAD(id_produk,10,'0')) WHERE barcode IS NULL");
    $idx = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tbl_produk' AND index_name='uk_produk_barcode'")->fetchColumn();
    if (!$idx) $pdo->exec("ALTER TABLE tbl_produk ADD UNIQUE KEY uk_produk_barcode (barcode)");

    // Kolom pembayaran di tbl_transaksi
    $st = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tbl_transaksi'");
    $tc = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    $addT = [
        'status_pembayaran' => "ADD COLUMN status_pembayaran enum('menunggu','dibayar','dibatalkan','kedaluwarsa') NOT NULL DEFAULT 'dibayar'",
        'metode_pembayaran' => "ADD COLUMN metode_pembayaran varchar(30) DEFAULT NULL",
        'kode_invoice'      => "ADD COLUMN kode_invoice varchar(30) DEFAULT NULL",
        'batas_bayar'       => "ADD COLUMN batas_bayar datetime DEFAULT NULL",
        'dibayar_pada'      => "ADD COLUMN dibayar_pada datetime DEFAULT NULL",
        'bukti_bayar'       => "ADD COLUMN bukti_bayar varchar(255) DEFAULT NULL",
        'id_member'         => "ADD COLUMN id_member int(11) DEFAULT NULL",
        'diskon_member'     => "ADD COLUMN diskon_member decimal(12,2) NOT NULL DEFAULT 0",
        'diskon_member_persen' => "ADD COLUMN diskon_member_persen decimal(5,2) NOT NULL DEFAULT 0",
    ];
    $todoT = [];
    foreach ($addT as $c => $ddl) {
        if (!in_array($c, $tc, true)) $todoT[] = $ddl;
    }
    if ($todoT) $pdo->exec('ALTER TABLE tbl_transaksi ' . implode(', ', $todoT));

    // Tabel detail transaksi
    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_detail_transaksi (
        id_detail int(11) NOT NULL AUTO_INCREMENT,
        id_transaksi int(11) NOT NULL,
        id_produk int(11) NOT NULL,
        jumlah int(11) NOT NULL,
        harga_satuan decimal(12,2) NOT NULL,
        subtotal decimal(12,2) NOT NULL,
        PRIMARY KEY (id_detail),
        KEY idx_detail_transaksi (id_transaksi),
        KEY fk_detail_produk (id_produk),
        CONSTRAINT fk_detail_produk FOREIGN KEY (id_produk) REFERENCES tbl_produk (id_produk),
        CONSTRAINT fk_detail_transaksi FOREIGN KEY (id_transaksi) REFERENCES tbl_transaksi (id_transaksi) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tabel member (member = pelanggan yang didaftarkan, dapat potongan harga)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_member (
        id_member int(11) NOT NULL AUTO_INCREMENT,
        id_pelanggan int(11) NOT NULL,
        kode_member varchar(32) DEFAULT NULL,
        kode_rfid char(64) DEFAULT NULL,
        no_hp varchar(20) DEFAULT NULL,
        diskon_persen decimal(5,2) NOT NULL DEFAULT 5.00,
        aktif tinyint(1) NOT NULL DEFAULT 1,
        bergabung_pada timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id_member),
        UNIQUE KEY uk_member_pelanggan (id_pelanggan),
        UNIQUE KEY uk_member_kode (kode_member),
        UNIQUE KEY uk_member_rfid (kode_rfid),
        CONSTRAINT fk_member_pelanggan FOREIGN KEY (id_pelanggan) REFERENCES tbl_pelanggan (id_pelanggan) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
})($pdo);

// Akun staf
$tipeRole = (string)$pdo->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tbl_user' AND column_name='role'")->fetchColumn();
if (!str_contains($tipeRole, "'kasir'")) {
    $pdo->exec("ALTER TABLE tbl_user MODIFY role ENUM('admin','kasir','user') NOT NULL DEFAULT 'kasir'");
}
$hasKartu = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tbl_user' AND column_name='kode_kartu'")->fetchColumn();
if (!$hasKartu) $pdo->exec("ALTER TABLE tbl_user ADD COLUMN kode_kartu char(64) DEFAULT NULL, ADD UNIQUE KEY uk_user_kartu (kode_kartu)");
$hasRfid = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tbl_user' AND column_name='kode_rfid'")->fetchColumn();
if (!$hasRfid) $pdo->exec("ALTER TABLE tbl_user ADD COLUMN kode_rfid char(64) DEFAULT NULL, ADD UNIQUE KEY uk_user_rfid (kode_rfid)");
$checkUser = $pdo->prepare('SELECT COUNT(*) FROM tbl_user WHERE username=?');
$checkUser->execute(['adminbaru']);
if (!(int)$checkUser->fetchColumn()) {
    $pdo->prepare("INSERT INTO tbl_user(nama,username,password,role) VALUES(?,?,?,'admin')")
        ->execute(['Administrator Toko', 'adminbaru', password_hash('Admin123!', PASSWORD_DEFAULT)]);
}
$checkUser->execute(['kasir']);
if (!(int)$checkUser->fetchColumn()) {
    $pdo->prepare("INSERT INTO tbl_user(nama,username,password,role) VALUES(?,?,?,'kasir')")
        ->execute(['Petugas Kasir', 'kasir', password_hash('Kasir123!', PASSWORD_DEFAULT)]);
}

// ---------------------------------------------------------------- helper upload gambar
function uploadImage(string $field, string $subdir): ?string {
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('Ukuran gambar terlalu besar (maksimal 2 MB).');
    }
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Gagal mengunggah gambar. Coba lagi.');
    if ($f['size'] > 2 * 1024 * 1024) throw new RuntimeException('Ukuran gambar terlalu besar (maksimal 2 MB).');
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('File harus berupa gambar JPG, PNG, WEBP, atau GIF.');
    }
    $dir = __DIR__ . '/assets/uploads/' . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Folder upload tidak bisa dibuat. Periksa izin folder assets.');
    }
    $ht = __DIR__ . '/assets/uploads/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|phar|php5)$\">\nRequire all denied\n</FilesMatch>\n");
    }
    $name = bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Gagal menyimpan gambar ke folder upload.');
    }
    return 'assets/uploads/' . $subdir . '/' . $name;
}

function deleteLocalImage(?string $path): void {
    if ($path && str_starts_with($path, 'assets/uploads/')) {
        $full = __DIR__ . '/' . $path;
        if (is_file($full)) @unlink($full);
    }
}

// ---------------------------------------------------------------- helper barcode
function makeBarcode(int $id): string {
    return '20' . str_pad((string)$id, 10, '0', STR_PAD_LEFT);
}

function makeMemberCode(int $id): string {
    return '30' . str_pad((string)$id, 10, '0', STR_PAD_LEFT);
}

// Cari member dari kode member (barcode), UID kartu RFID, atau nomor HP
function findMember(PDO $pdo, string $code): ?array {
    $code = trim($code);
    if ($code === '') return null;
    $st = $pdo->prepare('SELECT m.*, p.nama_pelanggan, p.kota FROM tbl_member m JOIN tbl_pelanggan p ON p.id_pelanggan=m.id_pelanggan WHERE m.kode_member=? OR m.kode_rfid=? OR m.no_hp=? LIMIT 1');
    $st->execute([$code, hash('sha256', strtoupper($code)), $code]);
    $r = $st->fetch();
    return $r ?: null;
}

// Member yang sedang dipilih di keranjang (hanya jika masih aktif)
function currentMember(PDO $pdo): ?array {
    $id = (int)($_SESSION['member_id'] ?? 0);
    if ($id < 1) return null;
    $st = $pdo->prepare('SELECT m.*, p.nama_pelanggan, p.kota FROM tbl_member m JOIN tbl_pelanggan p ON p.id_pelanggan=m.id_pelanggan WHERE m.id_member=? AND m.aktif=1');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) { unset($_SESSION['member_id']); return null; }
    return $r;
}

// Kotak input untuk scanner barcode (scanner USB bekerja seperti keyboard + Enter)
function scanBar(string $back): string {
    $b = $back === 'keranjang' ? 'keranjang' : 'produk';
    return '<form method="post" class="scan-bar" autocomplete="off">'
        . '<input type="hidden" name="action" value="scan_cart">'
        . '<input type="hidden" name="back" value="' . $b . '">'
        . '<span class="scan-ic">▮▯▮▮▯▮</span>'
        . '<input type="text" name="kode" id="scanInput" placeholder="Scan barcode produk / kartu member (barcode atau RFID) di sini" autofocus>'
        . '<button class="btn primary">Tambah</button>'
        . '</form>'
        . '<script>(function(){var i=document.getElementById("scanInput");if(!i)return;'
        . 'document.addEventListener("keydown",function(ev){var t=ev.target.tagName;'
        . 'if(ev.ctrlKey||ev.metaKey||ev.altKey||t==="INPUT"||t==="TEXTAREA"||t==="SELECT")return;'
        . 'if(ev.key&&ev.key.length===1)i.focus();});})();</script>';
}

// ---------------------------------------------------------------- helper pesanan
function releaseStock(PDO $pdo, int $idTrx): void {
    $pdo->prepare('UPDATE tbl_produk p JOIN tbl_detail_transaksi d ON d.id_produk=p.id_produk SET p.stok=p.stok+d.jumlah WHERE d.id_transaksi=?')
        ->execute([$idTrx]);
}

function expireOrders(PDO $pdo): void {
    $st = $pdo->prepare("SELECT id_transaksi FROM tbl_transaksi WHERE status_pembayaran='menunggu' AND batas_bayar IS NOT NULL AND batas_bayar < ?");
    $st->execute([date('Y-m-d H:i:s')]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->beginTransaction();
        $u = $pdo->prepare("UPDATE tbl_transaksi SET status_pembayaran='kedaluwarsa' WHERE id_transaksi=? AND status_pembayaran='menunggu'");
        $u->execute([$id]);
        if ($u->rowCount() > 0) releaseStock($pdo, (int)$id);
        $pdo->commit();
    }
}
expireOrders($pdo);

// Perapian satu kali: versi lama membuat baris "Pelanggan Umum" baru di setiap penjualan.
// Gabungkan semuanya ke satu baris (transaksi tetap utuh, member tidak disentuh).
if (setting('umum_dirapikan') !== '1') {
    $umum = "p.nama_pelanggan='Pelanggan Umum' AND (p.kota IS NULL OR p.kota='')";
    $bukanMember = "NOT EXISTS (SELECT 1 FROM tbl_member m WHERE m.id_pelanggan=p.id_pelanggan)";
    $keepId = (int)$pdo->query("SELECT MIN(p.id_pelanggan) FROM tbl_pelanggan p WHERE $umum AND $bukanMember")->fetchColumn();
    if ($keepId > 0) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE tbl_transaksi t JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan SET t.id_pelanggan=? WHERE $umum AND p.id_pelanggan<>? AND $bukanMember")
                ->execute([$keepId, $keepId]);
            $pdo->prepare("DELETE p FROM tbl_pelanggan p WHERE $umum AND p.id_pelanggan<>? AND $bukanMember AND NOT EXISTS (SELECT 1 FROM tbl_transaksi t WHERE t.id_pelanggan=p.id_pelanggan)")
                ->execute([$keepId]);
            $pdo->commit();
        } catch (Throwable $x) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    pengaturan_simpan($pdo, ['umum_dirapikan' => '1']);
}

function badge(string $s): string {
    $label = [
        'menunggu'   => 'Menunggu pembayaran',
        'dibayar'    => 'Lunas',
        'dibatalkan' => 'Dibatalkan',
        'kedaluwarsa'=> 'Kedaluwarsa'
    ][$s] ?? $s;
    return '<span class="badge st-' . e($s) . '">' . e($label) . '</span>';
}

function csrf(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars((string)$_SESSION['csrf'], ENT_QUOTES, 'UTF-8') . '">';
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}

function go(string $page, string $extra = ''): never {
    header('Location: index.php?page=' . urlencode($page) . $extra);
    exit;
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = [$message, $type];
}

$page = $_GET['page'] ?? 'dashboard';
$allowed = [
    'dashboard','produk','keranjang','pelanggan','member','transaksi','laporan','bayar','pengaturan',
    'view','join','subquery','procedure','function','trigger','transaction','explain'
];
if (!in_array($page, $allowed, true)) $page = 'dashboard';

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $st = $pdo->prepare('SELECT * FROM tbl_user WHERE username=? LIMIT 1');
    $st->execute([trim($_POST['username'] ?? '')]);
    $u = $st->fetch();
    if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'id'   => (int)$u['id_user'],
            'nama' => $u['nama'],
            'role' => $u['role']
        ];
        header('Location: ' . ($u['role'] === 'user' ? 'store.php' : 'index.php'));
        exit;
    }
    usleep(500000);
    $_SESSION['login_error'] = 'Username atau password salah.';
    header('Location: index.php');
    exit;
}

// Login dengan kartu staf: scan barcode ATAU tempel kartu RFID (reader USB mengetik UID + Enter)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login_kartu') {
    $kode = strtoupper(trim((string)($_POST['kartu'] ?? '')));
    $u = false;
    if ($kode !== '') {
        $h  = hash('sha256', $kode);
        $st = $pdo->prepare('SELECT * FROM tbl_user WHERE kode_kartu=? OR kode_rfid=? LIMIT 1');
        $st->execute([$h, $h]);
        $u = $st->fetch();
    }
    if ($u) {
        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'id'   => (int)$u['id_user'],
            'nama' => $u['nama'],
            'role' => $u['role']
        ];
        header('Location: index.php');
        exit;
    }
    usleep(500000);
    $_SESSION['login_error'] = 'Kartu tidak dikenali. Minta admin mendaftarkan kartu RFID / mencetak kartu barcode Anda.';
    header('Location: index.php');
    exit;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['auth'])) {
    $err = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login <?= e($NAMA_TOKO) ?></title>
<?php if ($LOGO_TOKO): ?><link rel="icon" href="<?= e($LOGO_TOKO) ?>"><?php endif ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body.lg{min-height:100vh;display:grid;place-items:center;padding:24px;font-family:'DM Sans',system-ui,Arial,sans-serif;color:#e8eeff;background:#070b17;overflow-x:hidden;position:relative}
/* latar bergerak */
.lg-bg{position:fixed;inset:0;z-index:0;background:linear-gradient(120deg,#0a1024,#150f3a,#0b1e46,#0a1024);background-size:300% 300%;animation:bgShift 18s ease infinite}
.blob{position:absolute;border-radius:50%;filter:blur(70px);opacity:.55;animation:float 14s ease-in-out infinite}
.b1{width:420px;height:420px;background:#6d3df0;top:-120px;left:-100px}
.b2{width:360px;height:360px;background:#2f7bff;bottom:-110px;right:-80px;animation-delay:-5s}
.b3{width:260px;height:260px;background:#22c994;top:55%;left:38%;opacity:.28;animation-delay:-9s}
.grid-lines{position:absolute;inset:0;background-image:linear-gradient(#ffffff08 1px,transparent 1px),linear-gradient(90deg,#ffffff08 1px,transparent 1px);background-size:46px 46px;mask-image:radial-gradient(circle at 50% 40%,#000 0,transparent 70%);-webkit-mask-image:radial-gradient(circle at 50% 40%,#000 0,transparent 70%)}
.lg-wrap{position:relative;z-index:1;width:min(980px,100%);display:grid;grid-template-columns:1.05fr .95fr;gap:48px;align-items:center}
/* sisi kiri */
.hero{animation:rise .8s .05s both}
.hero-logo{width:72px;height:72px;border-radius:20px;display:grid;place-items:center;font-size:36px;background:linear-gradient(135deg,#a78bfa,#5930dc);box-shadow:0 14px 40px #7144ec66;overflow:hidden;animation:bob 5s ease-in-out infinite}
.hero-logo.has-img{background:#fff;box-shadow:0 14px 40px #0008}
.hero-logo img{width:100%;height:100%;object-fit:contain}
.hero h1{font-size:clamp(30px,4.4vw,46px);line-height:1.08;margin:22px 0 12px;letter-spacing:-1.2px;font-weight:800;color:#fff}
.hero h1 em{font-style:normal;background:linear-gradient(90deg,#b8a2ff,#6fb0ff,#4be0b0);-webkit-background-clip:text;background-clip:text;color:transparent;background-size:200% 100%;animation:shine 6s linear infinite}
.hero p{margin:0 0 26px;color:#a9b7d6;font-size:15px;line-height:1.6;max-width:420px}
.feats{display:grid;gap:11px;padding:0;margin:0;list-style:none}
.feats li{display:flex;align-items:center;gap:12px;color:#cbd6ee;font-size:13px;opacity:0;animation:slideIn .6s both}
.feats li:nth-child(1){animation-delay:.45s}.feats li:nth-child(2){animation-delay:.6s}.feats li:nth-child(3){animation-delay:.75s}
.feats i{font-style:normal;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#ffffff12;border:1px solid #ffffff1f;font-size:16px}
/* kartu login */
.card{position:relative;padding:34px;border-radius:24px;background:linear-gradient(160deg,#ffffff1c,#ffffff0a);border:1px solid #ffffff26;backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px);box-shadow:0 30px 80px #000a,inset 0 1px 0 #ffffff22;animation:cardIn .8s cubic-bezier(.2,.9,.3,1.1) .1s both}
.card.shake{animation:shake .5s}
.card h2{margin:0 0 6px;font-size:24px;font-weight:800;color:#fff;letter-spacing:-.4px}
.card .sub{margin:0 0 22px;color:#a9b7d6;font-size:13px}
.roles{display:flex;gap:8px;margin-bottom:20px}
.roles span{font-size:11px;font-weight:700;padding:6px 11px;border-radius:30px;border:1px solid #ffffff22;background:#ffffff0d;color:#c9d5ef}
.roles span:first-child{border-color:#8b6cf266;color:#cdbfff}
.roles span:last-child{border-color:#22c99455;color:#7ff0cb}
.err{display:flex;gap:9px;align-items:center;background:#f04b6524;border:1px solid #f04b6566;color:#ffb3c0;padding:11px 13px;border-radius:12px;font-size:12px;margin-bottom:16px;animation:pop .35s}
.fld{position:relative;margin-bottom:14px}
.fld label{display:block;font-size:11px;font-weight:700;color:#aebbd8;margin:0 0 7px;letter-spacing:.3px}
.fld .in{position:relative}
.fld .ic{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:15px;opacity:.65;pointer-events:none}
.fld input{width:100%;padding:13px 44px 13px 42px;background:#0a1124b3;border:1px solid #ffffff22;border-radius:12px;color:#fff;font:inherit;font-size:14px;outline:none;transition:.2s}
.fld input::placeholder{color:#6f7fa3}
.fld input:focus{border-color:#8b6cf2;box-shadow:0 0 0 4px #8b6cf225;background:#0c1530}
.eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:0;color:#8fa0c4;cursor:pointer;padding:6px 8px;border-radius:8px;font-size:15px}
.eye:hover{color:#fff;background:#ffffff14}
.go{position:relative;overflow:hidden;width:100%;margin-top:8px;padding:14px;border:0;border-radius:12px;font:inherit;font-weight:700;font-size:14px;color:#fff;cursor:pointer;background:linear-gradient(100deg,#8055f5,#4a7cff);box-shadow:0 10px 28px #6a45f155;transition:.25s}
.go::after{content:"";position:absolute;top:0;left:-120%;width:60%;height:100%;background:linear-gradient(100deg,transparent,#ffffff55,transparent);transform:skewX(-20deg);animation:sweep 3.6s ease-in-out infinite}
.go:hover{transform:translateY(-2px);box-shadow:0 14px 34px #6a45f177}
.go:active{transform:translateY(0)}
.go.loading{pointer-events:none;opacity:.85}
.go.loading::before{content:"";display:inline-block;width:13px;height:13px;margin-right:9px;vertical-align:-2px;border:2px solid #fff6;border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
.or{display:flex;align-items:center;gap:12px;margin:22px 0 18px;color:#7384a8;font-size:11px;font-weight:700;letter-spacing:1px}
.or::before,.or::after{content:"";flex:1;height:1px;background:linear-gradient(90deg,transparent,#ffffff2a,transparent)}
/* zona kartu RFID */
.tap{display:flex;align-items:center;gap:16px;padding:16px;border-radius:16px;background:#0a1124a6;border:1px dashed #ffffff30;transition:.25s;cursor:text}
.tap:focus-within{border-color:#4be0b0;border-style:solid;box-shadow:0 0 0 4px #22c99420}
.pulse{position:relative;flex-shrink:0;width:48px;height:48px;border-radius:50%;display:grid;place-items:center;background:#22c9941f;color:#4be0b0;font-size:21px}
.pulse::before,.pulse::after{content:"";position:absolute;inset:0;border-radius:50%;border:2px solid #22c994;opacity:0;animation:ring 2.4s ease-out infinite}
.pulse::after{animation-delay:1.2s}
.tap .tx{flex:1;min-width:0}
.tap b{display:block;font-size:12px;color:#e8eeff;margin-bottom:6px}
.tap input{width:100%;background:none;border:0;border-bottom:1px solid #ffffff2a;color:#fff;font:inherit;font-size:13px;padding:4px 0;outline:none}
.tap input::placeholder{color:#6f7fa3}
.tap small{display:block;margin-top:6px;color:#7f90b3;font-size:10px}
.foot{margin-top:20px;text-align:center;color:#6f7fa3;font-size:11px}
/* animasi */
@keyframes bgShift{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
@keyframes float{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(50px,-40px) scale(1.12)}66%{transform:translate(-40px,35px) scale(.92)}}
@keyframes rise{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}
@keyframes cardIn{from{opacity:0;transform:translateY(34px) scale(.96)}to{opacity:1;transform:none}}
@keyframes slideIn{from{opacity:0;transform:translateX(-18px)}to{opacity:1;transform:none}}
@keyframes bob{0%,100%{transform:translateY(0) rotate(-2deg)}50%{transform:translateY(-8px) rotate(2deg)}}
@keyframes shine{to{background-position:200% 0}}
@keyframes sweep{0%,55%{left:-120%}100%{left:170%}}
@keyframes ring{0%{transform:scale(1);opacity:.7}100%{transform:scale(1.9);opacity:0}}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes pop{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:none}}
@keyframes shake{10%,90%{transform:translateX(-2px)}20%,80%{transform:translateX(4px)}30%,50%,70%{transform:translateX(-7px)}40%,60%{transform:translateX(7px)}}
@media(max-width:860px){.lg-wrap{grid-template-columns:1fr;gap:26px;max-width:440px}.hero{text-align:center}.hero-logo{margin:auto}.hero p{margin-inline:auto}.feats{display:none}.hero h1{margin-top:16px}}
@media(max-width:480px){.card{padding:24px 20px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important}.feats li{opacity:1}}
</style>
</head>
<body class="lg">
<div class="lg-bg"><div class="blob b1"></div><div class="blob b2"></div><div class="blob b3"></div><div class="grid-lines"></div></div>
<div class="lg-wrap">
  <section class="hero">
    <div class="hero-logo<?= $LOGO_TOKO ? ' has-img' : '' ?>"><?php if ($LOGO_TOKO): ?><img src="<?= e($LOGO_TOKO) ?>" alt="Logo <?= e($NAMA_TOKO) ?>"><?php else: ?>✦<?php endif ?></div>
    <h1>Selamat datang di <em><?= e($NAMA_TOKO) ?></em></h1>
    <p>Sistem kasir toko offline. Masuk untuk melayani transaksi, mengelola stok, dan memantau penjualan.</p>
    <ul class="feats">
      <li><i>⚡</i>Transaksi kasir cepat dengan scan barcode</li>
      <li><i>★</i>Diskon member otomatis lewat kartu</li>
      <li><i>▥</i>Laporan dan stok selalu terpantau</li>
    </ul>
  </section>

  <section class="card<?= $err ? ' shake' : '' ?>" id="card">
    <h2>Login staf</h2>
    <p class="sub">Masuk dengan akun Anda atau tap kartu.</p>
    <div class="roles"><span>● Admin</span><span>● Kasir</span></div>
    <?php if ($err): ?><div class="err" role="alert"><span>⚠</span><span><?= e($err) ?></span></div><?php endif ?>

    <form method="post" id="loginForm">
      <input type="hidden" name="action" value="login">
      <div class="fld">
        <label for="lgUser">Username</label>
        <div class="in"><span class="ic">👤</span><input id="lgUser" name="username" required autocomplete="username" placeholder="Masukkan username"></div>
      </div>
      <div class="fld">
        <label for="lgPass">Password</label>
        <div class="in"><span class="ic">🔒</span><input id="lgPass" type="password" name="password" required autocomplete="current-password" placeholder="Masukkan password"><button type="button" class="eye" id="eye" aria-label="Tampilkan password">👁</button></div>
      </div>
      <button class="go" id="goBtn">Masuk ke <?= e($NAMA_TOKO) ?></button>
    </form>

    <div class="or">ATAU</div>

    <form method="post" class="tap" autocomplete="off" id="tapForm">
      <input type="hidden" name="action" value="login_kartu">
      <div class="pulse">▣</div>
      <div class="tx">
        <b>Tap kartu RFID / scan barcode kartu</b>
        <input type="password" name="kartu" id="kartuInput" placeholder="Menunggu kartu…" autocomplete="off" autofocus>
        <small>Langsung masuk sesuai role, tanpa mengetik.</small>
      </div>
    </form>
    <div class="foot">© <?= date('Y') ?> <?= e($NAMA_TOKO) ?><br><a href="store.php" style="color:#a99aff;text-decoration:none;font-weight:700">Belanja sebagai pelanggan →</a></div>
  </section>
</div>
<script>
(function(){
  var k=document.getElementById('kartuInput');
  // Scanner/reader USB mengetik seperti keyboard: arahkan ketikan liar ke kotak kartu
  document.addEventListener('keydown',function(ev){var t=ev.target.tagName;
    if(ev.ctrlKey||ev.metaKey||ev.altKey||t==='INPUT'||t==='TEXTAREA'||t==='BUTTON')return;
    if(ev.key&&ev.key.length===1&&k)k.focus();});
  var eye=document.getElementById('eye'),pw=document.getElementById('lgPass');
  eye.addEventListener('click',function(){var h=pw.type==='password';pw.type=h?'text':'password';eye.textContent=h?'🙈':'👁';eye.setAttribute('aria-label',h?'Sembunyikan password':'Tampilkan password');});
  document.getElementById('loginForm').addEventListener('submit',function(){var b=document.getElementById('goBtn');b.classList.add('loading');b.textContent='Memproses…';});
  var c=document.getElementById('card');
  c.addEventListener('animationend',function(e){if(e.animationName==='shake')c.classList.remove('shake');});
})();
</script>
</body>
</html><?php
    exit;
}

$cekSesi = $pdo->prepare('SELECT nama, role FROM tbl_user WHERE id_user=?');
$cekSesi->execute([(int)$_SESSION['auth']['id']]);
$akunSesi = $cekSesi->fetch();
if (!$akunSesi) {            // akun sudah dihapus admin
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}
$_SESSION['auth']['nama'] = $akunSesi['nama'];
$_SESSION['auth']['role'] = $akunSesi['role'];
if ($akunSesi['role'] === 'user') { header('Location: store.php'); exit; }

$role = $_SESSION['auth']['role'];

// Migrasi transaksi lama: pada instalasi yang hanya memiliki SATU akun kasir,
// transaksi lunas tanpa id_user dapat dihubungkan secara tidak ambigu ke akun tersebut.
// Jika ada lebih dari satu kasir, data tidak ditebak/dipindahkan.
try {
    $kasirCount = (int)$pdo->query("SELECT COUNT(*) FROM tbl_user WHERE role='kasir'")->fetchColumn();
    if ($kasirCount === 1) {
        $soleCashierId = (int)$pdo->query("SELECT id_user FROM tbl_user WHERE role='kasir' ORDER BY id_user LIMIT 1")->fetchColumn();
        if ($soleCashierId > 0) {
            $fixLegacy = $pdo->prepare("UPDATE tbl_transaksi SET id_user=? WHERE status_pembayaran='dibayar' AND (id_user IS NULL OR id_user=0)");
            $fixLegacy->execute([$soleCashierId]);
        }
    }
} catch (Throwable $migrationError) {
    // Jangan sampai kegagalan migrasi menghentikan aplikasi.
}

if ($role !== 'admin' && in_array($page, ['pelanggan','view','join','subquery','procedure','function','trigger','transaction','explain'], true)) {
    $page = 'dashboard';
}

// Unduh laporan Excel (.xlsx) langsung sebagai file, tanpa dialog cetak
if ($page === 'laporan' && ($_GET['export'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/laporan_lib.php';
    if (!class_exists('ZipArchive')) {
        flash('Ekstensi PHP "zip" belum aktif, jadi file Excel tidak bisa dibuat. Aktifkan extension=zip di php.ini (XAMPP), lalu restart Apache.', 'error');
        go('laporan');
    }
    $perX = laporanPeriode($_GET);
    // Kasir selalu dibatasi ke ID sesi; admin boleh memilih satu kasir atau semua.
    $kasirFilterX = 0;
    if ($role === 'admin') {
        $requestedX = (int)($_GET['kasir'] ?? 0);
        if ($requestedX > 0) {
            $cekKasirX = $pdo->prepare("SELECT id_user FROM tbl_user WHERE id_user=? AND role='kasir' LIMIT 1");
            $cekKasirX->execute([$requestedX]);
            if ($cekKasirX->fetchColumn()) $kasirFilterX = $requestedX;
        }
    } else {
        $kasirFilterX = (int)($_SESSION['auth']['id'] ?? 0);
    }
    $datX = laporanData($pdo, $perX, $STOK_MENIPIS, $kasirFilterX);
    $slugX = trim((string)preg_replace('/[^a-z0-9]+/i', '-', $NAMA_TOKO), '-') ?: 'toko';
    $bin = laporanXlsx($perX, $datX, $NAMA_TOKO, $role === 'admin');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="laporan-' . strtolower($slugX) . '-' . $datX['dari'] . '_' . $datX['sampai'] . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: no-store');
    echo $bin;
    exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (in_array($action, ['save_product','update_product_image','delete_product','save_customer','delete_customer','call_procedure','test_transaction','pay_cancel','save_transaction'], true) && $role !== 'admin') {
            throw new RuntimeException('Aksi ini khusus admin.');
        }


        // ------------------------------------------------------------ Pengaturan
        if (in_array($action, ['save_profile','change_password','save_settings','user_add','user_update','user_delete'], true)) {
            if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
                throw new RuntimeException('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
            }
            if ($action !== 'save_profile' && $action !== 'change_password' && $role !== 'admin') {
                throw new RuntimeException('Aksi ini khusus admin.');
            }
        }

        if ($action === 'save_profile') {
            $nama = trim((string)($_POST['nama'] ?? ''));
            if ($nama === '' || mb_strlen($nama) > 100) throw new RuntimeException('Nama wajib diisi (maksimal 100 karakter).');
            $pdo->prepare('UPDATE tbl_user SET nama=? WHERE id_user=?')->execute([$nama, (int)$_SESSION['auth']['id']]);
            $_SESSION['auth']['nama'] = $nama;
            flash('Profil berhasil diperbarui.');
            go('pengaturan', '&tab=akun');
        }

        if ($action === 'change_password') {
            $lama = (string)($_POST['password_lama'] ?? '');
            $baru = (string)($_POST['password_baru'] ?? '');
            $ulang = (string)($_POST['password_ulang'] ?? '');
            $st = $pdo->prepare('SELECT password FROM tbl_user WHERE id_user=?');
            $st->execute([(int)$_SESSION['auth']['id']]);
            $hash = (string)$st->fetchColumn();
            if (!password_verify($lama, $hash)) { usleep(400000); throw new RuntimeException('Password saat ini salah.'); }
            if (strlen($baru) < 8) throw new RuntimeException('Password baru minimal 8 karakter.');
            if ($baru !== $ulang) throw new RuntimeException('Konfirmasi password baru tidak cocok.');
            if (password_verify($baru, $hash)) throw new RuntimeException('Password baru harus berbeda dari password saat ini.');
            $pdo->prepare('UPDATE tbl_user SET password=? WHERE id_user=?')
                ->execute([password_hash($baru, PASSWORD_DEFAULT), (int)$_SESSION['auth']['id']]);
            session_regenerate_id(true);
            flash('Password berhasil diganti.');
            go('pengaturan', '&tab=akun');
        }

        if ($action === 'save_settings') {
            $group = (string)($_POST['group'] ?? '');
            $pairs = [];
            if ($group === 'toko') {
                $nama = trim((string)($_POST['nama_toko'] ?? ''));
                $alamat = trim((string)($_POST['alamat_toko'] ?? ''));
                $telp = trim((string)($_POST['telepon_toko'] ?? ''));
                $footer = trim((string)($_POST['footer_struk'] ?? ''));
                $lebar = (string)($_POST['lebar_struk'] ?? '80');
                if ($nama === '' || mb_strlen($nama) > 60) throw new RuntimeException('Nama toko wajib diisi (maksimal 60 karakter).');
                if (mb_strlen($alamat) > 150) throw new RuntimeException('Alamat terlalu panjang (maksimal 150 karakter).');
                if ($telp !== '' && !preg_match('/^[0-9+\-() ]{5,20}$/', $telp)) throw new RuntimeException('Nomor telepon hanya boleh angka, spasi, +, -, dan tanda kurung (5-20 karakter).');
                if (mb_strlen($footer) > 120) throw new RuntimeException('Pesan penutup struk maksimal 120 karakter.');
                if (!in_array($lebar, ['58', '80'], true)) throw new RuntimeException('Lebar kertas struk tidak valid.');
                $pairs = ['nama_toko' => $nama, 'alamat_toko' => $alamat, 'telepon_toko' => $telp, 'footer_struk' => $footer, 'lebar_struk' => $lebar, 'struk_barcode' => !empty($_POST['struk_barcode']) ? '1' : '0'];
                $logoLama = setting('logo_toko');
                $logoBaru = uploadImage('logo', 'toko');
                if ($logoBaru !== null) {
                    deleteLocalImage($logoLama);
                    $pairs['logo_toko'] = $logoBaru;
                } elseif (!empty($_POST['hapus_logo'])) {
                    deleteLocalImage($logoLama);
                    $pairs['logo_toko'] = '';
                }
            } elseif ($group === 'transaksi') {
                $metode = array_values(array_intersect(array_keys($METODE_POS), (array)($_POST['metode'] ?? [])));
                $diskon = (float)str_replace(',', '.', (string)($_POST['member_diskon_default'] ?? '5'));
                $stokMn = (int)($_POST['stok_menipis'] ?? 5);
                if (!$metode) throw new RuntimeException('Aktifkan minimal satu metode pembayaran.');
                if ($diskon < 0 || $diskon > 50) throw new RuntimeException('Diskon member bawaan harus antara 0 dan 50 persen.');
                if ($stokMn < 0 || $stokMn > 1000) throw new RuntimeException('Batas stok menipis harus antara 0 dan 1000.');
                $namaBank = trim((string)($_POST['nama_bank'] ?? ''));
                $nomorRek = trim((string)($_POST['nomor_rekening'] ?? ''));
                $pemilikRek = trim((string)($_POST['nama_pemilik_rekening'] ?? ''));
                $catatanTf = trim((string)($_POST['catatan_transfer'] ?? ''));
                if (mb_strlen($namaBank)>60 || mb_strlen($nomorRek)>40 || mb_strlen($pemilikRek)>100 || mb_strlen($catatanTf)>300) throw new RuntimeException('Informasi rekening bank terlalu panjang.');
                $pairs = ['metode_aktif' => implode(',', $metode), 'member_diskon_default' => $diskon, 'stok_menipis' => $stokMn, 'nama_bank'=>$namaBank, 'nomor_rekening'=>$nomorRek, 'nama_pemilik_rekening'=>$pemilikRek, 'catatan_transfer'=>$catatanTf];
                $qrisLama = setting('qris_gambar');
                $qrisBaru = uploadImage('qris', 'toko');
                if ($qrisBaru !== null) {
                    deleteLocalImage($qrisLama);
                    $pairs['qris_gambar'] = $qrisBaru;
                } elseif (!empty($_POST['hapus_qris'])) {
                    deleteLocalImage($qrisLama);
                    $pairs['qris_gambar'] = '';
                }
            } else {
                throw new RuntimeException('Kelompok pengaturan tidak dikenal.');
            }
            pengaturan_simpan($pdo, $pairs);
            flash('Pengaturan berhasil disimpan.');
            go('pengaturan', '&tab=' . ($group === 'toko' ? 'toko' : 'transaksi'));
        }

        if ($action === 'user_add') {
            $nama = trim((string)($_POST['nama'] ?? ''));
            $user = strtolower(trim((string)($_POST['username'] ?? '')));
            $pass = (string)($_POST['password'] ?? '');
            $rl   = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'kasir';
            if ($nama === '' || mb_strlen($nama) > 100) throw new RuntimeException('Nama wajib diisi (maksimal 100 karakter).');
            if (!preg_match('/^[a-z0-9_.\-]{3,30}$/', $user)) throw new RuntimeException('Username 3-30 karakter: huruf kecil, angka, titik, strip, atau garis bawah.');
            if (strlen($pass) < 8) throw new RuntimeException('Password minimal 8 karakter.');
            $c = $pdo->prepare('SELECT COUNT(*) FROM tbl_user WHERE username=?');
            $c->execute([$user]);
            if ((int)$c->fetchColumn() > 0) throw new RuntimeException('Username "' . $user . '" sudah dipakai.');
            $pdo->prepare('INSERT INTO tbl_user(nama,username,password,role) VALUES(?,?,?,?)')
                ->execute([$nama, $user, password_hash($pass, PASSWORD_DEFAULT), $rl]);
            flash('Akun ' . $user . ' (' . $rl . ') berhasil dibuat.');
            go('pengaturan', '&tab=staf');
        }

        if ($action === 'user_update') {
            $idu  = (int)($_POST['id_user'] ?? 0);
            $nama = trim((string)($_POST['nama'] ?? ''));
            $rl   = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'kasir';
            $pass = (string)($_POST['password_baru'] ?? '');
            if ($nama === '' || mb_strlen($nama) > 100) throw new RuntimeException('Nama wajib diisi (maksimal 100 karakter).');
            if ($pass !== '' && strlen($pass) < 8) throw new RuntimeException('Password baru minimal 8 karakter.');
            $st = $pdo->prepare('SELECT id_user, role FROM tbl_user WHERE id_user=?');
            $st->execute([$idu]);
            $target = $st->fetch();
            if (!$target) throw new RuntimeException('Akun tidak ditemukan.');
            $roleLama = $target['role'] === 'admin' ? 'admin' : 'kasir';
            if ($rl !== $roleLama) {
                if ($idu === (int)$_SESSION['auth']['id']) throw new RuntimeException('Anda tidak dapat mengubah role akun sendiri.');
                $jmlAdmin = (int)$pdo->query("SELECT COUNT(*) FROM tbl_user WHERE role='admin'")->fetchColumn();
                if ($roleLama === 'admin' && $jmlAdmin <= 1) throw new RuntimeException('Harus ada minimal satu admin.');
            }
            $pdo->prepare('UPDATE tbl_user SET nama=?, role=? WHERE id_user=?')->execute([$nama, $rl, $idu]);
            if ($pass !== '') {
                $pdo->prepare('UPDATE tbl_user SET password=? WHERE id_user=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $idu]);
            }
            flash('Akun berhasil diperbarui' . ($pass !== '' ? ' dan password diganti.' : '.'));
            go('pengaturan', '&tab=staf');
        }

        if ($action === 'user_delete') {
            $idu = (int)($_POST['id_user'] ?? 0);
            if ($idu === (int)$_SESSION['auth']['id']) throw new RuntimeException('Anda tidak dapat menghapus akun yang sedang dipakai.');
            $st = $pdo->prepare('SELECT role FROM tbl_user WHERE id_user=?');
            $st->execute([$idu]);
            $rlTarget = $st->fetchColumn();
            if ($rlTarget === false) throw new RuntimeException('Akun tidak ditemukan.');
            if ($rlTarget === 'admin' && (int)$pdo->query("SELECT COUNT(*) FROM tbl_user WHERE role='admin'")->fetchColumn() <= 1) {
                throw new RuntimeException('Harus ada minimal satu admin.');
            }
            $pdo->prepare('DELETE FROM tbl_user WHERE id_user=?')->execute([$idu]);
            flash('Akun berhasil dihapus. Riwayat transaksinya tetap tersimpan.');
            go('pengaturan', '&tab=staf');
        }

        if ($action === 'save_customer') {
            $id   = (int)($_POST['id_pelanggan'] ?? 0);
            $nama = trim($_POST['nama_pelanggan'] ?? '');
            $kota = trim($_POST['kota'] ?? '');
            if ($nama === '') throw new RuntimeException('Nama pelanggan wajib diisi.');
            if ($id > 0) {
                $st = $pdo->prepare('UPDATE tbl_pelanggan SET nama_pelanggan=?, kota=? WHERE id_pelanggan=?');
                $st->execute([$nama, $kota, $id]);
                flash('Data pelanggan berhasil diperbarui.');
            } else {
                $st = $pdo->prepare('INSERT INTO tbl_pelanggan (nama_pelanggan,kota) VALUES (?,?)');
                $st->execute([$nama, $kota]);
                flash('Pelanggan baru berhasil ditambahkan.');
            }
            go('pelanggan');
        }

        if ($action === 'delete_customer') {
            $id = (int)$_POST['id_pelanggan'];
            $st = $pdo->prepare('SELECT COUNT(*) FROM tbl_transaksi WHERE id_pelanggan=?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                throw new RuntimeException('Pelanggan memiliki transaksi. Hapus/transaksikan datanya terlebih dahulu.');
            }
            $st = $pdo->prepare('DELETE FROM tbl_pelanggan WHERE id_pelanggan=?');
            $st->execute([$id]);
            flash('Pelanggan berhasil dihapus.');
            go('pelanggan');
        }

        if ($action === 'save_transaction') {
            $id      = (int)($_POST['id_pelanggan'] ?? 0);
            $tanggal = trim((string)($_POST['tanggal'] ?? '')) ?: date('Y-m-d');
            $total   = (float)($_POST['total_belanja'] ?? 0);
            $dt = DateTime::createFromFormat('Y-m-d', $tanggal);
            if (!$dt || $dt->format('Y-m-d') !== $tanggal) throw new RuntimeException('Format tanggal tidak valid.');
            if ($total < 0) throw new RuntimeException('Total belanja tidak boleh negatif.');
            $cp = $pdo->prepare('SELECT COUNT(*) FROM tbl_pelanggan WHERE id_pelanggan=?');
            $cp->execute([$id]);
            if (!(int)$cp->fetchColumn()) throw new RuntimeException('Pilih pelanggan yang valid.');
            $st = $pdo->prepare('INSERT INTO tbl_transaksi (tanggal,id_pelanggan,total_belanja,id_user) VALUES (?,?,?,?)');
            $st->execute([$tanggal, $id, $total, (int)$_SESSION['auth']['id']]);
            flash('Transaksi berhasil disimpan.');
            go('transaksi');
        }

        if ($action === 'save_product') {
            $id        = (int)($_POST['id_produk'] ?? 0);
            $nama      = trim($_POST['nama_produk'] ?? '');
            $kategori  = trim($_POST['kategori'] ?? '');
            $deskripsi = trim($_POST['deskripsi'] ?? '');
            $harga     = (float)($_POST['harga'] ?? 0);
            $stok      = (int)($_POST['stok'] ?? 0);
            $diskon    = (float)($_POST['diskon'] ?? 0);
            if ($nama === '' || $kategori === '' || $harga <= 0) {
                throw new RuntimeException('Nama, kategori, dan harga produk wajib diisi dengan benar.');
            }
            if ($diskon < 0 || $diskon > 90 || $stok < 0) {
                throw new RuntimeException('Diskon atau stok tidak valid.');
            }
            $barcode = trim($_POST['barcode'] ?? '');
            if ($barcode !== '' && !preg_match('/^[A-Za-z0-9.\-]{3,32}$/', $barcode)) {
                throw new RuntimeException('Barcode hanya boleh huruf, angka, titik, atau strip (3-32 karakter).');
            }
            if ($barcode !== '') {
                $dup = $pdo->prepare('SELECT nama_produk FROM tbl_produk WHERE barcode=? AND id_produk<>?');
                $dup->execute([$barcode, $id]);
                if ($d = $dup->fetchColumn()) throw new RuntimeException('Barcode ' . $barcode . ' sudah dipakai produk: ' . $d . '.');
            }
            $foto = uploadImage('foto', 'produk');
            if ($id > 0) {
                if ($barcode !== '') {
                    $pdo->prepare('UPDATE tbl_produk SET barcode=? WHERE id_produk=?')->execute([$barcode, $id]);
                }
                if ($foto !== null) {
                    $old = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk=?');
                    $old->execute([$id]);
                    deleteLocalImage($old->fetchColumn() ?: null);
                    $st = $pdo->prepare('UPDATE tbl_produk SET nama_produk=?,kategori=?,deskripsi=?,harga=?,stok=?,diskon=?,gambar=? WHERE id_produk=?');
                    $st->execute([$nama, $kategori, $deskripsi, $harga, $stok, $diskon, $foto, $id]);
                } else {
                    $st = $pdo->prepare('UPDATE tbl_produk SET nama_produk=?,kategori=?,deskripsi=?,harga=?,stok=?,diskon=? WHERE id_produk=?');
                    $st->execute([$nama, $kategori, $deskripsi, $harga, $stok, $diskon, $id]);
                }
                flash('Produk berhasil diperbarui.');
            } else {
                $st = $pdo->prepare('INSERT INTO tbl_produk(nama_produk,kategori,deskripsi,harga,stok,diskon,gambar) VALUES(?,?,?,?,?,?,?)');
                $st->execute([$nama, $kategori, $deskripsi, $harga, $stok, $diskon, $foto]);
                $newId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE tbl_produk SET barcode=? WHERE id_produk=?')
                    ->execute([$barcode !== '' ? $barcode : makeBarcode($newId), $newId]);
                flash('Produk berhasil ditambahkan ke katalog.');
            }
            go('produk');
        }

        if ($action === 'update_product_image') {
            $id   = (int)($_POST['id_produk'] ?? 0);
            $foto = uploadImage('foto', 'produk');
            if ($foto === null) throw new RuntimeException('Pilih file gambar terlebih dahulu.');
            $old = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk=?');
            $old->execute([$id]);
            $oldPath = $old->fetchColumn() ?: null;
            $pdo->prepare('UPDATE tbl_produk SET gambar=? WHERE id_produk=?')->execute([$foto, $id]);
            deleteLocalImage($oldPath);
            flash('Foto produk berhasil diganti.');
            go('produk');
        }

        if ($action === 'delete_product') {
            $id  = (int)$_POST['id_produk'];
            $old = $pdo->prepare('SELECT gambar FROM tbl_produk WHERE id_produk=?');
            $old->execute([$id]);
            $oldPath = $old->fetchColumn() ?: null;
            try {
                $pdo->prepare('DELETE FROM tbl_produk WHERE id_produk=?')->execute([$id]);
            } catch (PDOException $x) {
                if ($x->getCode() === '23000') {
                    throw new RuntimeException('Produk ini sudah ada di transaksi, jadi tidak bisa dihapus.');
                }
                throw $x;
            }
            deleteLocalImage($oldPath);
            flash('Produk berhasil dihapus.');
            go('produk');
        }

        if ($action === 'checkout') {
            $cart = $_SESSION['cart'] ?? [];
            if (!$cart) throw new RuntimeException('Keranjang masih kosong.');
            $metode = (string)($_POST['metode'] ?? '');
            if (!in_array($metode, $METODE_AKTIF, true)) {
                throw new RuntimeException('Metode pembayaran tidak valid.');
            }
            $member = currentMember($pdo);
            $nama = trim($_POST['nama_baru'] ?? '');
            if ($nama === '') $nama = 'Pelanggan Umum';
            $pdo->beginTransaction();
            if ($member) {
                $idp = (int)$member['id_pelanggan'];
            } else {
                $kotaBaru = trim($_POST['kota_baru'] ?? '');
                $idp = 0;
                if ($nama === 'Pelanggan Umum' && $kotaBaru === '') {
                    $cu = $pdo->query("SELECT id_pelanggan FROM tbl_pelanggan WHERE nama_pelanggan='Pelanggan Umum' AND (kota IS NULL OR kota='') ORDER BY id_pelanggan LIMIT 1");
                    $idp = (int)$cu->fetchColumn();
                }
                if ($idp < 1) {
                    $pdo->prepare('INSERT INTO tbl_pelanggan(nama_pelanggan,kota) VALUES(?,?)')->execute([$nama, $kotaBaru]);
                    $idp = (int)$pdo->lastInsertId();
                }
            }
            $ids = array_map('intval', array_keys($cart));
            sort($ids);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $st  = $pdo->prepare("SELECT id_produk,nama_produk,harga,diskon,stok FROM tbl_produk WHERE id_produk IN ($in) ORDER BY id_produk FOR UPDATE");
            $st->execute($ids);
            $items = [];
            $total = 0.0;
            foreach ($st->fetchAll() as $pr) {
                $qty = (int)($cart[$pr['id_produk']] ?? 0);
                if ($qty < 1) continue;
                if ($qty > (int)$pr['stok']) {
                    throw new RuntimeException('Stok ' . $pr['nama_produk'] . ' tidak mencukupi.');
                }
                $unit = round((float)$pr['harga'] * (1 - (float)($pr['diskon'] ?? 0) / 100), 2);
                $sub  = $unit * $qty;
                $total += $sub;
                $items[] = [(int)$pr['id_produk'], $qty, $unit, $sub];
            }
            if (!$items) throw new RuntimeException('Tidak ada produk yang dapat diproses.');
            $diskonMember  = 0.0;
            $persenMember  = 0.0;
            if ($member) {
                $persenMember = (float)$member['diskon_persen'];
                $diskonMember = round($total * $persenMember / 100, 2);
                $total       -= $diskonMember;
            }
            $isQris   = ($metode === 'qris');
            $status   = $isQris ? 'menunggu' : 'dibayar';
            $invoice  = 'TMP' . bin2hex(random_bytes(8));
            $deadline = $isQris ? date('Y-m-d H:i:s', time() + (5 * 60)) : null;
            $paidAt   = $isQris ? null : date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO tbl_transaksi(tanggal,id_pelanggan,total_belanja,id_user,status_pembayaran,metode_pembayaran,kode_invoice,batas_bayar,dibayar_pada,id_member,diskon_member,diskon_member_persen) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([date('Y-m-d'), $idp, $total, (int)$_SESSION['auth']['id'], $status, $metode, $invoice, $deadline, $paidAt, $member ? (int)$member['id_member'] : null, $diskonMember, $persenMember]);
            $idT = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE tbl_transaksi SET kode_invoice=? WHERE id_transaksi=?')
                ->execute(['POS' . date('ymd') . str_pad((string)$idT, 5, '0', STR_PAD_LEFT), $idT]);
            $ins = $pdo->prepare('INSERT INTO tbl_detail_transaksi(id_transaksi,id_produk,jumlah,harga_satuan,subtotal) VALUES(?,?,?,?,?)');
            $dec = $pdo->prepare($isQris
                ? 'UPDATE tbl_produk SET stok=stok-? WHERE id_produk=?'
                : 'UPDATE tbl_produk SET stok=stok-?,terjual=terjual+? WHERE id_produk=?');
            foreach ($items as [$pid, $qty, $unit, $sub]) {
                $ins->execute([$idT, $pid, $qty, $unit, $sub]);
                if ($isQris) $dec->execute([$qty, $pid]);
                else $dec->execute([$qty, $qty, $pid]);
            }
            $pdo->commit();
            unset($_SESSION['cart'], $_SESSION['member_id']);
            if ($isQris) {
                flash('Pesanan QRIS dummy dibuat. Petugas dapat memeriksa dan mengonfirmasi pembayaran simulasi.');
                go('bayar', '&id=' . $idT);
            }
            flash('Transaksi tunai/debit berhasil disimpan dan tercatat lunas.');
            go('transaksi');
        }

        if ($action === 'upload_bukti') {
            $idT = (int)($_POST['id_transaksi'] ?? 0);
            $st  = $pdo->prepare('SELECT status_pembayaran,bukti_bayar FROM tbl_transaksi WHERE id_transaksi=?');
            $st->execute([$idT]);
            $o = $st->fetch();
            if (!$o || $o['status_pembayaran'] !== 'menunggu') {
                throw new RuntimeException('Pesanan ini tidak menunggu pembayaran.');
            }
            $bukti = uploadImage('bukti', 'bukti');
            if ($bukti === null) throw new RuntimeException('Pilih foto bukti pembayaran.');
            $pdo->prepare('UPDATE tbl_transaksi SET bukti_bayar=? WHERE id_transaksi=?')->execute([$bukti, $idT]);
            deleteLocalImage($o['bukti_bayar']);
            flash('Bukti pembayaran diunggah. Menunggu konfirmasi admin.');
            go('bayar', '&id=' . $idT);
        }

        if ($action === 'pay_confirm') {
            $idT = (int)($_POST['id_transaksi'] ?? 0);
            $pdo->beginTransaction();
            $sqlLock = 'SELECT status_pembayaran FROM tbl_transaksi WHERE id_transaksi=?';
            $lockArgs = [$idT];
            if ($role !== 'admin') {
                $sqlLock .= ' AND id_user=?';
                $lockArgs[] = (int)$_SESSION['auth']['id'];
            }
            $st = $pdo->prepare($sqlLock . ' FOR UPDATE');
            $st->execute($lockArgs);
            $s0 = $st->fetchColumn();
            if ($s0 !== 'menunggu') throw new RuntimeException('Pesanan ini tidak dalam status menunggu pembayaran.');
            $pdo->prepare("UPDATE tbl_transaksi SET status_pembayaran='dibayar',dibayar_pada=? WHERE id_transaksi=?")
                ->execute([date('Y-m-d H:i:s'), $idT]);
            $pdo->prepare('UPDATE tbl_produk p JOIN tbl_detail_transaksi d ON d.id_produk=p.id_produk SET p.terjual=p.terjual+d.jumlah WHERE d.id_transaksi=?')
                ->execute([$idT]);
            $pdo->commit();
            flash('Pembayaran dikonfirmasi. Transaksi lunas.');
            go('bayar', '&id=' . $idT);
        }

        if ($action === 'pay_cancel') {
            $idT = (int)($_POST['id_transaksi'] ?? 0);
            $pdo->beginTransaction();
            $sqlCancel = "UPDATE tbl_transaksi SET status_pembayaran='dibatalkan' WHERE id_transaksi=? AND status_pembayaran='menunggu'";
            $cancelArgs = [$idT];
            if ($role !== 'admin') {
                $sqlCancel .= ' AND id_user=?';
                $cancelArgs[] = (int)$_SESSION['auth']['id'];
            }
            $u = $pdo->prepare($sqlCancel);
            $u->execute($cancelArgs);
            if ($u->rowCount() < 1) {
                throw new RuntimeException('Hanya pesanan yang menunggu pembayaran yang bisa dibatalkan.');
            }
            releaseStock($pdo, $idT);
            $pdo->commit();
            flash('Pesanan dibatalkan dan stok dikembalikan.');
            go('bayar', '&id=' . $idT);
        }

        if ($action === 'add_cart') {
            $id = (int)($_POST['id_produk'] ?? 0);
            $st = $pdo->prepare('SELECT id_produk,nama_produk,harga,diskon,stok FROM tbl_produk WHERE id_produk=?');
            $st->execute([$id]);
            $p = $st->fetch();
            if (!$p || (int)$p['stok'] < 1) {
                throw new RuntimeException('Produk tidak tersedia atau stok habis.');
            }
            $_SESSION['cart'] ??= [];
            $sudah = (int)($_SESSION['cart'][$id] ?? 0);
            if ($sudah >= (int)$p['stok']) {
                throw new RuntimeException('Stok ' . $p['nama_produk'] . ' hanya ' . (int)$p['stok'] . ' pcs, semuanya sudah ada di keranjang.');
            }
            $_SESSION['cart'][$id] = $sudah + 1;
            flash($p['nama_produk'] . ' masuk keranjang (' . ($sudah + 1) . ' pcs).');
            go('produk');
        }

        if ($action === 'scan_cart') {
            $kode = trim((string)($_POST['kode'] ?? ''));
            $back = ($_POST['back'] ?? '') === 'keranjang' ? 'keranjang' : 'produk';
            if ($kode === '') throw new RuntimeException('Kode barcode kosong.');
            $st = $pdo->prepare('SELECT id_produk,nama_produk,stok FROM tbl_produk WHERE barcode=? LIMIT 1');
            $st->execute([$kode]);
            $p = $st->fetch();
            if (!$p) {
                $mem = findMember($pdo, $kode);
                if ($mem) {
                    if (!(int)$mem['aktif']) throw new RuntimeException('Member ' . $mem['nama_pelanggan'] . ' sedang nonaktif.');
                    $_SESSION['member_id'] = (int)$mem['id_member'];
                    flash('Member ' . $mem['nama_pelanggan'] . ' aktif. Diskon ' . (float)$mem['diskon_persen'] . '% diterapkan.');
                    go('keranjang');
                }
                throw new RuntimeException('Kode ' . $kode . ' tidak terdaftar sebagai produk maupun member.');
            }
            if ((int)$p['stok'] < 1) throw new RuntimeException('Stok ' . $p['nama_produk'] . ' habis.');
            $pid = (int)$p['id_produk'];
            $_SESSION['cart'] ??= [];
            $now = (int)($_SESSION['cart'][$pid] ?? 0);
            if ($now >= (int)$p['stok']) throw new RuntimeException('Stok ' . $p['nama_produk'] . ' hanya ' . (int)$p['stok'] . '.');
            $_SESSION['cart'][$pid] = $now + 1;
            flash($p['nama_produk'] . ' masuk keranjang (' . ($now + 1) . ' pcs).');
            go($back);
        }

        if ($action === 'clear_member') {
            unset($_SESSION['member_id']);
            flash('Member dilepas dari transaksi ini.');
            go('keranjang');
        }

        if ($action === 'pick_member') {
            $kode = trim((string)($_POST['kode_member'] ?? ''));
            if ($kode === '') throw new RuntimeException('Pilih atau ketik member terlebih dahulu.');
            $mem = findMember($pdo, $kode);
            if (!$mem) {
                $st = $pdo->prepare('SELECT m.*, p.nama_pelanggan, p.kota FROM tbl_member m JOIN tbl_pelanggan p ON p.id_pelanggan=m.id_pelanggan WHERE LOWER(p.nama_pelanggan)=LOWER(?) LIMIT 2');
                $st->execute([$kode]);
                $cand = $st->fetchAll();
                if (count($cand) === 1) $mem = $cand[0];
                elseif (count($cand) > 1) throw new RuntimeException('Ada lebih dari satu member bernama ' . $kode . '. Gunakan kode member atau nomor HP.');
            }
            if (!$mem) throw new RuntimeException('Member "' . $kode . '" tidak ditemukan.');
            if (!(int)$mem['aktif']) throw new RuntimeException('Member ' . $mem['nama_pelanggan'] . ' sedang nonaktif.');
            $_SESSION['member_id'] = (int)$mem['id_member'];
            flash('Member ' . $mem['nama_pelanggan'] . ' dipilih. Diskon ' . (float)$mem['diskon_persen'] . '% diterapkan.');
            go('keranjang');
        }

        if ($action === 'member_add') {
            $lama   = (int)($_POST['id_pelanggan_lama'] ?? 0);
            $nama   = trim($_POST['nama_pelanggan'] ?? '');
            $kota   = trim($_POST['kota'] ?? '');
            $hp     = preg_replace('/\D+/', '', (string)($_POST['no_hp'] ?? ''));
            $persen = $role === 'admin' ? (float)($_POST['diskon_persen'] ?? $MEMBER_DISKON_DEFAULT) : (float)$MEMBER_DISKON_DEFAULT;
            $rfid   = strtoupper(trim((string)($_POST['rfid'] ?? '')));
            if ($persen < 0 || $persen > 50) throw new RuntimeException('Diskon member harus antara 0 dan 50 persen.');
            if ($hp !== '' && strlen($hp) < 8) throw new RuntimeException('Nomor HP tidak valid.');
            if ($rfid !== '' && !preg_match('/^[A-Z0-9]{4,32}$/', $rfid)) throw new RuntimeException('Kode RFID tidak valid. Pastikan reader dalam mode keyboard (HID).');
            $pdo->beginTransaction();
            if ($lama > 0) {
                $c = $pdo->prepare('SELECT nama_pelanggan FROM tbl_pelanggan WHERE id_pelanggan=?');
                $c->execute([$lama]);
                $nama = (string)$c->fetchColumn();
                if ($nama === '') throw new RuntimeException('Pelanggan yang dipilih tidak ditemukan.');
                $c = $pdo->prepare('SELECT COUNT(*) FROM tbl_member WHERE id_pelanggan=?');
                $c->execute([$lama]);
                if ((int)$c->fetchColumn() > 0) throw new RuntimeException('Pelanggan itu sudah menjadi member.');
                $idp = $lama;
            } else {
                if ($nama === '') throw new RuntimeException('Nama member wajib diisi.');
                $pdo->prepare('INSERT INTO tbl_pelanggan(nama_pelanggan,kota) VALUES(?,?)')->execute([$nama, $kota]);
                $idp = (int)$pdo->lastInsertId();
            }
            if ($hp !== '') {
                $c = $pdo->prepare('SELECT COUNT(*) FROM tbl_member WHERE no_hp=?');
                $c->execute([$hp]);
                if ((int)$c->fetchColumn() > 0) throw new RuntimeException('Nomor HP itu sudah terdaftar sebagai member.');
            }
            try {
                $pdo->prepare('INSERT INTO tbl_member(id_pelanggan,no_hp,diskon_persen,kode_rfid) VALUES(?,?,?,?)')
                    ->execute([$idp, $hp !== '' ? $hp : null, $persen, $rfid !== '' ? hash('sha256', $rfid) : null]);
            } catch (PDOException $x) {
                throw new RuntimeException('Kartu RFID itu sudah dipakai member lain.');
            }
            $idm  = (int)$pdo->lastInsertId();
            $kode = makeMemberCode($idm);
            $pdo->prepare('UPDATE tbl_member SET kode_member=? WHERE id_member=?')->execute([$kode, $idm]);
            $pdo->commit();
            flash('Member ' . $nama . ' terdaftar. Kode: ' . $kode . ', diskon ' . $persen . '%.');
            go('member');
        }

        if ($action === 'member_update') {
            if ($role !== 'admin') throw new RuntimeException('Hanya admin yang boleh mengubah data member.');
            $idm    = (int)($_POST['id_member'] ?? 0);
            $persen = (float)($_POST['diskon_persen'] ?? 0);
            $aktif  = ($_POST['aktif'] ?? '1') === '1' ? 1 : 0;
            $rfid   = strtoupper(trim((string)($_POST['rfid'] ?? '')));
            if ($persen < 0 || $persen > 50) throw new RuntimeException('Diskon member harus antara 0 dan 50 persen.');
            if ($rfid !== '' && !preg_match('/^[A-Z0-9]{4,32}$/', $rfid)) throw new RuntimeException('Kode RFID tidak valid. Pastikan reader dalam mode keyboard (HID).');
            $pdo->prepare('UPDATE tbl_member SET diskon_persen=?, aktif=? WHERE id_member=?')->execute([$persen, $aktif, $idm]);
            if ($rfid !== '') {
                try {
                    $pdo->prepare('UPDATE tbl_member SET kode_rfid=? WHERE id_member=?')->execute([hash('sha256', $rfid), $idm]);
                } catch (PDOException $x) {
                    throw new RuntimeException('Kartu RFID itu sudah dipakai member lain.');
                }
            }
            if (!$aktif && (int)($_SESSION['member_id'] ?? 0) === $idm) unset($_SESSION['member_id']);
            flash('Data member diperbarui.');
            go('member');
        }

        if ($action === 'update_cart') {
            $dibatasi = [];
            foreach (($_POST['qty'] ?? []) as $id => $qty) {
                $id  = (int)$id;
                $qty = max(0, (int)$qty);
                $st  = $pdo->prepare('SELECT nama_produk, stok FROM tbl_produk WHERE id_produk=?');
                $st->execute([$id]);
                $pr = $st->fetch();
                $stock = (int)($pr['stok'] ?? 0);
                if ($qty === 0 || $stock === 0) { unset($_SESSION['cart'][$id]); continue; }
                if ($qty > $stock) $dibatasi[] = $pr['nama_produk'] . ' (stok ' . $stock . ')';
                $_SESSION['cart'][$id] = min($qty, $stock);
            }
            if ($dibatasi) flash('Jumlah disesuaikan dengan stok: ' . implode(', ', $dibatasi) . '.', 'error');
            go('keranjang');
        }

        if ($action === 'call_procedure') {
            $proc = $_POST['procedure'] ?? '';
            if ($proc === 'TampilPelanggan') {
                $call = $pdo->query('CALL TampilPelanggan()');
                $call->fetchAll();
                while ($call->nextRowset()) {}
                $_SESSION['proc_result'] = $pdo->query('SELECT * FROM tbl_pelanggan ORDER BY id_pelanggan DESC')->fetchAll();
            } elseif ($proc === 'CariPelanggan') {
                $st = $pdo->prepare('CALL CariPelanggan(?)');
                $st->execute([(int)$_POST['param_id']]);
                $_SESSION['proc_result'] = $st->fetchAll();
                while ($st->nextRowset()) {}
            } elseif ($proc === 'TambahPelanggan') {
                $st = $pdo->prepare('CALL TambahPelanggan(?,?)');
                $st->execute([trim($_POST['param_nama']), trim($_POST['param_kota'])]);
                while ($st->nextRowset()) {}
                $_SESSION['proc_result'] = [['hasil' => 'Pelanggan berhasil ditambahkan melalui Stored Procedure.']];
            } elseif ($proc === 'LaporanTransaksi') {
                $st = $pdo->prepare('CALL LaporanTransaksi(?)');
                $st->execute([(int)$_POST['param_id']]);
                $_SESSION['proc_result'] = $st->fetchAll();
                while ($st->nextRowset()) {}
            } elseif ($proc === 'HitungPelanggan') {
                $call = $pdo->query('CALL HitungPelanggan(@jumlah)');
                while ($call->nextRowset()) {}
                $_SESSION['proc_result'] = [['jumlah_pelanggan' => $pdo->query('SELECT @jumlah')->fetchColumn()]];
            } else {
                throw new RuntimeException('Stored Procedure tidak dikenal.');
            }
            flash('Stored Procedure berhasil dipanggil.');
            go('procedure');
        }

        if ($action === 'test_transaction') {
            $mode = $_POST['mode'] ?? 'rollback';
            $pdo->beginTransaction();
            $customer = (int)$pdo->query('SELECT id_pelanggan FROM tbl_pelanggan ORDER BY id_pelanggan LIMIT 1')->fetchColumn();
            if (!$customer) throw new RuntimeException('Tambahkan minimal satu pelanggan untuk menguji transaksi.');
            // Data uji ditandai 'dibatalkan' supaya tidak masuk pendapatan/laporan.
            $st = $pdo->prepare("INSERT INTO tbl_transaksi (tanggal,id_pelanggan,total_belanja,status_pembayaran,metode_pembayaran,kode_invoice) VALUES (?,?,?,'dibatalkan','uji',?)");
            $st->execute([date('Y-m-d'), $customer, 12345, 'UJI' . date('ymdHis')]);
            if ($mode === 'commit') {
                $pdo->commit();
                flash('COMMIT berhasil. Data uji tersimpan di tabel transaksi (berstatus Dibatalkan, tidak dihitung di laporan).');
            } else {
                $pdo->rollBack();
                flash('ROLLBACK berhasil. Data uji dibatalkan dan tidak tersimpan.');
            }
            go('transaction');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($ex->getMessage(), 'error');
        go($page, $page === 'bayar' ? '&id=' . (int)($_GET['id'] ?? 0) : ($page === 'pengaturan' ? '&tab=' . urlencode((string)($_GET['tab'] ?? 'akun')) : ''));
    }
}

$flash     = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$search    = trim($_GET['q'] ?? '');
$customers = $pdo->query('SELECT * FROM tbl_pelanggan ORDER BY id_pelanggan DESC')->fetchAll();
$stats = [];
if ($role === 'admin') {
    $stats = [
        'customers'    => (int)$pdo->query('SELECT COUNT(*) FROM tbl_pelanggan')->fetchColumn(),
        'transactions' => (int)$pdo->query("SELECT COUNT(*) FROM tbl_transaksi WHERE status_pembayaran='dibayar'")->fetchColumn(),
        'revenue'      => (float)$pdo->query("SELECT COALESCE(SUM(total_belanja),0) FROM tbl_transaksi WHERE status_pembayaran='dibayar'")->fetchColumn(),
        'cities'       => (int)$pdo->query("SELECT COUNT(DISTINCT kota) FROM tbl_pelanggan WHERE kota IS NOT NULL AND kota<>''")->fetchColumn(),
        'sellers'      => (int)$pdo->query("SELECT COUNT(*) FROM tbl_user WHERE role='kasir'")->fetchColumn(),
    ];
} else {
    // Dashboard kasir hanya menghitung pelanggan dan transaksi yang terkait dengan akun login.
    $cashierId = (int)($_SESSION['auth']['id'] ?? 0);
    $qStats = $pdo->prepare("SELECT COUNT(*) transactions,
        COALESCE(SUM(total_belanja),0) revenue,
        COUNT(DISTINCT id_pelanggan) customers
        FROM tbl_transaksi
        WHERE status_pembayaran='dibayar' AND id_user=?");
    $qStats->execute([$cashierId]);
    $ownStats = $qStats->fetch(PDO::FETCH_ASSOC) ?: [];
    $qCities = $pdo->prepare("SELECT COUNT(DISTINCT p.kota)
        FROM tbl_transaksi t
        JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan
        WHERE t.status_pembayaran='dibayar' AND t.id_user=?
        AND p.kota IS NOT NULL AND p.kota<>''");
    $qCities->execute([$cashierId]);
    $stats = [
        'customers'    => (int)($ownStats['customers'] ?? 0),
        'transactions' => (int)($ownStats['transactions'] ?? 0),
        'revenue'      => (float)($ownStats['revenue'] ?? 0),
        'cities'       => (int)$qCities->fetchColumn(),
        'sellers'      => 0,
    ];
}

$titleMap = [
    'dashboard'  => 'Dashboard',
    'produk'     => 'Katalog Produk',
    'keranjang'  => 'Keranjang Belanja',
    'pelanggan'  => 'Data Pelanggan',
    'member'     => 'Member',
    'transaksi'  => 'Data Transaksi',
    'laporan'    => 'Laporan Toko',
    'pengaturan' => 'Pengaturan',
    'bayar'      => 'Pembayaran',
    'view'       => 'Pengujian VIEW',
    'join'       => 'Pengujian JOIN',
    'subquery'   => 'Pengujian Subquery',
    'procedure'  => 'Stored Procedure',
    'function'   => 'Pengujian Function',
    'trigger'    => 'Pengujian Trigger',
    'transaction'=> 'COMMIT & ROLLBACK',
    'explain'    => 'Analisis EXPLAIN'
];
$title = $titleMap[$page];

function rows(PDO $pdo, string $sql): array {
    return $pdo->query($sql)->fetchAll();
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · <?= e($NAMA_TOKO) ?></title>
<?php if ($LOGO_TOKO): ?><link rel="icon" href="<?= e($LOGO_TOKO) ?>"><?php endif ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=laporan-fix-2">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/barcode.js"></script>

</head>
<body>
<div class="app-shell">
<aside class="sidebar" id="sidebar">
  <a class="brand" href="index.php"><span class="brand-mark<?= $LOGO_TOKO ? ' has-logo' : '' ?>"><?php if ($LOGO_TOKO): ?><img src="<?= e($LOGO_TOKO) ?>" alt=""><?php else: ?>✦<?php endif ?></span><span><b><?= e($NAMA_TOKO) ?></b><small><?= $role==='admin' ? 'ADMIN PANEL' : 'KASIR' ?></small></span></a>
  <div class="side-label">MENU UTAMA</div>
  <nav>
    <a class="<?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><i>⌂</i>Dashboard</a>
    <a class="<?= $page==='produk'?'active':'' ?>" href="?page=produk"><i>▦</i>Katalog Produk</a>
    <a href="store.php" target="_blank" rel="noopener"><i>🛍</i>Lihat Toko Online</a>
    <a class="<?= $page==='keranjang'?'active':'' ?>" href="?page=keranjang"><i>🛒</i>Keranjang <span class="nav-count"><?= array_sum($_SESSION['cart'] ?? []) ?></span></a>
    <?php if ($role === 'admin'): ?>
    <a class="<?= $page==='pelanggan'?'active':'' ?>" href="?page=pelanggan"><i>♙</i>Pelanggan</a>
    <a href="barcode.php"><i>▮</i>Cetak Barcode</a>
    <?php endif ?>
    <a class="<?= $page==='member'?'active':'' ?>" href="?page=member"><i>★</i>Member</a>
    <a class="<?= in_array($page,['transaksi','bayar'])?'active':'' ?>" href="?page=transaksi"><i>▤</i>Transaksi</a>
    <a class="<?= in_array($page,['laporan','view','join','subquery'])?'active':'' ?>" href="?page=laporan"><i>▥</i>Laporan</a>
    <a class="<?= $page==='pengaturan'?'active':'' ?>" href="?page=pengaturan"><i>⚙</i>Pengaturan</a>
  </nav>
  <?php if ($role === 'admin'): ?>
  <div class="side-label">PENGUJIAN DATABASE</div>
  <nav>
    <a class="<?= $page==='view'?'active':'' ?>" href="?page=view"><i>◉</i>1. View</a>
    <a class="<?= $page==='join'?'active':'' ?>" href="?page=join"><i>⌁</i>2. JOIN</a>
    <a class="<?= $page==='subquery'?'active':'' ?>" href="?page=subquery"><i>⌕</i>3. Subquery</a>
    <a class="<?= $page==='procedure'?'active':'' ?>" href="?page=procedure"><i>▶</i>4. Stored Procedure</a>
    <a class="<?= $page==='function'?'active':'' ?>" href="?page=function"><i>ƒx</i>5. Function</a>
    <a class="<?= $page==='trigger'?'active':'' ?>" href="?page=trigger"><i>ϟ</i>6. Trigger</a>
    <a class="<?= $page==='transaction'?'active':'' ?>" href="?page=transaction"><i>⟳</i>7. COMMIT & ROLLBACK</a>
    <a class="<?= $page==='explain'?'active':'' ?>" href="?page=explain"><i>▥</i>8. EXPLAIN</a>
  </nav>
  <?php endif ?>
  <div class="sidebar-bottom">
    <div class="rocket">🚀</div>
    <b><?= e($NAMA_TOKO) ?></b>
    <small><?= $role==='admin' ? 'Kelola dan uji data tokomu.' : 'Layani transaksi pelanggan dengan cepat.' ?></small>
    <span>PHP Native · MySQL</span>
  </div>
</aside>

<main class="main">
<header class="topbar">
  <button class="mobile-menu" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
  <div class="searchbox">⌕ <form method="get"><input name="q" value="<?= e($search) ?>" placeholder="Cari produk, pelanggan, atau transaksi..."><input type="hidden" name="page" value="<?= $page ?>"></form></div>
  <div class="top-actions">
    <span class="status-dot"></span>
    <span>Mode toko offline</span>
    <a class="avatar" href="?page=pengaturan" title="Pengaturan akun"><?= e(mb_strtoupper(mb_substr($_SESSION['auth']['nama'], 0, 1))) ?></a>
    <b><?= e($_SESSION['auth']['nama']) ?> · <?= e(strtoupper($role)) ?></b>
    <a class="text-link" href="?logout=1">Keluar</a>
  </div>
</header>

<section class="content">
<div class="page-heading">
  <div>
    <div class="eyebrow"><?= e(strtoupper($NAMA_TOKO)) ?> / <?= e(strtoupper($role)) ?></div>
    <h1><?= e($title) ?></h1>
    <p><?= $page==='dashboard' ? 'Pantau aktivitas dan performa tokomu hari ini.' : ($page==='laporan' ? 'Analisis penjualan, produk, pelanggan, dan kinerja kasir. Unduh sebagai PDF atau Excel.' : ($page==='pengaturan' ? 'Atur akun, identitas toko, struk, dan cara kasir bekerja.' : ($page==='produk' ? 'Temukan berbagai pilihan produk terbaik di ' . e($NAMA_TOKO) . '.' : ($role==='admin' ? 'Kelola data dan jalankan pengujian database db_tokoku2.' : 'Lihat ringkasan data toko dan cetak laporan.')))) ?></p>
  </div>
  <div class="date-chip">◷ <?= date('d M Y') ?></div>
</div>

<?php if ($flash): ?>
<div class="toast <?= $flash[1]==='error'?'toast-error':'toast-ok' ?>" id="toast" role="status">
  <span class="toast-ic"><?= $flash[1]==='error'?'✕':'✓' ?></span>
  <span class="toast-msg"><?= e($flash[0]) ?></span>
  <button type="button" class="toast-x" onclick="closeToast()">×</button>
  <i class="toast-bar"></i>
</div>
<?php endif; ?>

<?php if ($page === 'dashboard'): ?>
<?php
$stokRendah = 0;
if ($STOK_MENIPIS > 0) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM tbl_produk WHERE stok<=?');
    $q->execute([$STOK_MENIPIS]);
    $stokRendah = (int)$q->fetchColumn();
}
?>
<?php if ($stokRendah > 0): ?>
<div class="info-banner stock-alert"><b>⚠ <?= $stokRendah ?> produk stoknya menipis</b><span>Stok sama dengan atau di bawah <?= $STOK_MENIPIS ?>. <a class="text-link" href="?page=produk&stok=menipis">Lihat produk ↗</a></span></div>
<?php endif ?>
<div class="stats-grid">
  <div class="stat-card blue"><div class="stat-icon">♙</div><span>Total Pelanggan</span><strong><?= $stats['customers'] ?></strong><small>Data pelanggan terdaftar</small><div class="spark">⌁</div></div>
  <div class="stat-card green"><div class="stat-icon">▤</div><span>Total Transaksi</span><strong><?= $stats['transactions'] ?></strong><small>Transaksi lunas</small><div class="spark">⌁</div></div>
  <div class="stat-card orange"><div class="stat-icon">◈</div><span>Total Pendapatan</span><strong><?= rupiah($stats['revenue']) ?></strong><small>Akumulasi transaksi lunas</small><div class="spark">⌁</div></div>
  <div class="stat-card purple"><div class="stat-icon">⌖</div><span>Kota Pelanggan</span><strong><?= $stats['cities'] ?></strong><small>Kota berbeda</small><div class="spark">⌁</div></div>
  <?php if ($role === 'admin'): ?>
  <div class="stat-card blue"><div class="stat-icon">♟</div><span>Total Kasir</span><strong><?= $stats['sellers'] ?></strong><small>Akun kasir terdaftar</small><div class="spark">⌁</div></div>
  <?php endif ?>
</div>
<div class="dashboard-grid"<?= $role!=='admin' ? ' style="grid-template-columns:1fr"' : '' ?>>
  <div class="panel chart-panel">
    <div class="panel-head"><div><h3>Ringkasan Transaksi</h3><p>Jumlah transaksi berdasarkan tanggal</p></div><span class="tag">LIVE DATA</span></div>
    <canvas id="salesChart" height="110"></canvas>
  </div>
  <?php if ($role === 'admin'): ?>
  <div class="panel">
    <div class="panel-head"><div><h3>Pengujian Akhir</h3><p>8 materi pengujian database</p></div><span class="mini-icon">✦</span></div>
    <div class="test-shortcuts">
    <?php foreach ([['view','View','Lihat laporan'],['join','JOIN','Gabungkan tabel'],['subquery','Subquery','Analisis data'],['procedure','Stored Procedure','Panggil prosedur'],['function','Function','Hitung nilai'],['trigger','Trigger','Otomatis berjalan'],['transaction','COMMIT & ROLLBACK','Uji transaksi'],['explain','EXPLAIN','Analisis query']] as $i => $x): ?>
      <a href="?page=<?= $x[0] ?>" class="test-link"><span><?= sprintf('%02d', $i+1) ?></span><div><b><?= $x[1] ?></b><small><?= $x[2] ?></small></div><i>↗</i></a>
    <?php endforeach ?>
    </div>
  </div>
  <?php endif ?>
</div>
<div class="panel table-panel">
  <div class="panel-head"><div><h3>Transaksi Terbaru</h3><p>Data transaksi terakhir dari database</p></div><a class="text-link" href="?page=transaksi">Lihat semua ↗</a></div>
  <?php
  if ($role === 'admin') {
      $recent = rows($pdo, "SELECT t.id_transaksi,t.tanggal,p.nama_pelanggan,t.total_belanja FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan ORDER BY t.id_transaksi DESC LIMIT 5");
  } else {
      $recentStmt = $pdo->prepare("SELECT t.id_transaksi,t.tanggal,p.nama_pelanggan,t.total_belanja FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan WHERE t.id_user=? ORDER BY t.id_transaksi DESC LIMIT 5");
      $recentStmt->execute([(int)$_SESSION['auth']['id']]);
      $recent = $recentStmt->fetchAll();
  }
?>
  <?php renderTable($recent, ['id_transaksi'=>'ID','nama_pelanggan'=>'Pelanggan','tanggal'=>'Tanggal','total_belanja'=>'Total'], 'money'); ?>
</div>

<?php elseif ($page === 'produk'): ?>
<?php
$cats = $pdo->query("SELECT DISTINCT kategori FROM tbl_produk ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);
$editProduct = null;
if ($role === 'admin' && isset($_GET['edit'])) {
    $ep = $pdo->prepare('SELECT * FROM tbl_produk WHERE id_produk=?');
    $ep->execute([(int)$_GET['edit']]);
    $editProduct = $ep->fetch() ?: null;
}
$category = trim($_GET['kategori'] ?? '');
$sort     = $_GET['sort'] ?? 'newest';
$order    = match ($sort) {
    'price_low'  => 'harga*(1-diskon/100) ASC',
    'price_high' => 'harga*(1-diskon/100) DESC',
    'popular'    => 'terjual DESC',
    'rating'     => 'rating DESC',
    default      => 'id_produk DESC'
};
$sql  = "SELECT * FROM tbl_produk WHERE (nama_produk LIKE ? OR kategori LIKE ? OR deskripsi LIKE ?)";
$args = ["%$search%", "%$search%", "%$search%"];
if ($category !== '') {
    $sql   .= " AND kategori=?";
    $args[] = $category;
}
$filterStok = ($_GET['stok'] ?? '') === 'menipis';
if ($filterStok) {
    $sql   .= " AND stok<=?";
    $args[] = $STOK_MENIPIS;
}
$sql .= " ORDER BY $order";
$st = $pdo->prepare($sql);
$st->execute($args);
$products = $st->fetchAll();
?>
<?= scanBar('produk') ?>
<div class="catalog-toolbar">
  <div>
    <span class="catalog-kicker"><?= e(strtoupper($NAMA_TOKO)) ?> MARKETPLACE</span>
    <h2>Temukan produk favoritmu</h2>
    <p>Belanja lebih mudah, pilihan lebih banyak.</p>
  </div>
  <?php if ($role === 'admin'): ?>
  <button class="btn primary" onclick="document.getElementById('productForm').classList.toggle('show')">＋ Tambah Produk</button>
  <?php endif ?>
</div>

<?php if ($role === 'admin'): ?>
<div class="panel product-form-panel <?= $editProduct ? 'show' : '' ?>" id="productForm">
  <div class="panel-head">
    <div><h3><?= $editProduct ? 'Edit Produk' : 'Tambah Produk Baru' ?></h3><p>Isi atau perbarui informasi produk.</p></div>
    <?php if ($editProduct): ?><a class="text-link" href="?page=produk">Batal edit</a><?php endif ?>
  </div>
  <form method="post" class="product-form" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save_product">
    <input type="hidden" name="id_produk" value="<?= (int)($editProduct['id_produk'] ?? 0) ?>">
    <label>Nama produk<input name="nama_produk" required placeholder="Contoh: Headset Gaming RGB" value="<?= e($editProduct['nama_produk'] ?? '') ?>"></label>
    <label>Kategori<input name="kategori" required placeholder="Elektronik, Fashion..." value="<?= e($editProduct['kategori'] ?? '') ?>"></label>
    <label>Harga (Rp)<input type="number" name="harga" min="1" required placeholder="150000" value="<?= e($editProduct['harga'] ?? '') ?>"></label>
    <label>Diskon (%)<input type="number" name="diskon" min="0" max="90" value="<?= e($editProduct['diskon'] ?? 0) ?>"></label>
    <label>Stok<input type="number" name="stok" min="0" value="<?= e($editProduct['stok'] ?? 10) ?>"></label>
    <label>Barcode (kosongkan = dibuat otomatis)<input name="barcode" maxlength="32" onkeydown="if(event.key==='Enter')event.preventDefault()" placeholder="Scan barcode kemasan di sini" value="<?= e($editProduct['barcode'] ?? '') ?>"></label>
    <?php if (!empty($editProduct['barcode'])): ?>
    <div class="barcode-preview"><svg class="bc" data-code="<?= e($editProduct['barcode']) ?>" style="width:220px;height:56px;background:#fff;border-radius:6px"></svg><small><?= e($editProduct['barcode']) ?></small><a class="text-link" target="_blank" href="barcode.php?id=<?= (int)$editProduct['id_produk'] ?>&n=12">Cetak 12 label ↗</a></div>
    <?php endif ?>
    <label>Foto Produk (JPG/PNG/WEBP, maks 2 MB)<input type="file" name="foto" id="fotoInput" accept="image/jpeg,image/png,image/webp,image/gif"></label>
    <div class="foto-preview" id="fotoPreview"><span>Pratinjau foto</span></div>
    <label class="wide">Deskripsi<textarea name="deskripsi" rows="2" placeholder="Deskripsi singkat produk"><?= e($editProduct['deskripsi'] ?? '') ?></textarea></label>
    <button class="btn primary"><?= $editProduct ? 'Simpan Perubahan' : 'Simpan Produk' ?></button>
  </form>
</div>
<?php endif ?>

<div class="catalog-filter">
  <div class="category-chips">
    <a class="<?= $category==='' && !$filterStok ?'selected':'' ?>" href="?page=produk">Semua</a>
    <?php foreach ($cats as $cat): ?>
    <a class="<?= $category===$cat?'selected':'' ?>" href="?page=produk&kategori=<?= urlencode($cat) ?>"><?= e($cat) ?></a>
    <?php endforeach ?>
    <?php if ($STOK_MENIPIS > 0): ?><a class="<?= $filterStok?'selected':'' ?>" href="?page=produk&stok=menipis">⚠ Stok menipis</a><?php endif ?>
  </div>
  <form method="get" class="sort-form">
    <input type="hidden" name="page" value="produk">
    <input type="hidden" name="q" value="<?= e($search) ?>">
    <select name="sort" onchange="this.form.submit()">
      <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Terbaru</option>
      <option value="popular" <?= $sort==='popular'?'selected':'' ?>>Paling laris</option>
      <option value="rating" <?= $sort==='rating'?'selected':'' ?>>Rating tertinggi</option>
      <option value="price_low" <?= $sort==='price_low'?'selected':'' ?>>Harga termurah</option>
      <option value="price_high" <?= $sort==='price_high'?'selected':'' ?>>Harga tertinggi</option>
    </select>
  </form>
</div>

<div class="catalog-meta"><b><?= count($products) ?> produk</b><span>Menampilkan pilihan terbaik untukmu</span></div>

<div class="product-grid">
<?php foreach ($products as $p):
    $p += ['diskon'=>0,'gambar'=>'','rating'=>5,'terjual'=>0];
    $final = (float)$p['harga'] * (1 - (float)$p['diskon'] / 100);
?>
<article class="product-card">
  <div class="product-image">
    <?php if ($p['gambar']): ?>
    <img src="<?= e($p['gambar']) ?>" alt="<?= e($p['nama_produk']) ?>" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
    <?php endif ?>
    <div class="product-placeholder" style="<?= !$p['gambar'] ? 'display:grid' : '' ?>">✦<small><?= e($p['kategori']) ?></small></div>
    <?php if ((float)$p['diskon'] > 0): ?>
    <span class="discount-badge">-<?= rtrim(rtrim(number_format((float)$p['diskon'], 1), '0'), '.') ?>%</span>
    <?php endif ?>
  </div>
  <div class="product-info">
    <span class="product-category"><?= e($p['kategori']) ?></span>
    <h3><?= e($p['nama_produk']) ?></h3>
    <div class="price-line">
      <strong><?= rupiah($final) ?></strong>
      <?php if ((float)$p['diskon'] > 0): ?><del><?= rupiah($p['harga']) ?></del><?php endif ?>
    </div>
    <div class="product-meta">
      <span>⭐ <?= number_format((float)$p['rating'], 1) ?></span>
      <span>Terjual <?= number_format((int)$p['terjual'], 0, ',', '.') ?></span>
    </div>
    <?php $rendah = $STOK_MENIPIS > 0 && (int)$p['stok'] <= $STOK_MENIPIS; ?>
    <div class="stock-line<?= $rendah ? ' low' : '' ?>">Stok <?= number_format((int)$p['stok'], 0, ',', '.') ?><?= $rendah ? ((int)$p['stok'] < 1 ? ' · habis' : ' · menipis') : '' ?></div>
    <form method="post">
      <input type="hidden" name="action" value="add_cart">
      <input type="hidden" name="id_produk" value="<?= $p['id_produk'] ?>">
      <button class="add-cart" <?= $p['stok'] < 1 ? 'disabled' : '' ?>>＋ Keranjang</button>
    </form>

    <?php if ($role === 'admin'): ?>
    <div class="product-admin">
      <a class="btn-admin btn-edit" href="?page=produk&edit=<?= $p['id_produk'] ?>" title="Edit produk">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        <span>Edit</span>
      </a>
      <form method="post" enctype="multipart/form-data" class="change-photo">
        <input type="hidden" name="action" value="update_product_image">
        <input type="hidden" name="id_produk" value="<?= $p['id_produk'] ?>">
        <label class="btn-admin btn-photo" title="Ganti foto">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
          <span>Ganti Foto</span>
          <input type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif" hidden onchange="this.form.submit()">
        </label>
      </form>
      <form method="post" data-confirm="Hapus produk ini?" data-title="Hapus produk?" data-ok="Ya, hapus" data-variant="danger" data-icon="🗑">
        <input type="hidden" name="action" value="delete_product">
        <input type="hidden" name="id_produk" value="<?= $p['id_produk'] ?>">
        <button type="submit" class="btn-admin btn-delete" title="Hapus produk">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
          <span>Hapus</span>
        </button>
      </form>
    </div>
    <?php endif ?>
  </div>
</article>
<?php endforeach ?>

<?php if (!$products): ?>
<div class="empty-catalog"><span>⌕</span><b>Produk belum ditemukan</b><p>Coba kata kunci atau kategori yang berbeda.</p></div>
<?php endif ?>
</div>

<?php elseif ($page === 'keranjang'): ?>
<?php
$cart       = $_SESSION['cart'] ?? [];
$cartRows   = [];
$grand      = 0;
$member     = currentMember($pdo);
$memberDisc = 0.0;
if ($cart) {
    $ids = array_map('intval', array_keys($cart));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT * FROM tbl_produk WHERE id_produk IN ($in)");
    $st->execute($ids);
    $cartRows = $st->fetchAll();
    $preTotal = 0.0;
    foreach ($cartRows as $r0) {
        $preTotal += round((float)$r0['harga'] * (1 - (float)$r0['diskon'] / 100), 2) * (int)($cart[$r0['id_produk']] ?? 0);
    }
    if ($member) $memberDisc = round($preTotal * (float)$member['diskon_persen'] / 100, 2);
}
?>
<?= scanBar('keranjang') ?>
<div class="member-box <?= $member ? 'on' : '' ?>">
<?php if ($member): ?>
  <div><span class="member-star">★ MEMBER AKTIF</span><b><?= e($member['nama_pelanggan']) ?></b><small>Kode <?= e($member['kode_member']) ?> · Diskon <?= (float)$member['diskon_persen'] ?>%<?= $cartRows ? ' · Hemat ' . rupiah($memberDisc) . ' pada transaksi ini' : ' · diterapkan saat ada produk di keranjang' ?></small></div>
  <form method="post"><input type="hidden" name="action" value="clear_member"><button class="btn tiny">Lepas member</button></form>
<?php else:
    $memberList = $pdo->query("SELECT m.kode_member, p.nama_pelanggan FROM tbl_member m JOIN tbl_pelanggan p ON p.id_pelanggan=m.id_pelanggan WHERE m.aktif=1 ORDER BY p.nama_pelanggan LIMIT 500")->fetchAll();
?>
  <div style="flex:1">
    <span class="member-star off">☆ Belum ada member dipilih</span>
    <small>Pembeli member? Scan kartunya di kotak scan atas, atau pilih / ketik di sini agar dapat diskon.</small>
    <form method="post" class="member-pick" autocomplete="off">
      <input type="hidden" name="action" value="pick_member">
      <input name="kode_member" list="memberList" placeholder="Nama, kode, atau no. HP member">
      <datalist id="memberList">
        <?php foreach ($memberList as $ml): ?><option value="<?= e($ml['kode_member']) ?>" label="<?= e($ml['nama_pelanggan']) ?>"></option><?php endforeach ?>
      </datalist>
      <button class="btn primary tiny">Pakai member</button>
    </form>
  </div>
  <a class="btn tiny" href="?page=member">Daftar member baru</a>
<?php endif ?>
</div>
<div class="panel table-panel">
  <div class="panel-head">
    <div><h3>Keranjang Belanja</h3><p>Periksa kembali produk pilihanmu.</p></div>
    <a class="text-link" href="?page=produk">← Lanjut belanja</a>
  </div>
  <?php if (!$cartRows): ?>
  <div class="empty-catalog"><span>🛒</span><b>Keranjang masih kosong</b><p>Yuk, cari produk yang kamu suka.</p><a class="btn primary" href="?page=produk">Mulai belanja</a></div>
  <?php else: ?>
  <form method="post" id="cartForm">
    <input type="hidden" name="action" value="update_cart">
    <div class="cart-list">
    <?php foreach ($cartRows as $p):
        $qty   = (int)$cart[$p['id_produk']];
        $price = (float)$p['harga'] * (1 - (float)$p['diskon'] / 100);
        $sub   = $price * $qty;
        $grand += $sub;
    ?>
      <div class="cart-row">
        <div class="cart-thumb"><?php if ($p['gambar']): ?><img src="<?= e($p['gambar']) ?>" alt=""><?php else: ?>✦<?php endif ?></div>
        <div class="cart-product"><b><?= e($p['nama_produk']) ?></b><small><?= e($p['kategori']) ?></small><strong><?= rupiah($price) ?></strong></div>
        <div class="qty-wrap">
          <small>Jumlah</small>
          <div class="qty-step">
            <button type="button" data-d="-1" aria-label="Kurangi">−</button>
            <input type="number" min="0" max="<?= (int)$p['stok'] ?>" name="qty[<?= $p['id_produk'] ?>]" value="<?= $qty ?>">
            <button type="button" data-d="1" aria-label="Tambah">+</button>
          </div>
          <small class="qty-stock">Stok <?= (int)$p['stok'] ?></small>
        </div>
        <b><?= rupiah($sub) ?></b>
      </div>
    <?php endforeach ?>
    </div>
    <div class="cart-summary">
      <button class="btn primary">Perbarui Keranjang</button>
      <span class="auto-note">Perubahan jumlah tersimpan otomatis.</span>
      <div><small>Total sementara</small><strong><?= rupiah($grand) ?></strong></div>
    </div>
  </form>
  <form method="post" class="checkout-form">
    <input type="hidden" name="action" value="checkout">
    <h3>Checkout</h3>
    <div class="checkout-grid">
      <div>
      <?php if ($member): ?>
        <label>Pelanggan (member)</label>
        <input value="<?= e($member['nama_pelanggan']) ?>" disabled>
        <small>Transaksi tercatat atas nama member dan mendapat diskon <?= (float)$member['diskon_persen'] ?>%.</small>
      <?php else: ?>
        <label>Nama pelanggan (opsional)</label>
        <input name="nama_baru" placeholder="Kosongkan untuk Pelanggan Umum">
        <small>Pelanggan tidak perlu login atau memiliki akun. Kalau pembeli adalah member, pilih member di kotak MEMBER di atas supaya dapat diskon.</small>
      <?php endif ?>
      </div>
      <div>
        <label>Metode pembayaran</label>
        <div class="pay-methods">
          <?php foreach ($METODE_AKTIF as $i => $mk): ?>
          <label class="pay-option"><input type="radio" name="metode" value="<?= e($mk) ?>" <?= $i === 0 ? 'checked' : '' ?>><span><b><?= e($METODE_POS[$mk]['label']) ?></b><small><?= e($METODE_POS[$mk]['hint']) ?></small></span></label>
          <?php endforeach ?>
        </div>
      </div>
    </div>
    <div class="cart-summary">
      <div class="co-note">Transaksi langsung dicatat lunas setelah pembayaran diterima oleh kasir.</div>
      <div>
        <?php if ($member): ?><small>Diskon member <?= (float)$member['diskon_persen'] ?>%: -<?= rupiah($memberDisc) ?></small><?php endif ?>
        <small>Total bayar</small>
        <strong><?= rupiah($grand - $memberDisc) ?></strong>
        <button class="btn primary" style="margin-top:10px">Selesaikan Transaksi →</button>
      </div>
    </div>
  </form>
  <?php endif ?>
</div>

<?php elseif ($page === 'bayar'): ?>
<?php
$idT  = (int)($_GET['id'] ?? 0);
$sqlOrder = 'SELECT t.*,p.nama_pelanggan,p.kota FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan WHERE t.id_transaksi=?';
$orderArgs = [$idT];
if ($role !== 'admin') {
    $sqlOrder .= ' AND t.id_user=?';
    $orderArgs[] = (int)$_SESSION['auth']['id'];
}
$st = $pdo->prepare($sqlOrder);
$st->execute($orderArgs);
$order = $st->fetch();
$items = [];
$m     = null;
$sisa  = 0;
if ($order) {
    $st = $pdo->prepare('SELECT d.*,pr.nama_produk,pr.gambar FROM tbl_detail_transaksi d JOIN tbl_produk pr ON pr.id_produk=d.id_produk WHERE d.id_transaksi=?');
    $st->execute([$idT]);
    $items = $st->fetchAll();
    $m     = $PAYMENT_METHODS[$order['metode_pembayaran'] ?? ''] ?? null;
    $sisa  = $order['batas_bayar'] ? max(0, strtotime($order['batas_bayar']) - time()) : 0;
}
?>
<?php if (!$order): ?>
<div class="panel"><div class="empty-catalog"><span>⌕</span><b>Transaksi tidak ditemukan</b><a class="btn primary" href="?page=transaksi">← Ke daftar transaksi</a></div></div>
<?php else:
    $stt = (string)$order['status_pembayaran'];
?>
<div class="pay-layout">
  <div class="panel">
    <div class="panel-head">
      <div>
        <h3>Invoice <?= e($order['kode_invoice'] ?: '#' . $order['id_transaksi']) ?></h3>
        <p><?= e($order['tanggal']) ?> · <?= e($order['nama_pelanggan']) ?><?= $order['kota'] ? ' · ' . e($order['kota']) : '' ?></p>
      </div>
      <?= badge($stt) ?>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Produk</th><th>Harga</th><th>Qty</th><th>Subtotal</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><?= e($it['nama_produk']) ?></td>
            <td><?= rupiah($it['harga_satuan']) ?></td>
            <td><?= $it['jumlah'] ?></td>
            <td><?= rupiah($it['subtotal']) ?></td>
          </tr>
        <?php endforeach ?>
        <?php if (!$items): ?><tr><td colspan="4" class="empty">Transaksi manual (tanpa rincian produk).</td></tr><?php endif ?>
        </tbody>
      </table>
    </div>
    <?php if ((float)($order['diskon_member'] ?? 0) > 0): ?>
    <div class="invoice-total" style="opacity:.8"><span>Subtotal</span><strong><?= rupiah((float)$order['total_belanja'] + (float)$order['diskon_member']) ?></strong></div>
    <div class="invoice-total" style="opacity:.8"><span>Diskon member (<?= (float)$order['diskon_member_persen'] ?>%)</span><strong>-<?= rupiah($order['diskon_member']) ?></strong></div>
    <?php endif ?>
    <div class="invoice-total"><span>Total</span><strong><?= rupiah($order['total_belanja']) ?></strong></div>
    <?php if ($m): ?><p class="co-note">Metode: <b><?= e($m['label']) ?></b></p><?php endif ?>
    <p><a class="text-link" href="?page=transaksi">← Kembali ke transaksi</a></p>
  </div>

  <div class="panel pay-panel">
  <?php if ($stt === 'menunggu'): ?>
    <div class="panel-head"><div><h3>Selesaikan Pembayaran</h3><p>Bayar sebelum waktu habis, atau pesanan otomatis dibatalkan.</p></div></div>
    <div class="countdown" id="countdown" data-sisa="<?= $sisa ?>">--:--:--</div>
    <div class="pay-amount"><small>Jumlah yang harus dibayar</small><strong><?= rupiah($order['total_belanja']) ?></strong></div>
    <?php if ($m && $m['type'] === 'qris'): ?>
      <div class="qr-box">
        <?php if (is_file(__DIR__ . '/' . $m['image'])): ?>
        <img src="<?= e($m['image']) ?>" alt="QRIS TokoKu">
        <?php else: ?>
        <div id="qr"></div>
        <small><b>QRIS DUMMY / DEMO</b><br>QR ini hanya untuk simulasi dan tidak dapat menerima pembayaran sungguhan.</small>
        <?php endif ?>
      </div>
      <p class="co-note">Mode demo: QRIS ini hanya contoh tampilan, bukan kode pembayaran aktif. Petugas dapat memeriksa pembayaran simulasi lalu menekan konfirmasi.</p>
    <?php elseif ($m): ?>
      <div class="pay-dest">
        <small><?= $m['type']==='bank' ? 'Rekening tujuan' : 'Nomor tujuan' ?> · <?= e($m['label']) ?></small>
        <strong id="payDest"><?= e($m['tujuan']) ?></strong>
        <span>a.n. <?= e($m['atas_nama']) ?></span>
        <button type="button" class="btn tiny" onclick="navigator.clipboard.writeText(document.getElementById('payDest').textContent);this.textContent='Tersalin ✓'">Salin</button>
      </div>
    <?php endif ?>
    <form method="post" enctype="multipart/form-data" class="bukti-form">
      <input type="hidden" name="action" value="upload_bukti">
      <input type="hidden" name="id_transaksi" value="<?= $order['id_transaksi'] ?>">
      <label>Unggah bukti pembayaran (opsional)<input type="file" name="bukti" accept="image/jpeg,image/png,image/webp" required></label>
      <button class="btn tiny">Unggah bukti</button>
    </form>
    <?php if ($order['bukti_bayar']): ?>
    <div class="bukti-img"><small>Bukti terunggah:</small><a href="<?= e($order['bukti_bayar']) ?>" target="_blank"><img src="<?= e($order['bukti_bayar']) ?>" alt="Bukti pembayaran"></a></div>
    <?php endif ?>
    <div class="pay-actions">
      <form method="post" data-confirm="Pastikan uang sudah masuk. Konfirmasi pembayaran?" data-title="Konfirmasi pembayaran?" data-ok="Ya, sudah masuk" data-variant="success" data-icon="✓">
        <input type="hidden" name="action" value="pay_confirm">
        <input type="hidden" name="id_transaksi" value="<?= $order['id_transaksi'] ?>">
        <button class="btn primary">✓ Konfirmasi pembayaran diterima</button>
      </form>
      <form method="post" data-confirm="Batalkan pesanan ini?" data-title="Batalkan pesanan?" data-ok="Ya, batalkan" data-variant="danger" data-icon="⚠">
        <input type="hidden" name="action" value="pay_cancel">
        <input type="hidden" name="id_transaksi" value="<?= $order['id_transaksi'] ?>">
        <button class="btn danger-btn">Batalkan pesanan</button>
      </form>
    </div>
  <?php elseif ($stt === 'dibayar'): ?>
    <div class="pay-state ok">
      <div class="big-symbol green-text">✓</div>
      <h3>Pembayaran diterima</h3>
      <p><?= $order['dibayar_pada'] ? 'Lunas pada ' . e($order['dibayar_pada']) : 'Transaksi lunas.' ?></p>
      <a class="btn primary" href="struk.php?id=<?= $order['id_transaksi'] ?>&print=1" target="_blank">🖨 Cetak Struk</a>
    </div>
    <?php if ($order['bukti_bayar']): ?>
    <div class="bukti-img"><small>Bukti pembayaran:</small><a href="<?= e($order['bukti_bayar']) ?>" target="_blank"><img src="<?= e($order['bukti_bayar']) ?>" alt="Bukti"></a></div>
    <?php endif ?>
  <?php else: ?>
    <div class="pay-state bad">
      <div class="big-symbol red-text">✕</div>
      <h3><?= $stt === 'dibatalkan' ? 'Pesanan dibatalkan' : 'Waktu pembayaran habis' ?></h3>
      <p>Stok produk sudah dikembalikan. Silakan buat pesanan baru dari katalog.</p>
      <a class="btn primary" href="?page=produk">Ke katalog</a>
    </div>
  <?php endif ?>
  </div>
</div>
<?php endif ?>

<?php elseif ($page === 'member'): ?>
<?php
$ms = trim($_GET['q'] ?? '');
$st = $pdo->prepare("SELECT m.*, p.nama_pelanggan, p.kota,
    (SELECT COALESCE(SUM(t.diskon_member),0) FROM tbl_transaksi t WHERE t.id_member=m.id_member AND t.status_pembayaran='dibayar') AS total_hemat
    FROM tbl_member m JOIN tbl_pelanggan p ON p.id_pelanggan=m.id_pelanggan
    WHERE p.nama_pelanggan LIKE ? OR m.kode_member LIKE ? OR m.no_hp LIKE ?
    ORDER BY m.id_member DESC");
$st->execute(["%$ms%", "%$ms%", "%$ms%"]);
$members   = $st->fetchAll();
$nonMember = $pdo->query("SELECT id_pelanggan,nama_pelanggan,kota FROM tbl_pelanggan WHERE id_pelanggan NOT IN (SELECT id_pelanggan FROM tbl_member) ORDER BY nama_pelanggan")->fetchAll();
?>
<div class="panel">
  <div class="panel-head"><div><h3>Daftarkan Member</h3><p>Member otomatis mendapat potongan harga <?= (float)$MEMBER_DISKON_DEFAULT ?>% (default) pada setiap transaksi.</p></div></div>
  <form method="post" class="product-form" autocomplete="off">
    <input type="hidden" name="action" value="member_add">
    <label>Jadikan member dari pelanggan lama
      <select name="id_pelanggan_lama">
        <option value="0">- Pelanggan baru (isi form di bawah) -</option>
        <?php foreach ($nonMember as $nm): ?>
        <option value="<?= (int)$nm['id_pelanggan'] ?>"><?= e($nm['nama_pelanggan']) ?><?= $nm['kota'] ? ' · ' . e($nm['kota']) : '' ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label>Nama member<input name="nama_pelanggan" placeholder="Nama lengkap"></label>
    <label>Kota<input name="kota" placeholder="Ponorogo"></label>
    <label>Nomor HP<input name="no_hp" inputmode="numeric" placeholder="08xxxxxxxxxx"></label>
    <?php if ($role === 'admin'): ?>
    <label>Diskon member (%)<input type="number" name="diskon_persen" min="0" max="50" step="0.5" value="<?= (float)$MEMBER_DISKON_DEFAULT ?>"></label>
    <?php endif ?>
    <label>Kartu RFID (opsional, tap di reader)<input type="password" name="rfid" autocomplete="off" onkeydown="if(event.key==='Enter')event.preventDefault()" placeholder="Tap kartu RFID"></label>
    <button class="btn primary">Daftarkan Member</button>
  </form>
</div>

<div class="panel table-panel" style="margin-top:18px">
  <div class="panel-head"><div><h3>Daftar Member</h3><p><?= count($members) ?> member<?= $ms !== '' ? ' (hasil pencarian)' : '' ?></p></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Member</th><th>Kode</th><th>HP</th><th>Diskon</th><th>Total hemat</th><th>Status</th><th>Kartu</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($members as $m): ?>
        <tr>
          <td><b><?= e($m['nama_pelanggan']) ?></b><br><small style="color:#8999b8"><?= e($m['kota']) ?></small></td>
          <td><?= e($m['kode_member']) ?></td>
          <td><?= e($m['no_hp'] ?: '-') ?></td>
          <td><?= $role === 'admin' ? '' : (float)$m['diskon_persen'] . '%' ?>
            <?php if ($role === 'admin'): ?>
            <form method="post" class="member-edit" autocomplete="off">
              <input type="hidden" name="action" value="member_update">
              <input type="hidden" name="id_member" value="<?= (int)$m['id_member'] ?>">
              <input type="number" name="diskon_persen" min="0" max="50" step="0.5" value="<?= (float)$m['diskon_persen'] ?>" title="Diskon (%)">
              <select name="aktif"><option value="1" <?= $m['aktif'] ? 'selected' : '' ?>>Aktif</option><option value="0" <?= !$m['aktif'] ? 'selected' : '' ?>>Nonaktif</option></select>
              <input type="password" name="rfid" placeholder="<?= $m['kode_rfid'] ? 'Ganti RFID' : 'Tap RFID' ?>" onkeydown="if(event.key==='Enter')event.preventDefault()">
              <button class="btn tiny">Simpan</button>
            </form>
            <?php endif ?>
          </td>
          <td><?= rupiah($m['total_hemat']) ?></td>
          <td><?= $m['aktif'] ? '<span class="badge st-dibayar">Aktif</span>' : '<span class="badge st-dibatalkan">Nonaktif</span>' ?></td>
          <td><?= $m['kode_rfid'] ? 'RFID ✓' : '-' ?></td>
          <td><a class="btn tiny" target="_blank" href="kartu_member.php?id=<?= (int)$m['id_member'] ?>">Cetak kartu</a></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$members): ?><tr><td colspan="8" class="empty">Belum ada member.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($page === 'pengaturan'): ?>
<?php
$tabs = $role === 'admin'
    ? ['akun' => 'Akun Saya', 'toko' => 'Toko & Struk', 'transaksi' => 'Transaksi & Member', 'staf' => 'Akun Staf']
    : ['akun' => 'Akun Saya'];
$tab = (string)($_GET['tab'] ?? 'akun');
if (!isset($tabs[$tab])) $tab = 'akun';
$stMe = $pdo->prepare('SELECT nama, username, role, dibuat_pada, kode_kartu, kode_rfid FROM tbl_user WHERE id_user=?');
$stMe->execute([(int)$_SESSION['auth']['id']]);
$me = $stMe->fetch();
?>
<?php if (count($tabs) > 1): ?>
<div class="settings-tabs" role="tablist">
  <?php foreach ($tabs as $k => $label): ?>
  <a class="<?= $tab === $k ? 'on' : '' ?>" href="?page=pengaturan&tab=<?= $k ?>" role="tab"><?= e($label) ?></a>
  <?php endforeach ?>
</div>
<?php endif ?>

<?php if ($tab === 'akun'): ?>
<div class="settings-grid">
  <div class="panel">
    <div class="panel-head"><div><h3>Profil</h3><p>Nama ini tampil di pojok kanan atas dan di catatan transaksi.</p></div>
      <span class="badge <?= $role === 'admin' ? 'st-menunggu' : 'st-dibayar' ?>"><?= e(strtoupper($role)) ?></span></div>
    <form method="post" class="set-form">
      <?= csrf() ?>
      <input type="hidden" name="action" value="save_profile">
      <label>Nama tampilan<input name="nama" required maxlength="100" value="<?= e($me['nama'] ?? '') ?>"></label>
      <label>Username<input value="<?= e($me['username'] ?? '') ?>" disabled></label>
      <p class="set-note">Akun dibuat <?= e(substr((string)($me['dibuat_pada'] ?? ''), 0, 10)) ?>.
        <?= (!empty($me['kode_kartu']) || !empty($me['kode_rfid'])) ? 'Kartu login (barcode/RFID) sudah terdaftar.' : 'Belum ada kartu login terdaftar.' ?>
        <?= $role === 'admin' ? 'Username dan role diubah di tab Akun Staf.' : 'Username dan role hanya bisa diubah admin.' ?></p>
      <button class="btn primary">Simpan Profil</button>
    </form>
  </div>
  <div class="panel">
    <div class="panel-head"><div><h3>Ganti Password</h3><p>Gunakan minimal 8 karakter yang tidak dipakai di tempat lain.</p></div></div>
    <form method="post" class="set-form" autocomplete="off">
      <?= csrf() ?>
      <input type="hidden" name="action" value="change_password">
      <label>Password saat ini<input type="password" name="password_lama" required autocomplete="current-password"></label>
      <label>Password baru<input type="password" name="password_baru" required minlength="8" autocomplete="new-password"></label>
      <label>Ulangi password baru<input type="password" name="password_ulang" required minlength="8" autocomplete="new-password"></label>
      <button class="btn primary">Ganti Password</button>
    </form>
  </div>
</div>

<?php elseif ($tab === 'toko'): ?>
<div class="settings-grid with-preview">
  <div class="panel">
    <div class="panel-head"><div><h3>Identitas Toko</h3><p>Dipakai di sidebar, halaman login, struk, dan kartu member.</p></div></div>
    <form method="post" class="set-form" enctype="multipart/form-data">
      <?= csrf() ?>
      <input type="hidden" name="action" value="save_settings">
      <input type="hidden" name="group" value="toko">
      <div class="logo-field">
        <div class="logo-box" id="logoBox"><?php if ($LOGO_TOKO): ?><img src="<?= e($LOGO_TOKO) ?>" alt="Logo toko"><?php else: ?><span>✦</span><?php endif ?></div>
        <div class="logo-ctrl">
          <label>Logo toko (JPG/PNG/WEBP, maks 2 MB)<input type="file" name="logo" id="inLogo" accept="image/jpeg,image/png,image/webp,image/gif"></label>
          <p class="set-note">Tampil di sidebar, halaman login, dan struk. Gunakan gambar persegi dengan latar putih/transparan.</p>
          <?php if ($LOGO_TOKO): ?><label class="check-line"><input type="checkbox" name="hapus_logo" value="1" id="inHapusLogo"> Hapus logo (kembali ke ikon bawaan)</label><?php endif ?>
        </div>
      </div>
      <label>Nama toko<input name="nama_toko" id="inNama" required maxlength="60" value="<?= e($NAMA_TOKO) ?>"></label>
      <label>Nomor telepon (opsional)<input name="telepon_toko" id="inTelp" maxlength="20" inputmode="tel" placeholder="0352-xxxxxx" value="<?= e($TELEPON_TOKO) ?>"></label>
      <label>Alamat<textarea name="alamat_toko" id="inAlamat" rows="2" maxlength="150"><?= e($ALAMAT_TOKO) ?></textarea></label>
      <label>Pesan penutup struk<input name="footer_struk" id="inFooter" maxlength="120" value="<?= e($FOOTER_STRUK) ?>"></label>
      <label>Lebar kertas struk
        <select name="lebar_struk" id="inLebar">
          <option value="80" <?= $LEBAR_STRUK === 80 ? 'selected' : '' ?>>80 mm (printer thermal standar)</option>
          <option value="58" <?= $LEBAR_STRUK === 58 ? 'selected' : '' ?>>58 mm (printer thermal kecil)</option>
        </select>
      </label>
      <label class="opt-line"><input type="checkbox" name="struk_barcode" value="1" id="inBarcode" <?= $STRUK_BARCODE ? 'checked' : '' ?>> Cetak barcode nomor invoice di struk</label>
      <p class="set-note">Barcode memudahkan mencari transaksi kembali lewat scanner.</p>
      <button class="btn primary">Simpan Pengaturan Toko</button>
    </form>
  </div>
  <div class="panel receipt-wrap">
    <div class="panel-head"><div><h3>Pratinjau struk</h3><p>Berubah saat Anda mengetik.</p></div></div>
    <div class="receipt-paper <?= $LEBAR_STRUK === 58 ? 'w58' : '' ?>" id="rcPaper">
      <img class="rc-logo" id="pvLogo" src="<?= e($LOGO_TOKO) ?>" alt="" <?= $LOGO_TOKO ? '' : 'style="display:none"' ?>>
      <div class="rc-name" id="pvNama"><?= e($NAMA_TOKO) ?></div>
      <div class="rc-c" id="pvAlamat"><?= e($ALAMAT_TOKO) ?></div>
      <div class="rc-c" id="pvTelp"><?= $TELEPON_TOKO !== '' ? 'Telp ' . e($TELEPON_TOKO) : '' ?></div>
      <hr>
      <div class="rc-row"><span>No</span><span>POS261123101500</span></div>
      <div class="rc-row"><span>Plg</span><span>Pelanggan Umum</span></div>
      <hr>
      <div class="rc-item">Air Mineral 600 ml</div>
      <div class="rc-row"><span>2 x 4.000</span><span>8.000</span></div>
      <div class="rc-item">Mi Instan</div>
      <div class="rc-row"><span>1 x 3.500</span><span>3.500</span></div>
      <hr>
      <div class="rc-row rc-total"><span>TOTAL</span><span>Rp 11.500</span></div>
      <div class="rc-row"><span>Bayar</span><span>Tunai</span></div>
      <div class="rc-barcode" id="pvBarcode" <?= $STRUK_BARCODE ? '' : 'style="display:none"' ?>><i></i><small>POS261123101500</small></div>
      <hr>
      <div class="rc-c" id="pvFooter"><?= e($FOOTER_STRUK) ?></div>
    </div>
  </div>
</div>
<script>
(function(){
  function bind(inp, out, fmt){
    var i = document.getElementById(inp), o = document.getElementById(out);
    if (!i || !o) return;
    function u(){ var v = i.value.trim(); o.textContent = fmt ? fmt(v) : v; o.style.display = o.textContent ? '' : 'none'; }
    i.addEventListener('input', u); u();
  }
  bind('inNama', 'pvNama', function(v){ return v || 'Nama toko'; });
  bind('inAlamat', 'pvAlamat');
  bind('inTelp', 'pvTelp', function(v){ return v ? 'Telp ' + v : ''; });
  bind('inFooter', 'pvFooter');
  var lf = document.getElementById('inLogo'), lb = document.getElementById('logoBox'), lp = document.getElementById('pvLogo'), lh = document.getElementById('inHapusLogo');
  if (lf) lf.addEventListener('change', function(){
    if (lf.files && lf.files[0]) {
      var u = URL.createObjectURL(lf.files[0]);
      lb.innerHTML = '<img src="' + u + '" alt="">'; lp.src = u; lp.style.display = '';
      if (lh) lh.checked = false;
    }
  });
  if (lh) lh.addEventListener('change', function(){ lp.style.display = lh.checked ? 'none' : ''; });
  var cb = document.getElementById('inBarcode'), pb = document.getElementById('pvBarcode');
  if (cb && pb) cb.addEventListener('change', function(){ pb.style.display = cb.checked ? '' : 'none'; });
  var w = document.getElementById('inLebar'), p = document.getElementById('rcPaper');
  if (w && p) w.addEventListener('change', function(){ p.classList.toggle('w58', w.value === '58'); });
})();
</script>

<?php elseif ($tab === 'transaksi'): ?>
<?php
$qrisFile = setting('qris_gambar');
$qrisAda  = $qrisFile !== '' && is_file(__DIR__ . '/' . $qrisFile);
?>
<form method="post" enctype="multipart/form-data" class="set-form-wide">
  <?= csrf() ?>
  <input type="hidden" name="action" value="save_settings">
  <input type="hidden" name="group" value="transaksi">
  <div class="settings-grid">
    <div class="panel">
      <div class="panel-head"><div><h3>Metode pembayaran kasir</h3><p>Hanya metode yang dicentang muncul saat checkout.</p></div></div>
      <div class="method-grid">
        <?php foreach ($METODE_POS as $k => $mm): ?>
        <label class="method-opt">
          <input type="checkbox" name="metode[]" value="<?= e($k) ?>" <?= in_array($k, $METODE_AKTIF, true) ? 'checked' : '' ?>>
          <span><b><?= e($mm['label']) ?></b><small><?= e($mm['hint']) ?></small></span>
        </label>
        <?php endforeach ?>
      </div>
      <div class="set-form" style="margin-top:18px">
        <label>Gambar QRIS toko (JPG/PNG/WEBP, maks 2 MB)<input type="file" name="qris" accept="image/jpeg,image/png,image/webp,image/gif"></label>
        <?php if ($qrisAda): ?>
        <div class="qris-now"><img src="<?= e($qrisFile) ?>" alt="QRIS saat ini"><label class="check-line"><input type="checkbox" name="hapus_qris" value="1"> Hapus gambar ini</label></div>
        <?php else: ?>
        <p class="set-note">Belum ada gambar yang diunggah. Selama kosong, halaman bayar memakai <code>assets/qris.png</code> atau QR contoh (demo).</p>
        <?php endif ?>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><div><h3>Rekening transfer bank untuk toko online</h3><p>Informasi ini ditampilkan kepada pelanggan saat memilih transfer bank.</p></div></div>
      <div class="set-form"><label>Nama bank<input name="nama_bank" maxlength="60" placeholder="Contoh: BRI / BCA / BNI" value="<?= e(setting('nama_bank')) ?>"></label><label>Nomor rekening<input name="nomor_rekening" maxlength="40" inputmode="numeric" value="<?= e(setting('nomor_rekening')) ?>"></label><label>Nama pemilik rekening<input name="nama_pemilik_rekening" maxlength="100" value="<?= e(setting('nama_pemilik_rekening')) ?>"></label><label>Catatan transfer (opsional)<textarea name="catatan_transfer" rows="2" maxlength="300" placeholder="Contoh: Cantumkan nomor invoice pada berita transfer."><?= e(setting('catatan_transfer')) ?></textarea></label><p class="set-note">Isi rekening ini agar pelanggan dapat memilih Transfer Bank ketika checkout toko online.</p></div>
      <div class="panel-head"><div><h3>Aturan transaksi</h3><p>Berlaku mulai transaksi berikutnya.</p></div></div>
      <div class="set-form">
        <label>Diskon member bawaan (%)<input type="number" name="member_diskon_default" min="0" max="50" step="0.5" value="<?= e((float)$MEMBER_DISKON_DEFAULT) ?>"></label>
        <p class="set-note">Dipakai sebagai nilai awal saat mendaftarkan member baru. Diskon member yang sudah ada tidak berubah.</p>
        <label>Batas waktu bayar QRIS</label><p class="set-note"><b>5 menit</b> — pesanan QRIS otomatis kedaluwarsa jika belum dibayar dalam 5 menit.</p>
        <p class="set-note">Pesanan QRIS yang belum dibayar lewat batas ini otomatis kedaluwarsa dan stoknya kembali.</p>
        <label>Peringatan stok menipis (pcs)<input type="number" name="stok_menipis" min="0" max="1000" value="<?= (int)$STOK_MENIPIS ?>"></label>
        <p class="set-note">Produk dengan stok sama atau di bawah angka ini diberi tanda di katalog dan dashboard. Isi 0 untuk mematikan.</p>
      </div>
    </div>
  </div>
  <button class="btn primary">Simpan Pengaturan Transaksi</button>
</form>

<?php elseif ($tab === 'staf'): ?>
<?php
$staf = $pdo->query('SELECT id_user,nama,username,role,kode_kartu,kode_rfid FROM tbl_user ORDER BY role, nama')->fetchAll();
$meId = (int)$_SESSION['auth']['id'];
?>
<div class="panel">
  <div class="panel-head"><div><h3>Tambah Akun Staf</h3><p>Admin mengelola semua menu; kasir hanya melayani transaksi.</p></div></div>
  <form method="post" class="product-form" autocomplete="off">
    <?= csrf() ?>
    <input type="hidden" name="action" value="user_add">
    <label>Nama<input name="nama" required maxlength="100" placeholder="Nama petugas"></label>
    <label>Username<input name="username" required minlength="3" maxlength="30" pattern="[a-zA-Z0-9_.\-]{3,30}" placeholder="huruf, angka, . - _"></label>
    <label>Role<select name="role"><option value="kasir">Kasir</option><option value="admin">Admin</option></select></label>
    <label>Password awal<input type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Minimal 8 karakter"></label>
    <button class="btn primary">＋ Buat Akun</button>
  </form>
</div>

<div class="panel table-panel">
  <div class="panel-head"><div><h3>Daftar Akun</h3><p><?= count($staf) ?> akun staf. Kartu login barcode/RFID diatur di <a class="text-link" href="barcode.php?tab=staf">Cetak Barcode ↗</a></p></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Password baru</th><th>Kartu</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($staf as $u):
          $uid  = (int)$u['id_user'];
          $self = $uid === $meId;
          $isAdm = $u['role'] === 'admin';
      ?>
        <tr>
          <td><b><?= e($u['username']) ?></b><?= $self ? ' <span class="badge st-menunggu">Anda</span>' : '' ?></td>
          <td><input class="cell-input" form="uf<?= $uid ?>" name="nama" required maxlength="100" value="<?= e($u['nama']) ?>"></td>
          <td>
            <select class="cell-input" form="uf<?= $uid ?>" name="role" <?= $self ? 'disabled title="Tidak bisa mengubah role akun sendiri"' : '' ?>>
              <option value="kasir" <?= !$isAdm ? 'selected' : '' ?>>Kasir</option>
              <option value="admin" <?= $isAdm ? 'selected' : '' ?>>Admin</option>
            </select>
          </td>
          <td><input class="cell-input" type="password" form="uf<?= $uid ?>" name="password_baru" minlength="8" autocomplete="new-password" placeholder="Kosong = tidak diganti"></td>
          <td><?= (!empty($u['kode_kartu']) || !empty($u['kode_rfid'])) ? '<span class="badge st-dibayar">Terdaftar</span>' : '<span style="color:#8999b8">-</span>' ?></td>
          <td class="actions">
            <form method="post" id="uf<?= $uid ?>">
              <?= csrf() ?>
              <input type="hidden" name="action" value="user_update">
              <input type="hidden" name="id_user" value="<?= $uid ?>">
              <?php if ($self): ?><input type="hidden" name="role" value="admin"><?php endif ?>
              <button class="btn tiny">Simpan</button>
            </form>
            <?php if (!$self): ?>
            <form method="post" data-confirm="Akun <?= e($u['username']) ?> akan dihapus. Riwayat transaksinya tetap tersimpan." data-title="Hapus akun?" data-ok="Ya, hapus" data-variant="danger" data-icon="🗑">
              <?= csrf() ?>
              <input type="hidden" name="action" value="user_delete">
              <input type="hidden" name="id_user" value="<?= $uid ?>">
              <button class="btn tiny danger-btn">Hapus</button>
            </form>
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif ?>

<?php elseif ($page === 'pelanggan'): ?>
<div class="panel form-panel">
  <div class="panel-head"><div><h3>Tambah Pelanggan</h3><p>Masukkan informasi pelanggan baru</p></div></div>
  <form method="post" class="inline-form">
    <input type="hidden" name="action" value="save_customer">
    <input name="nama_pelanggan" placeholder="Nama pelanggan" required>
    <input name="kota" placeholder="Kota">
    <button class="btn primary">＋ Tambah Pelanggan</button>
  </form>
</div>
<div class="panel table-panel">
  <div class="panel-head"><div><h3>Daftar Pelanggan</h3><p><?= $stats['customers'] ?> pelanggan tersimpan</p></div></div>
  <?php
  $list = $pdo->prepare("SELECT * FROM tbl_pelanggan WHERE nama_pelanggan LIKE ? OR kota LIKE ? ORDER BY id_pelanggan DESC");
  $list->execute(["%$search%", "%$search%"]);
  $data = $list->fetchAll();
  ?>
  <table>
    <thead><tr><th>ID</th><th>Nama Pelanggan</th><th>Kota</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($data as $r): ?>
      <tr>
        <td>#<?= $r['id_pelanggan'] ?></td>
        <td><b><?= e($r['nama_pelanggan']) ?></b></td>
        <td><?= e($r['kota']) ?></td>
        <td class="actions">
          <button class="btn tiny" onclick='editCustomer(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Edit</button>
          <form method="post" data-confirm="Yakin menghapus pelanggan ini?" data-title="Hapus pelanggan?" data-ok="Ya, hapus" data-variant="danger" data-icon="🗑">
            <input type="hidden" name="action" value="delete_customer">
            <input type="hidden" name="id_pelanggan" value="<?= $r['id_pelanggan'] ?>">
            <button class="btn tiny danger-btn">Hapus</button>
          </form>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$data): ?><tr><td colspan="4" class="empty">Belum ada data pelanggan.</td></tr><?php endif ?>
    </tbody>
  </table>
</div>
<div class="modal" id="editModal">
  <form method="post" class="modal-card">
    <button type="button" class="modal-close" onclick="closeEdit()">×</button>
    <h3>Edit Pelanggan</h3>
    <input type="hidden" name="action" value="save_customer">
    <input type="hidden" name="id_pelanggan" id="editId">
    <label>Nama</label><input name="nama_pelanggan" id="editNama" required>
    <label>Kota</label><input name="kota" id="editKota">
    <button class="btn primary">Simpan Perubahan</button>
  </form>
</div>

<?php elseif ($page === 'transaksi'): ?>
<?php if ($role === 'admin'): ?>
<div class="panel form-panel">
  <div class="panel-head"><div><h3>Tambah Transaksi Manual</h3><p>Dicatat langsung lunas (untuk pengujian database). Belanja dari keranjang harus dibayar dulu.</p></div></div>
  <form method="post" class="inline-form">
    <input type="hidden" name="action" value="save_transaction">
    <select name="id_pelanggan" required>
      <option value="">Pilih pelanggan</option>
      <?php foreach ($customers as $c): ?>
      <option value="<?= $c['id_pelanggan'] ?>"><?= e($c['nama_pelanggan']) ?></option>
      <?php endforeach ?>
    </select>
    <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>">
    <input type="number" name="total_belanja" min="0" step="0.01" placeholder="Total belanja" required>
    <button class="btn primary">＋ Simpan Transaksi</button>
  </form>
</div>
<?php endif ?>
<div class="panel table-panel">
  <div class="panel-head"><div><h3>Riwayat Transaksi</h3><p>Transaksi dan pelanggan digabungkan dengan JOIN</p></div></div>
  <?php
  $fs = $_GET['status'] ?? '';
  $kasirFilter = $role === 'admin' ? (int)($_GET['kasir'] ?? 0) : 0;
  $cariTransaksi = trim((string)($_GET['cari_transaksi'] ?? ''));
  $kasirOptions = $role === 'admin'
      ? rows($pdo, "SELECT id_user,nama FROM tbl_user WHERE role='kasir' ORDER BY nama")
      : [];
  $sql = "SELECT t.*,p.nama_pelanggan,p.kota,u.nama AS nama_kasir
          FROM tbl_transaksi t
          LEFT JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan
          LEFT JOIN tbl_user u ON u.id_user=t.id_user
          WHERE (COALESCE(p.nama_pelanggan,'') LIKE ? OR COALESCE(t.kode_invoice,'') LIKE ?)";
  $args = ["%$cariTransaksi%", "%$cariTransaksi%"];
  if ($role !== 'admin') {
      $sql .= ' AND t.id_user = ?';
      $args[] = (int)$_SESSION['auth']['id'];
  } elseif ($kasirFilter > 0) {
      $sql .= ' AND t.id_user = ?';
      $args[] = $kasirFilter;
  }
  if (in_array($fs, ['menunggu','dibayar','dibatalkan','kedaluwarsa'], true)) {
      $sql .= ' AND t.status_pembayaran=?';
      $args[] = $fs;
  }
  $st = $pdo->prepare($sql . ' ORDER BY t.id_transaksi DESC');
  $st->execute($args);
  $trx = $st->fetchAll();
  $queryBase = ['page'=>'transaksi'];
  if ($kasirFilter > 0 && $role === 'admin') $queryBase['kasir'] = $kasirFilter;
  if ($cariTransaksi !== '') $queryBase['cari_transaksi'] = $cariTransaksi;
  ?>
  <form method="get" class="inline-form" style="margin:0 0 14px">
    <input type="hidden" name="page" value="transaksi">
    <input name="cari_transaksi" value="<?= e($cariTransaksi) ?>" placeholder="Cari invoice atau nama pelanggan">
    <?php if ($role === 'admin'): ?>
    <select name="kasir">
      <option value="0">Semua Kasir</option>
      <?php foreach ($kasirOptions as $k): ?>
      <option value="<?= (int)$k['id_user'] ?>" <?= $kasirFilter===(int)$k['id_user']?'selected':'' ?>><?= e($k['nama']) ?></option>
      <?php endforeach ?>
    </select>
    <?php endif ?>
    <?php if ($fs !== ''): ?><input type="hidden" name="status" value="<?= e($fs) ?>"><?php endif ?>
    <button class="btn primary">Terapkan</button>
    <a class="btn" href="?page=transaksi">Reset</a>
  </form>
  <div class="category-chips" style="margin:0 0 14px">
    <?php $statusBase = $queryBase; ?>
    <a class="<?= $fs===''?'selected':'' ?>" href="?<?= e(http_build_query($statusBase)) ?>">Semua</a>
    <?php foreach (['menunggu'=>'Menunggu bayar','dibayar'=>'Lunas','dibatalkan'=>'Dibatalkan','kedaluwarsa'=>'Kedaluwarsa'] as $k => $l): ?>
    <a class="<?= $fs===$k?'selected':'' ?>" href="?<?= e(http_build_query($statusBase + ['status'=>$k])) ?>"><?= $l ?></a>
    <?php endforeach ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Invoice</th><th>Tanggal</th><th>Pelanggan</th><th>Nama Kasir</th><th>Metode</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($trx as $r): ?>
        <tr>
          <td>#<?= $r['id_transaksi'] ?></td>
          <td><?= e($r['kode_invoice'] ?: '-') ?></td>
          <td><?= e($r['tanggal']) ?></td>
          <td><?= e($r['nama_pelanggan']) ?> <small style="color:#8999b8"><?= e($r['kota']) ?></small></td>
          <td><?= e($r['nama_kasir'] ?: ($r['metode_pembayaran']==='qris' && str_starts_with((string)$r['kode_invoice'],'WEB') ? 'Online' : '-')) ?></td>
          <td><?= e($PAYMENT_METHODS[$r['metode_pembayaran'] ?? '']['label'] ?? 'Manual') ?></td>
          <td><?= rupiah($r['total_belanja']) ?></td>
          <td><?= badge((string)$r['status_pembayaran']) ?></td>
          <td><a class="btn tiny" href="?page=bayar&id=<?= $r['id_transaksi'] ?>"><?= $r['status_pembayaran']==='menunggu' ? 'Bayar' : 'Detail' ?></a></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$trx): ?><tr><td colspan="9" class="empty">Tidak ada transaksi.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($page === 'laporan'): ?>
<?php include __DIR__ . '/laporan_view.php'; ?>

<?php elseif ($page === 'view'): ?>
<div class="info-banner"><b>VIEW Database</b><span>Data berikut diambil langsung dari view yang sudah tersedia pada db_tokoku2.</span></div>

<div class="panel table-panel">
  <div class="panel-head"><div><h3>view_pelanggan</h3><p>Daftar pelanggan melalui VIEW</p></div></div>
  <?php renderTable(rows($pdo,'SELECT * FROM view_pelanggan'),['id_pelanggan'=>'ID','nama_pelanggan'=>'Nama','kota'=>'Kota']); ?>
</div>

<div class="panel table-panel">
  <div class="panel-head"><div><h3>view_total_belanja</h3><p>Rekap transaksi dan total belanja per pelanggan</p></div></div>
  <?php renderTable(rows($pdo,'SELECT * FROM view_total_belanja'),['id_pelanggan'=>'ID','nama_pelanggan'=>'Pelanggan','total_transaksi'=>'Jumlah Transaksi','total_belanja'=>'Total Belanja'],'money'); ?>
</div>

<div class="panel table-panel">
  <div class="panel-head"><div><h3>view_transaksi_pelanggan</h3><p>Detail transaksi melalui VIEW</p></div></div>
  <?php renderTable(rows($pdo,'SELECT * FROM view_transaksi_pelanggan ORDER BY tanggal DESC'),['id_transaksi'=>'ID','tanggal'=>'Tanggal','nama_pelanggan'=>'Pelanggan','kota'=>'Kota','total_belanja'=>'Total'],'money'); ?>
</div>

<?php elseif ($page === 'join'): ?>
<div class="info-banner"><b>JOIN: tbl_transaksi + tbl_pelanggan</b><span>Menggabungkan data transaksi dengan informasi pemilik transaksi.</span></div>
<div class="panel table-panel">
  <?php
  $data = rows($pdo, 'SELECT t.id_transaksi,t.tanggal,p.nama_pelanggan,p.kota,t.total_belanja FROM tbl_transaksi t INNER JOIN tbl_pelanggan p ON t.id_pelanggan=p.id_pelanggan ORDER BY t.id_transaksi DESC');
  renderTable($data, ['id_transaksi'=>'ID Transaksi','tanggal'=>'Tanggal','nama_pelanggan'=>'Nama Pelanggan','kota'=>'Kota','total_belanja'=>'Total'], 'money');
  ?>
</div>

<?php elseif ($page === 'subquery'): ?>
<div class="info-banner"><b>Analisis menggunakan Subquery</b><span>Menampilkan pelanggan yang total belanjanya di atas rata-rata seluruh transaksi per pelanggan.</span></div>
<div class="panel table-panel">
  <?php
  $data = rows($pdo, "SELECT p.id_pelanggan,p.nama_pelanggan,p.kota,(SELECT COALESCE(SUM(t.total_belanja),0) FROM tbl_transaksi t WHERE t.id_pelanggan=p.id_pelanggan) AS total_belanja FROM tbl_pelanggan p WHERE (SELECT COALESCE(SUM(t.total_belanja),0) FROM tbl_transaksi t WHERE t.id_pelanggan=p.id_pelanggan) > (SELECT AVG(x.total_per_pelanggan) FROM (SELECT SUM(total_belanja) total_per_pelanggan FROM tbl_transaksi GROUP BY id_pelanggan) x) ORDER BY total_belanja DESC");
  renderTable($data, ['id_pelanggan'=>'ID','nama_pelanggan'=>'Pelanggan','kota'=>'Kota','total_belanja'=>'Total Belanja'], 'money');
  ?>
</div>

<?php elseif ($page === 'procedure'): ?>
<div class="info-banner"><b>Stored Procedure</b><span>Pilih prosedur yang ingin dijalankan. Semua nama sesuai database db_tokoku.</span></div>
<div class="panel form-panel">
  <form method="post" class="procedure-form">
    <input type="hidden" name="action" value="call_procedure">
    <label>Stored Procedure</label>
    <select name="procedure" id="procedureSelect" onchange="procedureFields()">
      <option>TampilPelanggan</option>
      <option>CariPelanggan</option>
      <option>HitungPelanggan</option>
      <option>LaporanTransaksi</option>
      <option>TambahPelanggan</option>
    </select>
    <div id="procId" class="field-hidden"><label>ID Pelanggan</label><input type="number" name="param_id" value="1"></div>
    <div id="procAdd" class="field-hidden">
      <label>Nama Pelanggan</label><input name="param_nama" placeholder="Nama">
      <label>Kota</label><input name="param_kota" placeholder="Kota">
    </div>
    <button class="btn primary">▶ Jalankan Procedure</button>
  </form>
</div>
<?php if (isset($_SESSION['proc_result'])):
    $res = $_SESSION['proc_result'];
    unset($_SESSION['proc_result']);
?>
<div class="panel table-panel">
  <div class="panel-head"><h3>Hasil Stored Procedure</h3></div>
  <?php renderTable($res); ?>
</div>
<?php endif ?>
<div class="panel code-panel">
  <b>Daftar prosedur tersedia</b>
  <code> TampilPelanggan() · CariPelanggan(IN p_id) · HitungPelanggan(OUT jumlah) · LaporanTransaksi(IN p_id_pelanggan) · TambahPelanggan(IN p_nama, IN p_kota)</code>
</div>

<?php elseif ($page === 'function'): ?>
<div class="info-banner"><b>Pengujian Function</b><span>Hasil dihitung langsung oleh function yang tersimpan di MySQL.</span></div>
<div class="panel form-panel">
  <form method="get" class="function-grid">
    <input type="hidden" name="page" value="function">
    <div><label>Harga / Total</label><input type="number" step="0.01" name="harga" value="<?= e($_GET['harga'] ?? 100000) ?>"></div>
    <div><label>Diskon (%)</label><input type="number" step="0.01" name="diskon" value="<?= e($_GET['diskon'] ?? 10) ?>"></div>
    <div><label>Jumlah</label><input type="number" name="jumlah" value="<?= e($_GET['jumlah'] ?? 2) ?>"></div>
    <div><label>Nilai</label><input type="number" name="nilai" value="<?= e($_GET['nilai'] ?? 85) ?>"></div>
    <button class="btn primary">Hitung Function</button>
  </form>
</div>
<?php if (isset($_GET['harga'])):
    $h = (float)$_GET['harga'];
    $d = (float)$_GET['diskon'];
    $j = (int)$_GET['jumlah'];
    $n = (int)$_GET['nilai'];
    $st = $pdo->prepare('SELECT HitungDiskon(?,?) diskon, HitungPajak(?) pajak, HitungTotal(?,?) total, KategoriBelanja(?) kategori, KonversiNilai(?) konversi');
    $st->execute([$h, $d, $h, $h, $j, $h, $n]);
    $r = $st->fetch();
?>
<div class="result-grid">
  <?php foreach ($r as $k => $v): ?>
  <div class="result-card"><small><?= e(ucwords(str_replace('_', ' ', $k))) ?></small><b><?= e(is_numeric($v) ? rupiah($v) : $v) ?></b></div>
  <?php endforeach ?>
</div>
<?php endif ?>
<div class="panel code-panel"><b>Function tersedia</b><code>HitungDiskon · HitungPajak · HitungTotal · KategoriBelanja · KonversiNilai</code></div>

<?php elseif ($page === 'trigger'): ?>
<div class="info-banner"><b>Pengujian Trigger Otomatis</b><span>Tambah atau ubah pelanggan untuk memicu trigger. Hasilnya dicatat ke tabel log.</span></div>
<div class="panel form-panel">
  <h3>Trigger yang tersedia</h3>
  <div class="trigger-list">
    <div><b>after_insert_pelanggan</b><small>Setelah pelanggan ditambahkan, mencatat aktivitas ke log_pelanggan.</small></div>
    <div><b>after_update_pelanggan</b><small>Mencatat nama lama dan nama baru ke log_perubahan_pelanggan.</small></div>
    <div><b>before_insert_transaksi</b><small>Menolak total belanja bernilai negatif.</small></div>
  </div>
  <a class="btn primary" href="?page=pelanggan">＋ Uji tambah / edit pelanggan</a>
</div>
<div class="panel table-panel">
  <div class="panel-head"><h3>Log Insert Pelanggan</h3></div>
  <?php renderTable(rows($pdo, 'SELECT * FROM log_pelanggan ORDER BY waktu DESC'), ['id_log'=>'ID','aktivitas'=>'Aktivitas','id_pelanggan'=>'ID Pelanggan','waktu'=>'Waktu']); ?>
</div>
<div class="panel table-panel">
  <div class="panel-head"><h3>Log Perubahan Pelanggan</h3></div>
  <?php renderTable(rows($pdo, 'SELECT * FROM log_perubahan_pelanggan ORDER BY waktu DESC'), ['id_log'=>'ID','id_pelanggan'=>'ID Pelanggan','nama_lama'=>'Nama Lama','nama_baru'=>'Nama Baru','waktu'=>'Waktu']); ?>
</div>

<?php elseif ($page === 'transaction'): ?>
<div class="info-banner"><b>Uji transaksi database</b><span>Gunakan data uji Rp12.345 untuk melihat perbedaan COMMIT dan ROLLBACK. Pastikan tabel menggunakan InnoDB.</span></div>
<div class="transaction-cards">
  <div class="panel">
    <div class="big-symbol green-text">✓</div>
    <h3>COMMIT</h3>
    <p>Menyimpan perubahan transaksi secara permanen.</p>
    <form method="post">
      <input type="hidden" name="action" value="test_transaction">
      <input type="hidden" name="mode" value="commit">
      <button class="btn primary">Jalankan COMMIT</button>
    </form>
  </div>
  <div class="panel">
    <div class="big-symbol red-text">↶</div>
    <h3>ROLLBACK</h3>
    <p>Membatalkan perubahan sehingga data uji tidak tersimpan.</p>
    <form method="post">
      <input type="hidden" name="action" value="test_transaction">
      <input type="hidden" name="mode" value="rollback">
      <button class="btn danger-btn">Jalankan ROLLBACK</button>
    </form>
  </div>
</div>
<div class="panel table-panel">
  <div class="panel-head"><h3>Transaksi terbaru</h3></div>
  <?php renderTable(rows($pdo, 'SELECT t.id_transaksi,t.tanggal,p.nama_pelanggan,t.total_belanja FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan ORDER BY t.id_transaksi DESC LIMIT 8'), ['id_transaksi'=>'ID','tanggal'=>'Tanggal','nama_pelanggan'=>'Pelanggan','total_belanja'=>'Total'], 'money'); ?>
</div>

<?php elseif ($page === 'explain'): ?>
<div class="info-banner"><b>EXPLAIN Query</b><span>Melihat cara MySQL menjalankan query, termasuk type, key, dan perkiraan jumlah baris.</span></div>
<div class="panel code-panel">
  <b>Query yang dianalisis</b>
  <code>SELECT t.id_transaksi, p.nama_pelanggan, t.total_belanja FROM tbl_transaksi t JOIN tbl_pelanggan p ON t.id_pelanggan=p.id_pelanggan WHERE p.kota='Ponorogo';</code>
</div>
<div class="panel table-panel">
  <?php
  $data = rows($pdo, "EXPLAIN SELECT t.id_transaksi,p.nama_pelanggan,t.total_belanja FROM tbl_transaksi t JOIN tbl_pelanggan p ON t.id_pelanggan=p.id_pelanggan WHERE p.kota='Ponorogo'");
  renderTable($data);
  ?>
</div>
<div class="hint">Perhatikan kolom <b>type</b>, <b>possible_keys</b>, <b>key</b>, dan <b>rows</b>. Nilainya bergantung pada jumlah data dan index di database.</div>
<?php endif; ?>

<footer>© <?= date('Y') ?> <?= e($NAMA_TOKO) ?> Admin Panel <span>PHP Native · MySQL · Dibuat untuk Pengujian Basis Data</span></footer>
</section>
</main>
</div>

<div class="cf-overlay" id="cfOverlay" aria-hidden="true">
  <div class="cf-card" role="dialog" aria-modal="true" aria-labelledby="cfTitle">
    <div class="cf-icon" id="cfIcon">?</div>
    <h3 id="cfTitle">Konfirmasi</h3>
    <p id="cfText"></p>
    <div class="cf-actions">
      <button type="button" class="cf-btn cf-cancel" id="cfCancel">Batal</button>
      <button type="button" class="cf-btn cf-ok" id="cfOk">Ya</button>
    </div>
  </div>
</div>

<script>
(function(){
  var ov = document.getElementById('cfOverlay'), card = ov.querySelector('.cf-card'), pending = null;
  function open(f) {
    pending = f;
    var v = f.dataset.variant || 'success';
    card.className = 'cf-card cf-' + v;
    document.getElementById('cfIcon').textContent = f.dataset.icon || '?';
    document.getElementById('cfTitle').textContent = f.dataset.title || 'Konfirmasi';
    document.getElementById('cfText').textContent = f.dataset.confirm || '';
    document.getElementById('cfOk').textContent = f.dataset.ok || 'Ya';
    ov.classList.remove('closing');
    ov.classList.add('show');
    ov.setAttribute('aria-hidden', 'false');
    document.body.classList.add('cf-lock');
    setTimeout(function(){ document.getElementById('cfOk').focus(); }, 120);
  }
  function close() {
    ov.classList.add('closing');
    ov.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('cf-lock');
    setTimeout(function(){ ov.classList.remove('show','closing'); pending = null; }, 200);
  }
  document.addEventListener('submit', function(e) {
    var f = e.target;
    if (f.dataset && f.dataset.confirm && !f.dataset.confirmed) {
      e.preventDefault();
      open(f);
    }
  });
  document.getElementById('cfCancel').addEventListener('click', close);
  ov.addEventListener('mousedown', function(e){ if (e.target === ov) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && ov.classList.contains('show')) close(); });
  document.getElementById('cfOk').addEventListener('click', function(){
    if (!pending) return;
    var f = pending;
    f.dataset.confirmed = '1';
    var b = document.getElementById('cfOk');
    b.classList.add('loading');
    b.textContent = 'Memproses…';
    b.disabled = true;
    f.submit();
  });
})();

(function(){
  var t = document.getElementById('toast');
  if (!t) return;
  window.closeToast = function() {
    t.classList.add('out');
    setTimeout(function(){ t.remove(); }, 350);
  };
  setTimeout(window.closeToast, 4500);
})();
</script>

<?php if ($page === 'bayar' && !empty($order) && ($order['status_pembayaran'] ?? '') === 'menunggu' && ($m['type'] ?? '') === 'qris' && !is_file(__DIR__ . '/' . $m['image'])): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<?php endif ?>

<script>
function editCustomer(row) {
  document.getElementById('editId').value = row.id_pelanggan;
  document.getElementById('editNama').value = row.nama_pelanggan || '';
  document.getElementById('editKota').value = row.kota || '';
  document.getElementById('editModal').classList.add('show');
}
(function(){
  var fi = document.getElementById('fotoInput');
  if (fi) fi.addEventListener('change', function(){
    var pv = document.getElementById('fotoPreview');
    if (fi.files && fi.files[0]) {
      var u = URL.createObjectURL(fi.files[0]);
      pv.innerHTML = '<img src="'+u+'" alt="">';
    } else {
      pv.innerHTML = '<span>Pratinjau foto</span>';
    }
  });
})();
(function(){
  var c = document.getElementById('countdown');
  if (!c) return;
  var s = parseInt(c.dataset.sisa, 10) || 0;
  function t() {
    if (s <= 0) {
      c.textContent = 'Waktu habis';
      setTimeout(function(){ location.reload(); }, 1500);
      return;
    }
    var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
    c.textContent = [h, m, x].map(function(n){ return String(n).padStart(2, '0'); }).join(':');
    s--;
    setTimeout(t, 1000);
  }
  t();
})();
<?php if ($page === 'bayar' && !empty($order) && ($order['status_pembayaran'] ?? '') === 'menunggu' && ($m['type'] ?? '') === 'qris' && !is_file(__DIR__ . '/' . $m['image'])): ?>
if (window.QRCode && document.getElementById('qr')) {
  new QRCode(document.getElementById('qr'), {
    text: <?= json_encode('TOKOKU-DEMO|' . $order['kode_invoice'] . '|' . (int)$order['total_belanja']) ?>,
    width: 200,
    height: 200
  });
}
<?php endif ?>
(function(){
  var f = document.getElementById('cartForm');
  if (!f) return;
  var t;
  function kirim(){ clearTimeout(t); t = setTimeout(function(){ f.submit(); }, 450); }
  f.addEventListener('click', function(ev){
    var b = ev.target.closest('.qty-step button');
    if (!b) return;
    var i = b.parentNode.querySelector('input'), max = parseInt(i.max, 10), v = (parseInt(i.value, 10) || 0) + parseInt(b.dataset.d, 10);
    i.value = Math.max(0, isNaN(max) ? v : Math.min(v, max));
    kirim();
  });
  f.addEventListener('change', function(ev){ if (ev.target.matches('.qty-step input')) kirim(); });
})();
function closeEdit() {
  document.getElementById('editModal').classList.remove('show');
}
function procedureFields() {
  let p = document.getElementById('procedureSelect')?.value;
  document.getElementById('procId')?.classList.toggle('field-hidden', !['CariPelanggan','LaporanTransaksi'].includes(p));
  document.getElementById('procAdd')?.classList.toggle('field-hidden', p !== 'TambahPelanggan');
}
<?php if ($page === 'procedure'): ?>procedureFields();<?php endif ?>
<?php if ($page === 'dashboard'): ?>
<?php
if ($role === 'admin') {
    $chart = rows($pdo, "SELECT DATE_FORMAT(tanggal,'%d %b') label,COUNT(*) jumlah FROM tbl_transaksi WHERE status_pembayaran='dibayar' GROUP BY DATE(tanggal) ORDER BY DATE(tanggal)");
} else {
    $chartStmt = $pdo->prepare("SELECT DATE_FORMAT(tanggal,'%d %b') label,COUNT(*) jumlah FROM tbl_transaksi WHERE status_pembayaran='dibayar' AND id_user=? GROUP BY DATE(tanggal) ORDER BY DATE(tanggal)");
    $chartStmt->execute([(int)($_SESSION['auth']['id'] ?? 0)]);
    $chart = $chartStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
new Chart(document.getElementById('salesChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($chart, 'label')) ?>,
    datasets: [{
      label: 'Transaksi',
      data: <?= json_encode(array_map('intval', array_column($chart, 'jumlah'))) ?>,
      borderColor: '#8b5cf6',
      backgroundColor: 'rgba(139,92,246,.14)',
      fill: true,
      tension: .42,
      pointRadius: 4,
      pointBackgroundColor: '#a78bfa'
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { color: 'rgba(148,163,184,.08)' }, ticks: { color: '#9caac5' } },
      y: { beginAtZero: true, grid: { color: 'rgba(148,163,184,.08)' }, ticks: { color: '#9caac5', precision: 0 } }
    }
  }
});
<?php endif ?>
</script>
</body>
</html>
<?php
function renderTable(array $data, array $labels = [], string $moneyKey = ''): void {
    if (!$labels && isset($data[0])) {
        $labels = array_combine(array_keys($data[0]), array_keys($data[0]));
    }
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($labels as $key => $label) echo '<th>' . e($label) . '</th>';
    echo '</tr></thead><tbody>';
    if (!$data) {
        echo '<tr><td colspan="' . max(1, count($labels)) . '" class="empty">Tidak ada data untuk ditampilkan.</td></tr>';
    }
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($labels as $key => $label) {
            $v = $row[$key] ?? '';
            if ($key === $moneyKey || (str_contains(strtolower($key), 'total') && !str_contains(strtolower($key), 'transaksi') && is_numeric($v))) {
                $v = rupiah($v);
            }
            echo '<td>' . e($v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
?>