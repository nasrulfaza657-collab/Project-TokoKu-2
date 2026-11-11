# 🛒 TokoKu — Aplikasi Kasir dan Manajemen Toko

TokoKu adalah aplikasi kasir berbasis web yang dibuat menggunakan **PHP Native dan MySQL**. Aplikasi ini membantu mengelola produk, pelanggan, keranjang belanja, transaksi, serta laporan toko melalui satu sistem.

## ✨ Fitur Utama

- **Login Pengguna** — Sistem autentikasi dengan hak akses Admin dan Kasir.
- **Dashboard** — Menampilkan ringkasan aktivitas toko.
- **Manajemen Produk** — Mengelola data produk dan informasi stok.
- **Keranjang Belanja** — Menambahkan, mengubah, dan menghapus produk dari keranjang.
- **Checkout** — Memproses transaksi dengan pilihan pembayaran tunai, QRIS, dan debit.
- **Manajemen Transaksi** — Melihat dan mengelola data transaksi serta status pembayaran.
- **Data Pelanggan** — Menampilkan dan mengelola informasi pelanggan.
- **Laporan** — Menampilkan rekap data pelanggan dan transaksi menggunakan database VIEW.
- **Cetak dan PDF** — Mencetak laporan atau mengunduhnya dalam format PDF.
- **Pengujian Database** — Tersedia halaman untuk mempelajari dan menguji VIEW, JOIN, Subquery, Stored Procedure, Function, Trigger, COMMIT & ROLLBACK, serta EXPLAIN.

## 🧰 Teknologi yang Digunakan

| Teknologi | Kegunaan |
|---|---|
| PHP Native | Backend aplikasi |
| MySQL | Database |
| HTML5 | Struktur halaman |
| CSS3 | Tampilan antarmuka |
| JavaScript | Interaksi halaman |
| XAMPP | Server lokal |

## 📁 Struktur Project

```text
tokoku_native/
├── assets/
│   └── style.css
├── config.php
├── index.php
├── struk.php
└── db_tokoku.sql
```

*Struktur di atas merupakan gambaran file utama proyek.*

## ⚙️ Cara Instalasi

Ikuti langkah berikut untuk menjalankan aplikasi di komputer lokal.

### 1. Clone Repository

```bash
git clone https://github.com/USERNAME/REPOSITORY.git
```

Ganti `USERNAME` dan `REPOSITORY` sesuai alamat repository GitHub kamu.

### 2. Pindahkan Folder

Pindahkan folder proyek ke direktori `htdocs` XAMPP.

Contoh:

```text
C:/xampp/htdocs/tokoku_native/
```

### 3. Jalankan XAMPP

Aktifkan:
- Apache
- MySQL

### 4. Buat Database

1. Buka phpMyAdmin melalui `http://localhost/phpmyadmin`.
2. Buat database dengan nama `db_tokoku`.
3. Pilih database tersebut.
4. Import file `db_tokoku.sql`.

### 5. Konfigurasi Database

Buka file `config.php`, kemudian sesuaikan konfigurasi koneksi database dengan pengaturan MySQL di komputer kamu.

### 6. Jalankan Aplikasi

Buka browser dan akses:

```text
http://localhost/tokoku_native/
```

Login menggunakan akun yang tersedia di database atau akun yang telah dibuat.

## 🔐 Hak Akses Pengguna

| Fitur | Admin | Kasir |
|---|:---:|:---:|
| Dashboard | ✅ | ✅ |
| Produk | ✅ | ✅ |
| Keranjang | ✅ | ✅ |
| Transaksi | ✅ | ✅ |
| Laporan | ✅ | ✅ |
| Manajemen Pelanggan | ✅ | ❌ |
| Pengujian Database | ✅ | ❌ |

## 🗄️ Database

Aplikasi menggunakan database MySQL dengan beberapa tabel dan VIEW untuk mendukung pengelolaan data toko.

Beberapa VIEW yang digunakan pada halaman laporan:

- `view_pelanggan`
- `view_total_belanja`
- `view_transaksi_pelanggan`

## 📌 Catatan

- Aplikasi ini ditujukan untuk dijalankan pada server lokal menggunakan XAMPP.
- Pastikan database sudah diimpor sebelum menjalankan aplikasi.
- Sesuaikan konfigurasi koneksi database pada `config.php`.
- Jangan mengunggah password atau informasi akun pribadi ke repository publik.

## 👨‍💻 Pengembang

**TokoKu — Aplikasi Kasir dan Manajemen Toko**

Dibuat sebagai proyek pembelajaran dan pengembangan aplikasi berbasis web menggunakan PHP Native dan MySQL.

---

⭐ Jika proyek ini bermanfaat, jangan lupa berikan **Star** pada repository ini!
