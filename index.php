<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

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
    ];
    $todo = [];
    foreach ($add as $c => $ddl) {
        if (!in_array($c, $cols, true)) $todo[] = $ddl;
    }
    if ($todo) $pdo->exec('ALTER TABLE tbl_produk ' . implode(', ', $todo));

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
})($pdo);

// Akun staf
$pdo->exec("ALTER TABLE tbl_user MODIFY role ENUM('admin','kasir','user') NOT NULL DEFAULT 'kasir'");
$pdo->exec("UPDATE tbl_user SET role='kasir' WHERE role='user'");
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

function badge(string $s): string {
    $label = [
        'menunggu'   => 'Menunggu pembayaran',
        'dibayar'    => 'Lunas',
        'dibatalkan' => 'Dibatalkan',
        'kedaluwarsa'=> 'Kedaluwarsa'
    ][$s] ?? $s;
    return '<span class="badge st-' . e($s) . '">' . e($label) . '</span>';
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
    'dashboard','produk','keranjang','pelanggan','transaksi','laporan','bayar',
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
        header('Location: index.php');
        exit;
    }
    $_SESSION['login_error'] = 'Username atau password salah.';
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
<title>Login TokoKu</title>
<link rel="stylesheet" href="assets/style.css">
<style>
body.login-screen{min-height:100vh;display:grid;place-items:center;background:linear-gradient(135deg,#eef4ff,#f8fafc);font-family:Arial,sans-serif}
.login-box{width:min(410px,calc(100% - 32px));background:#fff;padding:34px;border-radius:22px;box-shadow:0 20px 60px #1e3a8a18}
.login-box h1{margin:12px 0}
.login-box p{color:#64748b}
.login-box label{display:block;margin:17px 0 7px;font-weight:bold}
.login-box input{box-sizing:border-box;width:100%;padding:13px;border:1px solid #dbe3ef;border-radius:10px}
.login-box button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-weight:bold}
.login-error{background:#fee2e2;color:#991b1b;padding:10px;border-radius:9px}
.login-kicker{color:#2563eb;font-weight:bold;letter-spacing:2px}
</style>
</head>
<body class="login-screen">
<form method="post" class="login-box">
  <div class="login-kicker">✦ TOKOKU · TOKO OFFLINE</div>
  <h1>Login staf</h1>
  <p>Masuk sebagai admin atau petugas kasir.</p>
  <?php if ($err): ?><div class="login-error"><?= e($err) ?></div><?php endif ?>
  <input type="hidden" name="action" value="login">
  <label>Username</label>
  <input name="username" required autofocus>
  <label>Password</label>
  <input type="password" name="password" required>
  <button>Masuk ke TokoKu</button>
</form>
</body>
</html><?php
    exit;
}

$role = $_SESSION['auth']['role'] ?? 'kasir';
if ($role !== 'admin' && in_array($page, ['pelanggan','laporan','view','join','subquery','procedure','function','trigger','transaction','explain'], true)) {
    $page = 'dashboard';
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (in_array($action, ['save_product','update_product_image','delete_product','save_customer','delete_customer','call_procedure','test_transaction','pay_cancel'], true) && $role !== 'admin') {
            throw new RuntimeException('Aksi ini khusus admin.');
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
            $id      = (int)$_POST['id_pelanggan'];
            $tanggal = $_POST['tanggal'] ?: date('Y-m-d');
            $total   = (float)$_POST['total_belanja'];
            if ($total < 0) throw new RuntimeException('Total belanja tidak boleh negatif.');
            $st = $pdo->prepare('INSERT INTO tbl_transaksi (tanggal,id_pelanggan,total_belanja) VALUES (?,?,?)');
            $st->execute([$tanggal, $id, $total]);
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
            $foto = uploadImage('foto', 'produk');
            if ($id > 0) {
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
            $metode = $_POST['metode'] ?? 'tunai';
            if (!in_array($metode, ['tunai', 'qris', 'debit'], true)) {
                throw new RuntimeException('Metode pembayaran tidak valid.');
            }
            $nama = trim($_POST['nama_baru'] ?? '');
            if ($nama === '') $nama = 'Pelanggan Umum';
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO tbl_pelanggan(nama_pelanggan,kota) VALUES(?,?)')
                ->execute([$nama, trim($_POST['kota_baru'] ?? '')]);
            $idp = (int)$pdo->lastInsertId();
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
            $isQris   = ($metode === 'qris');
            $status   = $isQris ? 'menunggu' : 'dibayar';
            $invoice  = 'POS' . date('ymdHis');
            $deadline = $isQris ? date('Y-m-d H:i:s', time() + ((int)$PAYMENT_TIMEOUT_HOURS * 3600)) : null;
            $paidAt   = $isQris ? null : date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO tbl_transaksi(tanggal,id_pelanggan,total_belanja,id_user,status_pembayaran,metode_pembayaran,kode_invoice,batas_bayar,dibayar_pada) VALUES(?,?,?,?,?,?,?,?,?)")
                ->execute([date('Y-m-d'), $idp, $total, (int)$_SESSION['auth']['id'], $status, $metode, $invoice, $deadline, $paidAt]);
            $idT = (int)$pdo->lastInsertId();
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
            unset($_SESSION['cart']);
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
            $st = $pdo->prepare('SELECT status_pembayaran FROM tbl_transaksi WHERE id_transaksi=? FOR UPDATE');
            $st->execute([$idT]);
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
            $u = $pdo->prepare("UPDATE tbl_transaksi SET status_pembayaran='dibatalkan' WHERE id_transaksi=? AND status_pembayaran='menunggu'");
            $u->execute([$idT]);
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
            $_SESSION['cart'][$id] = min((int)$p['stok'], (int)($_SESSION['cart'][$id] ?? 0) + 1);
            flash('Produk ditambahkan ke keranjang.');
            go('produk');
        }

        if ($action === 'update_cart') {
            foreach (($_POST['qty'] ?? []) as $id => $qty) {
                $id  = (int)$id;
                $qty = max(0, (int)$qty);
                $st  = $pdo->prepare('SELECT stok FROM tbl_produk WHERE id_produk=?');
                $st->execute([$id]);
                $stock = (int)$st->fetchColumn();
                if ($qty === 0 || $stock === 0) unset($_SESSION['cart'][$id]);
                else $_SESSION['cart'][$id] = min($qty, $stock);
            }
            flash('Keranjang berhasil diperbarui.');
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
            $st = $pdo->prepare('INSERT INTO tbl_transaksi (tanggal,id_pelanggan,total_belanja) VALUES (?,?,?)');
            $st->execute([date('Y-m-d'), $customer, 12345]);
            if ($mode === 'commit') {
                $pdo->commit();
                flash('COMMIT berhasil. Data uji tersimpan di tabel transaksi.');
            } else {
                $pdo->rollBack();
                flash('ROLLBACK berhasil. Data uji dibatalkan dan tidak tersimpan.');
            }
            go('transaction');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($ex->getMessage(), 'error');
        go($page, $page === 'bayar' ? '&id=' . (int)($_GET['id'] ?? 0) : '');
    }
}

$flash     = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$search    = trim($_GET['q'] ?? '');
$customers = $pdo->query('SELECT * FROM tbl_pelanggan ORDER BY id_pelanggan DESC')->fetchAll();
$stats     = [
    'customers'    => (int)$pdo->query('SELECT COUNT(*) FROM tbl_pelanggan')->fetchColumn(),
    'transactions' => (int)$pdo->query('SELECT COUNT(*) FROM tbl_transaksi')->fetchColumn(),
    'revenue'      => (float)$pdo->query("SELECT COALESCE(SUM(total_belanja),0) FROM tbl_transaksi WHERE status_pembayaran='dibayar'")->fetchColumn(),
    'cities'       => (int)$pdo->query('SELECT COUNT(DISTINCT kota) FROM tbl_pelanggan')->fetchColumn(),
];

$titleMap = [
    'dashboard'  => 'Dashboard',
    'produk'     => 'Katalog Produk',
    'keranjang'  => 'Keranjang Belanja',
    'pelanggan'  => 'Data Pelanggan',
    'transaksi'  => 'Data Transaksi',
    'laporan'    => 'Laporan Toko',
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
<title><?= e($title) ?> · TokoKu</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>
<body>
<div class="app-shell">
<aside class="sidebar" id="sidebar">
  <a class="brand" href="index.php"><span class="brand-mark">✦</span><span><b>TokoKu</b><small>ADMIN PANEL</small></span></a>
  <div class="side-label">MENU UTAMA</div>
  <nav>
    <a class="<?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><i>⌂</i>Dashboard</a>
    <a class="<?= $page==='produk'?'active':'' ?>" href="?page=produk"><i>▦</i>Katalog Produk</a>
    <a class="<?= $page==='keranjang'?'active':'' ?>" href="?page=keranjang"><i>🛒</i>Keranjang <span class="nav-count"><?= array_sum($_SESSION['cart'] ?? []) ?></span></a>
    <a class="<?= $page==='pelanggan'?'active':'' ?>" href="?page=pelanggan"><i>♙</i>Pelanggan</a>
    <a class="<?= in_array($page,['transaksi','bayar'])?'active':'' ?>" href="?page=transaksi"><i>▤</i>Transaksi</a>
    <a class="<?= in_array($page,['laporan','view','join','subquery'])?'active':'' ?>" href="?page=laporan"><i>▥</i>Laporan</a>
  </nav>
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
  <div class="sidebar-bottom">
    <div class="rocket">🚀</div>
    <b>TokoKu Database</b>
    <small>Kelola dan uji data tokomu.</small>
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
    <div class="avatar"><?= e(strtoupper(substr($_SESSION['auth']['nama'], 0, 1))) ?></div>
    <b><?= e($_SESSION['auth']['nama']) ?> · <?= e(strtoupper($role)) ?></b>
    <a class="text-link" href="?logout=1">Keluar</a>
  </div>
</header>

<section class="content">
<div class="page-heading">
  <div>
    <div class="eyebrow">TOKOKU / <?= e(strtoupper($role)) ?></div>
    <h1><?= e($title) ?></h1>
    <p><?= $page==='dashboard' ? 'Pantau aktivitas dan performa tokomu hari ini.' : ($page==='produk' ? 'Temukan berbagai pilihan produk terbaik di TokoKu.' : 'Kelola data dan jalankan pengujian database db_tokoku.') ?></p>
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
<div class="stats-grid">
  <div class="stat-card blue"><div class="stat-icon">♙</div><span>Total Pelanggan</span><strong><?= $stats['customers'] ?></strong><small>Data pelanggan terdaftar</small><div class="spark">⌁</div></div>
  <div class="stat-card green"><div class="stat-icon">▤</div><span>Total Transaksi</span><strong><?= $stats['transactions'] ?></strong><small>Seluruh transaksi tercatat</small><div class="spark">⌁</div></div>
  <div class="stat-card orange"><div class="stat-icon">◈</div><span>Total Pendapatan</span><strong><?= rupiah($stats['revenue']) ?></strong><small>Akumulasi transaksi lunas</small><div class="spark">⌁</div></div>
  <div class="stat-card purple"><div class="stat-icon">⌖</div><span>Kota Pelanggan</span><strong><?= $stats['cities'] ?></strong><small>Kota berbeda</small><div class="spark">⌁</div></div>
</div>
<div class="dashboard-grid">
  <div class="panel chart-panel">
    <div class="panel-head"><div><h3>Ringkasan Transaksi</h3><p>Jumlah transaksi berdasarkan tanggal</p></div><span class="tag">LIVE DATA</span></div>
    <canvas id="salesChart" height="110"></canvas>
  </div>
  <div class="panel">
    <div class="panel-head"><div><h3>Pengujian Akhir</h3><p>8 materi pengujian database</p></div><span class="mini-icon">✦</span></div>
    <div class="test-shortcuts">
    <?php foreach ([['view','View','Lihat laporan'],['join','JOIN','Gabungkan tabel'],['subquery','Subquery','Analisis data'],['procedure','Stored Procedure','Panggil prosedur'],['function','Function','Hitung nilai'],['trigger','Trigger','Otomatis berjalan'],['transaction','COMMIT & ROLLBACK','Uji transaksi'],['explain','EXPLAIN','Analisis query']] as $i => $x): ?>
      <a href="?page=<?= $x[0] ?>" class="test-link"><span><?= sprintf('%02d', $i+1) ?></span><div><b><?= $x[1] ?></b><small><?= $x[2] ?></small></div><i>↗</i></a>
    <?php endforeach ?>
    </div>
  </div>
</div>
<div class="panel table-panel">
  <div class="panel-head"><div><h3>Transaksi Terbaru</h3><p>Data transaksi terakhir dari database</p></div><a class="text-link" href="?page=transaksi">Lihat semua ↗</a></div>
  <?php $recent = rows($pdo, "SELECT t.id_transaksi,t.tanggal,p.nama_pelanggan,t.total_belanja FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan ORDER BY t.id_transaksi DESC LIMIT 5"); ?>
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
$sql .= " ORDER BY $order";
$st = $pdo->prepare($sql);
$st->execute($args);
$products = $st->fetchAll();
?>
<div class="catalog-toolbar">
  <div>
    <span class="catalog-kicker">TOKOKU MARKETPLACE</span>
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
    <label>Foto Produk (JPG/PNG/WEBP, maks 2 MB)<input type="file" name="foto" id="fotoInput" accept="image/jpeg,image/png,image/webp,image/gif"></label>
    <div class="foto-preview" id="fotoPreview"><span>Pratinjau foto</span></div>
    <label class="wide">Deskripsi<textarea name="deskripsi" rows="2" placeholder="Deskripsi singkat produk"><?= e($editProduct['deskripsi'] ?? '') ?></textarea></label>
    <button class="btn primary"><?= $editProduct ? 'Simpan Perubahan' : 'Simpan Produk' ?></button>
  </form>
</div>
<?php endif ?>

<div class="catalog-filter">
  <div class="category-chips">
    <a class="<?= $category===''?'selected':'' ?>" href="?page=produk">Semua</a>
    <?php foreach ($cats as $cat): ?>
    <a class="<?= $category===$cat?'selected':'' ?>" href="?page=produk&kategori=<?= urlencode($cat) ?>"><?= e($cat) ?></a>
    <?php endforeach ?>
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
    <div class="stock-line">Stok <?= number_format((int)$p['stok'], 0, ',', '.') ?></div>
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
$cart     = $_SESSION['cart'] ?? [];
$cartRows = [];
$grand    = 0;
if ($cart) {
    $ids = array_map('intval', array_keys($cart));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT * FROM tbl_produk WHERE id_produk IN ($in)");
    $st->execute($ids);
    $cartRows = $st->fetchAll();
}
?>
<div class="panel table-panel">
  <div class="panel-head">
    <div><h3>Keranjang Belanja</h3><p>Periksa kembali produk pilihanmu.</p></div>
    <a class="text-link" href="?page=produk">← Lanjut belanja</a>
  </div>
  <?php if (!$cartRows): ?>
  <div class="empty-catalog"><span>🛒</span><b>Keranjang masih kosong</b><p>Yuk, cari produk yang kamu suka.</p><a class="btn primary" href="?page=produk">Mulai belanja</a></div>
  <?php else: ?>
  <form method="post">
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
        <label>Jumlah<input type="number" min="0" max="<?= $p['stok'] ?>" name="qty[<?= $p['id_produk'] ?>]" value="<?= $qty ?>"></label>
        <b><?= rupiah($sub) ?></b>
      </div>
    <?php endforeach ?>
    </div>
    <div class="cart-summary">
      <button class="btn primary">Perbarui Keranjang</button>
      <div><small>Total sementara</small><strong><?= rupiah($grand) ?></strong></div>
    </div>
  </form>
  <form method="post" class="checkout-form">
    <input type="hidden" name="action" value="checkout">
    <h3>Checkout</h3>
    <div class="checkout-grid">
      <div>
        <label>Nama pelanggan (opsional)</label>
        <input name="nama_baru" placeholder="Kosongkan untuk Pelanggan Umum">
        <small>Pelanggan tidak perlu login atau memiliki akun.</small>
      </div>
      <div>
        <label>Metode pembayaran</label>
        <div class="pay-methods">
          <label class="pay-option"><input type="radio" name="metode" value="tunai" checked><span><b>Tunai</b><small>Pembayaran langsung di kasir</small></span></label>
          <label class="pay-option"><input type="radio" name="metode" value="qris"><span><b>QRIS</b><small>Pembayaran QR di toko</small></span></label>
          <label class="pay-option"><input type="radio" name="metode" value="debit"><span><b>Kartu Debit</b><small>Pembayaran melalui EDC</small></span></label>
        </div>
      </div>
    </div>
    <div class="cart-summary">
      <div class="co-note">Transaksi langsung dicatat lunas setelah pembayaran diterima oleh kasir.</div>
      <div>
        <small>Total bayar</small>
        <strong><?= rupiah($grand) ?></strong>
        <button class="btn primary" style="margin-top:10px">Selesaikan Transaksi →</button>
      </div>
    </div>
  </form>
  <?php endif ?>
</div>

<?php elseif ($page === 'bayar'): ?>
<?php
$idT  = (int)($_GET['id'] ?? 0);
$st   = $pdo->prepare('SELECT t.*,p.nama_pelanggan,p.kota FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON p.id_pelanggan=t.id_pelanggan WHERE t.id_transaksi=?');
$st->execute([$idT]);
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
<div class="panel table-panel">
  <div class="panel-head"><div><h3>Riwayat Transaksi</h3><p>Transaksi dan pelanggan digabungkan dengan JOIN</p></div></div>
  <?php
  $fs  = $_GET['status'] ?? '';
  $sql = "SELECT t.*,p.nama_pelanggan,p.kota FROM tbl_transaksi t LEFT JOIN tbl_pelanggan p ON t.id_pelanggan=p.id_pelanggan WHERE (COALESCE(p.nama_pelanggan,'') LIKE ? OR COALESCE(t.kode_invoice,'') LIKE ?)";
  $args = ["%$search%", "%$search%"];
  if (in_array($fs, ['menunggu','dibayar','dibatalkan','kedaluwarsa'], true)) {
      $sql   .= ' AND t.status_pembayaran=?';
      $args[] = $fs;
  }
  $st = $pdo->prepare($sql . ' ORDER BY t.id_transaksi DESC');
  $st->execute($args);
  $trx = $st->fetchAll();
  ?>
  <div class="category-chips" style="margin:0 0 14px">
    <a class="<?= $fs===''?'selected':'' ?>" href="?page=transaksi">Semua</a>
    <?php foreach (['menunggu'=>'Menunggu bayar','dibayar'=>'Lunas','dibatalkan'=>'Dibatalkan','kedaluwarsa'=>'Kedaluwarsa'] as $k => $l): ?>
    <a class="<?= $fs===$k?'selected':'' ?>" href="?page=transaksi&status=<?= $k ?>"><?= $l ?></a>
    <?php endforeach ?>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>ID</th><th>Invoice</th><th>Tanggal</th><th>Pelanggan</th><th>Metode</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($trx as $r): ?>
        <tr>
          <td>#<?= $r['id_transaksi'] ?></td>
          <td><?= e($r['kode_invoice'] ?: '-') ?></td>
          <td><?= e($r['tanggal']) ?></td>
          <td><?= e($r['nama_pelanggan']) ?> <small style="color:#8999b8"><?= e($r['kota']) ?></small></td>
          <td><?= e($PAYMENT_METHODS[$r['metode_pembayaran'] ?? '']['label'] ?? 'Manual') ?></td>
          <td><?= rupiah($r['total_belanja']) ?></td>
          <td><?= badge((string)$r['status_pembayaran']) ?></td>
          <td><a class="btn tiny" href="?page=bayar&id=<?= $r['id_transaksi'] ?>"><?= $r['status_pembayaran']==='menunggu' ? 'Bayar' : 'Detail' ?></a></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$trx): ?><tr><td colspan="8" class="empty">Tidak ada transaksi.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif($page==='laporan' || $page==='view'): ?>
<div class="info-banner">
  <b>VIEW Database</b>
  <span>Data berikut diambil langsung dari view yang sudah tersedia pada db_tokoku.</span>
</div>

<div class="report-toolbar">
  <button type="button" class="btn primary" onclick="window.print()">🖨 Cetak Laporan</button>
  <button type="button" class="btn tiny" id="btnPdf" onclick="downloadReportPdf()">⬇ Unduh PDF</button>
</div>

<div id="reportPrintArea">
  <div class="report-header print-only">
    <h2>Laporan TokoKu</h2>
    <p>Dicetak: <?= date('d M Y H:i') ?> · <?= e($_SESSION['auth']['nama'] ?? 'Admin') ?></p>
  </div>

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
</div>


<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadReportPdf() {
    const report = document.getElementById('reportPrintArea');
    const button = document.getElementById('btnPdf');

    if (!report) {
        alert('Area laporan tidak ditemukan.');
        return;
    }

    // Hapus style cetak sebelumnya jika masih ada.
    const oldStyle = document.getElementById('reportPdfPrintStyle');
    if (oldStyle) oldStyle.remove();

    const style = document.createElement('style');
    style.id = 'reportPdfPrintStyle';

    style.textContent = `
        @media print {
            @page {
                size: A4 portrait;
                margin: 12mm;
            }

            body * {
                visibility: hidden !important;
            }

            #reportPrintArea,
            #reportPrintArea * {
                visibility: visible !important;
            }

            #reportPrintArea {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
                background: #ffffff !important;
                color: #111111 !important;
                font-family: Arial, sans-serif !important;
            }

            #reportPrintArea .print-only {
                display: block !important;
                margin-bottom: 18px !important;
            }

            #reportPrintArea .panel {
                display: block !important;
                width: 100% !important;
                margin-bottom: 18px !important;
                padding: 10px !important;
                background: #ffffff !important;
                color: #111111 !important;
                border: 1px solid #999999 !important;
                box-shadow: none !important;
                break-inside: auto !important;
                page-break-inside: auto !important;
            }

            #reportPrintArea .panel-head {
                display: block !important;
                margin-bottom: 10px !important;
            }

            #reportPrintArea .table-wrap {
                width: 100% !important;
                overflow: visible !important;
            }

            #reportPrintArea table {
                width: 100% !important;
                border-collapse: collapse !important;
                font-size: 10px !important;
            }

            #reportPrintArea th,
            #reportPrintArea td {
                border: 1px solid #777777 !important;
                padding: 6px !important;
                color: #111111 !important;
                background: #ffffff !important;
                text-align: left !important;
            }

            #reportPrintArea th {
                background: #e5e7eb !important;
                font-weight: bold !important;
            }

            #reportPrintArea button,
            #reportPrintArea .btn {
                display: none !important;
            }

            .report-toolbar,
            .info-banner,
            header,
            nav,
            aside,
            footer {
                display: none !important;
            }
        }
    `;

    document.head.appendChild(style);

    if (button) {
        button.disabled = true;
        button.textContent = 'Membuka cetak...';
    }

    function selesaiCetak() {
        style.remove();

        if (button) {
            button.disabled = false;
            button.textContent = '⬇ Unduh PDF';
        }

        window.removeEventListener('afterprint', selesaiCetak);
    }

    window.addEventListener('afterprint', selesaiCetak);

    // Beri waktu browser menerapkan CSS cetak.
    setTimeout(function () {
        window.print();
    }, 300);
}
</script>


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

<footer>© <?= date('Y') ?> TokoKu Admin Panel <span>PHP Native · MySQL · Dibuat untuk Pengujian Basis Data</span></footer>
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
<?php $chart = rows($pdo, "SELECT DATE_FORMAT(tanggal,'%d %b') label,COUNT(*) jumlah FROM tbl_transaksi GROUP BY tanggal ORDER BY tanggal"); ?>
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
            if ($key === $moneyKey || (str_contains(strtolower($key), 'total') && is_numeric($v))) {
                $v = rupiah($v);
            }
            echo '<td>' . e($v) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
?>