# TokoKu Admin Panel — PHP Native

## Kebutuhan
- XAMPP (Apache + MySQL/MariaDB)
- PHP 8.0+
- Database `db_tokoku2`

## Instalasi
1. Ekstrak folder `tokoku_native` ke `C:\xampp\htdocs\`.
2. Jalankan Apache dan MySQL dari XAMPP.
3. Buka phpMyAdmin: `http://localhost/phpmyadmin`.
4. Jika database `db_tokoku` belum dibuat, buat database tersebut lalu import `db_tokoku.sql`.
   - Jika database lama sudah berisi tabel pelanggan dan transaksi, **jangan import ulang seluruh dump**. Cukup pilih database `db_tokoku` di phpMyAdmin, lalu import file `upgrade_katalog_produk.sql` satu kali.
5. Periksa `config.php`. Database yang dipakai adalah `db_tokoku2`. Default XAMPP biasanya:
   - host: `localhost`
   - database: `db_tokoku`
   - user: `root`
   - password: kosong
6. Buka `http://localhost/tokoku_native/`.

## Fitur
- Dashboard statistik dari tabel pelanggan dan transaksi.
- Katalog produk bergaya marketplace: kartu produk, foto, diskon, rating, kategori, pencarian, dan pengurutan.
- CRUD produk lengkap (tambah, lihat, edit, hapus), unggah/ganti foto, dan keranjang POS berbasis session.
- CRUD pelanggan.
- Tambah dan daftar transaksi.
- VIEW: `view_pelanggan`, `view_total_belanja`, `view_transaksi_pelanggan`.
- JOIN: `tbl_transaksi` dan `tbl_pelanggan`.
- Subquery analisis total belanja pelanggan di atas rata-rata.
- Stored Procedure: `TampilPelanggan`, `CariPelanggan`, `HitungPelanggan`, `LaporanTransaksi`, `TambahPelanggan`.
- Function: `HitungDiskon`, `HitungPajak`, `HitungTotal`, `KategoriBelanja`, `KonversiNilai`.
- Trigger dan tabel log.
- Uji transaksi COMMIT / ROLLBACK.
- EXPLAIN query.

## Login dan hak akses staf
- Sistem menggunakan dua role staf: **admin** dan **kasir**. Pelanggan toko offline tidak perlu login.
- Login admin awal: `adminbaru` / `Admin123!`
- Login kasir awal: `kasir` / `Kasir123!`
- Akun lama dengan role `user` otomatis dimigrasikan menjadi `kasir` saat aplikasi dibuka.
- Admin dapat mengelola produk (tambah, lihat, edit, hapus), pelanggan, laporan, dan menu pengujian database.
- Kasir dapat melihat katalog, mengelola keranjang, dan menyelesaikan transaksi POS. Kasir tidak dapat mengubah/menghapus produk.
- Checkout toko offline: pelanggan opsional (kosong = Pelanggan Umum), metode Tunai/QRIS/Kartu Debit, transaksi langsung tercatat lunas dan stok otomatis berkurang.
- Ganti password akun awal setelah login sebelum digunakan secara rutin.

## Catatan keamanan
- Gunakan hanya di localhost atau jaringan toko yang tepercaya. Sebelum dipublikasikan, tambahkan CSRF token, pembatasan percobaan login, validasi tambahan, HTTPS, dan pengamanan konfigurasi.
- Tabel `tbl_produk` ditambahkan sebagai pelengkap tanpa mengubah tabel pelanggan dan transaksi yang sudah ada. Produk contoh otomatis tersedia setelah SQL diimpor.
- Chart.js dan Google Fonts dimuat dari CDN sehingga perlu koneksi internet saat halaman dibuka.

## Checkout & Pembayaran (baru)
- Keranjang → pilih pelanggan + metode pembayaran (QRIS, transfer BCA/BRI/Mandiri, GoPay, DANA, ShopeePay) → "Buat Pesanan & Bayar".
- Pesanan berstatus **menunggu** dulu. Transaksi baru dihitung lunas setelah admin menekan "Konfirmasi pembayaran diterima".
- Stok dikurangi saat pesanan dibuat; bila dibatalkan atau lewat batas waktu (24 jam), stok dikembalikan otomatis.
- Ganti nomor rekening/e-wallet di `config.php`. Untuk QRIS asli, taruh gambar QRIS toko di `assets/qris.png`.
- Foto produk diunggah dari komputer (JPG/PNG/WEBP/GIF, maks 2 MB), tersimpan di `assets/uploads/`.
- Konfirmasi pembayaran masih manual (belum terhubung payment gateway seperti Midtrans/Xendit).
