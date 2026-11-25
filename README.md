# TokoKu — Aplikasi Kasir & Admin Panel (PHP Native)

Aplikasi kasir toko offline berbasis PHP native + MySQL/MariaDB, dengan katalog produk, keranjang POS, member, scanner barcode/RFID, struk, laporan, menu Pengaturan, dan menu pengujian database.

## Kebutuhan
- XAMPP (Apache + MySQL/MariaDB)
- PHP 8.0+ dengan ekstensi `pdo_mysql` dan `mbstring` (aktif bawaan XAMPP)
- Database `db_tokoku2`

## Instalasi
1. Ekstrak folder `tokoku_native` ke `C:\xampp\htdocs\`.
2. Jalankan Apache dan MySQL dari XAMPP.
3. Buka phpMyAdmin (`http://localhost/phpmyadmin`), buat database `db_tokoku2`, lalu import `db_tokoku2.sql`.
   - Jika database lama sudah berisi data, **jangan import ulang**. Aplikasi menambah kolom dan tabel yang kurang secara otomatis saat pertama dibuka.
4. Periksa `config.php`. Bawaan XAMPP: host `localhost`, user `root`, password kosong.
5. Buka `http://localhost/tokoku_native/`.

Tabel `tbl_pengaturan`, `tbl_member`, `tbl_detail_transaksi`, dan kolom tambahan produk/transaksi dibuat otomatis, tidak perlu SQL tambahan.

## Struktur folder
```
tokoku_native/
├── index.php            # halaman utama, login, semua menu & aksi form
├── config.php           # koneksi DB, helper pengaturan, metode pembayaran
├── laporan_view.php     # tampilan menu Laporan (tab, grafik, dokumen PDF)
├── laporan_lib.php      # query laporan + pembuat file Excel (.xlsx)
├── struk.php            # cetak struk thermal (+ barcode invoice)
├── kartu_member.php     # cetak kartu member
├── barcode.php          # label barcode produk & kartu login staf
├── README.md
└── assets/
    ├── style.css        # gaya tampilan
    ├── barcode.js       # generator barcode Code 128 (offline)
    ├── qris.png         # (opsional) gambar QRIS toko
    └── uploads/
        ├── .htaccess    # blokir eksekusi PHP
        ├── produk/      # foto produk
        ├── toko/        # logo toko & gambar QRIS dari Pengaturan
        └── bukti/       # bukti pembayaran
```
Folder `produk/`, `toko/`, dan `bukti/` dibuat otomatis saat file pertama diunggah.

| File | Fungsi |
|---|---|
| `index.php` | Halaman utama, login, semua menu, dan seluruh aksi form |
| `config.php` | Koneksi database, `setting()` / `pengaturan_simpan()`, metode pembayaran |
| `laporan_view.php` | Halaman Laporan: filter periode, tab, grafik, dokumen PDF |
| `laporan_lib.php` | `laporanPeriode()`, `laporanData()`, `laporanXlsx()`, `lapTabel()` |
| `struk.php` | Struk 58/80 mm: logo, identitas toko, item, diskon member, barcode invoice |
| `kartu_member.php` | Kartu member berisi barcode kode member |
| `barcode.php` | Label barcode produk dan kartu login staf (admin) |

## Struktur database (`db_tokoku2`)
| Tabel | Isi | Kolom penting |
|---|---|---|
| `tbl_user` | Akun staf | `id_user`, `username` (unik), `password` (hash), `role` (admin/kasir), `kode_kartu`, `kode_rfid` (hash) |
| `tbl_pelanggan` | Pelanggan | `id_pelanggan`, `nama_pelanggan`, `kota` |
| `tbl_member` | Member (pelanggan terdaftar) | `id_member`, `id_pelanggan`, `kode_member`, `kode_rfid`, `no_hp`, `diskon_persen`, `aktif` |
| `tbl_produk` | Katalog | `id_produk`, `nama_produk`, `kategori`, `harga`, `stok`, `diskon`, `gambar`, `terjual`, `rating`, `barcode` (unik) |
| `tbl_transaksi` | Header transaksi | `id_transaksi`, `tanggal`, `id_pelanggan`, `id_user`, `id_member`, `total_belanja`, `diskon_member`, `kode_invoice`, `metode_pembayaran`, `status_pembayaran`, `batas_bayar`, `dibayar_pada`, `bukti_bayar` |
| `tbl_detail_transaksi` | Rincian item | `id_detail`, `id_transaksi`, `id_produk`, `jumlah`, `harga_satuan`, `subtotal` |
| `tbl_pengaturan` | Pengaturan aplikasi | `kunci` (PK), `nilai`, `diubah_pada` |
| `log_pelanggan` | Log trigger insert pelanggan | `id_log`, `aktivitas`, `id_pelanggan`, `waktu` |
| `log_perubahan_pelanggan` | Log trigger update nama | `id_log`, `id_pelanggan`, `nama_lama`, `nama_baru`, `waktu` |

Relasi utama:
```
tbl_pelanggan 1─┬─* tbl_transaksi *─1 tbl_user        (id_user: ON DELETE SET NULL)
                ├─0..1 tbl_member ─* tbl_transaksi    (id_member)
tbl_transaksi 1─* tbl_detail_transaksi *─1 tbl_produk (detail ikut terhapus bersama transaksi)
```
Status pembayaran: `menunggu`, `dibayar`, `dibatalkan`, `kedaluwarsa`.

Kunci di `tbl_pengaturan`: `nama_toko`, `alamat_toko`, `telepon_toko`, `footer_struk`, `lebar_struk`, `struk_barcode`, `logo_toko`, `metode_aktif`, `qris_gambar`, `member_diskon_default`, `payment_timeout_hours`, `stok_menipis`. Kunci yang belum tersimpan memakai nilai bawaan.

Objek database lain: VIEW (`view_pelanggan`, `view_total_belanja`, `view_transaksi_pelanggan`), 5 Stored Procedure, 5 Function, dan 3 Trigger (lihat bagian Pengujian database).

## Login dan hak akses
Dua role staf: **admin** dan **kasir**. Pelanggan toko tidak perlu login.

| Role | Akun awal | Password awal |
|---|---|---|
| Admin | `adminbaru` | `Admin123!` |
| Kasir | `kasir` | `Kasir123!` |

**Ganti password akun awal segera setelah login** (menu Pengaturan > Akun Saya).

- **Admin:** semua menu — produk, pelanggan, member, transaksi, laporan, pengaturan, cetak barcode, dan pengujian database.
- **Kasir:** katalog, keranjang, member, transaksi, laporan, dan pengaturan akun sendiri. Kasir tidak bisa mengubah/menghapus produk.
- Perubahan role atau penghapusan akun oleh admin langsung berlaku pada sesi pengguna tersebut di permintaan berikutnya.

### Halaman login
Tampilan gelap dengan latar bergerak, logo dan nama toko dari Pengaturan, tombol lihat/sembunyikan password, animasi getar saat gagal, dan area tap kartu RFID/barcode. Animasi otomatis mati bila perangkat memakai pengaturan "kurangi gerakan".

## Menu Pengaturan
Buka dari sidebar **Pengaturan** atau klik inisial nama di pojok kanan atas.

### Akun Saya (admin & kasir)
- Ubah nama tampilan.
- Ganti password: wajib memasukkan password saat ini, minimal 8 karakter, harus berbeda dari yang lama, dan perlu konfirmasi.

### Toko & Struk (admin)
| Pengaturan | Keterangan |
|---|---|
| Logo toko | Unggah JPG/PNG/WEBP/GIF (maks 2 MB). Tampil di sidebar, halaman login, ikon tab browser, dan struk (hitam-putih). Centang "Hapus logo" untuk kembali ke ikon bawaan. |
| Nama toko | Dipakai di sidebar, login, judul halaman, struk, dan kartu member |
| Telepon, alamat | Dicetak di kepala struk (opsional) |
| Pesan penutup | Teks di bawah struk |
| Lebar kertas | 58 mm atau 80 mm |
| Barcode di struk | Cetak barcode nomor invoice (bawaan aktif) |

Pratinjau struk di sebelah kanan berubah langsung saat Anda mengetik atau memilih logo.

### Transaksi & Member (admin)
| Pengaturan | Keterangan |
|---|---|
| Metode pembayaran | Tunai / QRIS / Kartu Debit. Hanya yang dicentang tampil di checkout; minimal satu harus aktif. |
| Gambar QRIS | Menggantikan `assets/qris.png`. Jika kosong dipakai `assets/qris.png` atau QR contoh (demo). |
| Diskon member bawaan | Nilai awal untuk member baru, 0–50%. Member yang sudah ada tidak berubah. |
| Batas waktu bayar QRIS | Tetap 5 menit. Pesanan QRIS yang lewat batas otomatis kedaluwarsa dan stok dikembalikan. |
| Peringatan stok menipis | Produk dengan stok ≤ angka ini diberi tanda di katalog, ada filter **Stok menipis**, dan peringatan di dashboard. Isi 0 untuk mematikan. |

### Akun Staf (admin)
- Tambah akun (username 3–30 karakter: huruf kecil, angka, `.` `-` `_`; password minimal 8 karakter).
- Ubah nama, role, dan reset password.
- Hapus akun (riwayat transaksinya tetap tersimpan).
- Aturan pengaman: tidak bisa menghapus atau menurunkan role akun sendiri, dan harus selalu ada minimal satu admin.
- Kartu login barcode/RFID tetap diatur di **Cetak Barcode > Kartu Login Staf**.

> Pengaturan disimpan di tabel `tbl_pengaturan`. Nilai `$PAYMENT_TIMEOUT_HOURS` dan `$MEMBER_DISKON_DEFAULT` di `config.php` kini dibaca dari tabel ini, jadi tidak perlu diedit manual.

## Laporan
Menu **Laporan** (admin & kasir) menampilkan analisis penjualan dalam beberapa **tab** (Ringkasan, Penjualan, Produk, Pelanggan, Kasir, Transaksi) tanpa harus menggulir panjang ke bawah. Tab Kasir hanya terlihat oleh admin.

- **Filter periode:** Hari ini, 7 hari, 30 hari, Bulan ini, Tahun ini, Semua, atau rentang tanggal sendiri. Semua angka hanya menghitung transaksi **lunas** (pesanan menunggu, dibatalkan, kedaluwarsa, dan data uji tidak ikut).
- **Ringkasan:** omzet, jumlah transaksi, rata-rata per transaksi, item terjual (dengan pembanding persen terhadap periode sebelumnya), grafik omzet dan transaksi, porsi metode pembayaran, produk terlaris, dan pelanggan teratas.
- **Penjualan:** tabel per hari dan per metode bayar, lengkap dengan baris total.
- **Produk:** penjualan per produk dan kategori, serta daftar stok menipis.
- **Pelanggan:** pelanggan teratas dan rekap per kota. **Kasir:** kinerja tiap akun. **Transaksi:** daftar transaksi lunas dengan tautan ke detail.
- Tabel Produk, Pelanggan, dan Transaksi punya kotak pencarian.

**Unduh laporan (tanpa dialog cetak browser):**
- **PDF:** dibuat langsung menjadi file `laporan-<toko>-<dari>_<sampai>.pdf` (A4, kop berisi logo dan identitas toko, grafik, dan semua tabel). Memakai pustaka html2pdf dari CDN, jadi perlu internet.
- **Excel (.xlsx):** file asli dengan beberapa sheet (Ringkasan, Penjualan Harian, Produk, Pelanggan, Kasir, Transaksi), format rupiah, dan baris total. Dibuat di server dan butuh ekstensi PHP `zip` (aktif bawaan XAMPP).

## Kasir & transaksi
- **Katalog** bergaya marketplace: kartu produk, foto, diskon, rating, kategori, pencarian, dan pengurutan.
- **Keranjang:** jumlah diubah dengan tombol **−** / **+** atau diketik, dan tersimpan otomatis. Jika jumlah melebihi stok, muncul pemberitahuan dan jumlah disesuaikan. Tombol **＋ Keranjang** menolak penambahan bila stok sudah habis di keranjang.
- **Checkout:** pelanggan opsional (kosong = Pelanggan Umum). Tunai dan Debit langsung tercatat lunas; QRIS berstatus *menunggu* sampai admin menekan **Konfirmasi pembayaran diterima**. Stok berkurang saat pesanan dibuat dan dikembalikan bila dibatalkan atau kedaluwarsa.
- **Struk:** dicetak dari halaman detail transaksi lunas, berisi logo, identitas toko, rincian item, diskon member, dan **barcode nomor invoice** (transaksi manual memakai `TRX` + ID).

## Scanner barcode & RFID
- Dukung scanner/reader **USB mode keyboard** (mengetik kode lalu Enter). Tanpa driver.
- **Belanja:** di Katalog atau Keranjang, scan barcode produk; tiap scan menambah 1 pcs. Barcode produk dibuat otomatis (12 digit) atau isi barcode pabrik di form produk. Label dicetak dari **Cetak Barcode**.
- **Login dengan kartu:** admin membuat kartu staf atau mendaftarkan RFID di **Cetak Barcode > Kartu Login Staf**; staf cukup tap kartu di halaman login. Kode kartu hanya disimpan sebagai hash.
- Perlakukan kartu staf seperti kunci. UID RFID murah bisa disalin, jangan tinggalkan kartu admin di meja kasir.

## Member & potongan harga
- Menu **Member**: daftarkan member baru atau jadikan pelanggan lama sebagai member. Tiap member mendapat kode barcode 12 digit (awalan 30) dan boleh punya kartu RFID.
- Admin dapat mengubah persen diskon (maks 50%), status aktif/nonaktif, dan RFID tiap member.
- Saat transaksi, scan kartu member (barcode/RFID) atau ketik kode/nomor HP/nama di keranjang. Diskon dihitung dari total setelah diskon produk dan tampil di keranjang, invoice, dan struk. Member nonaktif tidak mendapat diskon.
- Kartu member dicetak dari tombol **Cetak kartu**.

## Pengujian database (admin)
- VIEW: `view_pelanggan`, `view_total_belanja`, `view_transaksi_pelanggan`
- JOIN `tbl_transaksi` dan `tbl_pelanggan`; subquery pelanggan di atas rata-rata
- Stored Procedure: `TampilPelanggan`, `CariPelanggan`, `HitungPelanggan`, `LaporanTransaksi`, `TambahPelanggan`
- Function: `HitungDiskon`, `HitungPajak`, `HitungTotal`, `KategoriBelanja`, `KonversiNilai`
- Trigger dan tabel log, uji COMMIT / ROLLBACK, dan EXPLAIN query

## Riwayat perbaikan bug
- **Struk bisa dibuka tanpa login.** `struk.php` kini wajib login.
- **Setiap penjualan umum membuat pelanggan baru.** Sekarang memakai satu baris "Pelanggan Umum". Duplikat lama digabung otomatis satu kali (transaksi tetap utuh, member tidak disentuh).
- **Uji COMMIT menambah pendapatan palsu.** Data uji kini berstatus *dibatalkan* sehingga tidak masuk dashboard maupun laporan.
- **Dashboard salah hitung.** Total transaksi dan grafik kini hanya menghitung transaksi lunas; jumlah kota tidak lagi menghitung kota kosong.
- **Kolom "Jumlah Transaksi" tampil sebagai rupiah** di halaman View. Sudah diperbaiki.
- **Nomor invoice bisa kembar** jika dua transaksi terjadi dalam detik yang sama. Format baru: `POS` + tanggal + ID transaksi (contoh `POS26112300012`).
- **Transaksi manual** kini khusus admin, divalidasi (tanggal, pelanggan), dan mencatat akun pembuatnya.
- **Login password salah** kini diberi jeda singkat untuk menyulitkan tebak password.
- **`ALTER TABLE` berjalan di setiap halaman** (memperlambat). Sekarang hanya dijalankan bila perlu.
- Inisial avatar mendukung nama multi-byte, dan nama toko dipakai konsisten di sidebar dan katalog.

## Catatan keamanan
- Gunakan hanya di localhost atau jaringan toko yang tepercaya.
- Form Pengaturan memakai token CSRF. Form lain dan login belum; tambahkan CSRF, pembatasan percobaan login, HTTPS, dan pengamanan `config.php` sebelum dipublikasikan.
- Folder `assets/uploads/` memblokir eksekusi file PHP lewat `.htaccess`.
- Konfirmasi pembayaran masih manual (belum terhubung Midtrans/Xendit). QRIS bawaan adalah demo.
- Chart.js, html2pdf, dan Google Fonts dimuat dari CDN, jadi perlu internet saat halaman dibuka.
## Catatan perbaikan (25 November 2026)
- Filter laporan memakai batas akhir eksklusif (`< tanggal berikutnya`) agar transaksi bertipe `DATETIME` pada hari terakhir tidak terlewat.
- Periode kustom memvalidasi tanggal kalender secara ketat; tanggal tidak valid kembali ke periode 30 hari.
- Periode semua waktu mengambil tanggal minimum dengan `DATE()` agar aman untuk kolom `DATETIME`.
- Teks deskripsi halaman produk diperbaiki agar nama toko tampil, bukan kode PHP mentah.

Catatan: seluruh file PHP sudah lolos `php -l`. Pengujian integrasi laporan tetap perlu dilakukan pada database `db_tokoku2` milik Anda karena dump SQL tidak disertakan dalam arsip ini.


## Perbaikan laporan per kasir

- Transaksi POS baru dan transaksi manual dicatat dengan `tbl_transaksi.id_user` dari sesi akun yang sedang login.
- Laporan kasir difilter berdasarkan `id_user` sesi, sedangkan admin dapat melihat semua atau memilih kasir.
- Untuk instalasi yang hanya memiliki satu akun kasir, transaksi lunas lama yang `id_user`-nya kosong akan dihubungkan ke satu-satunya akun kasir tersebut.
- Jika terdapat beberapa akun kasir, transaksi lama tanpa `id_user` tidak dibagikan otomatis karena pemiliknya tidak dapat diketahui dengan aman. Admin perlu menentukan pemilik transaksi tersebut.
