-- OPSIONAL: index.php sudah menjalankan semua perubahan ini otomatis saat dibuka.
-- Jalankan manual di phpMyAdmin (database db_tokoku2) hanya jika Anda ingin file dump-nya ikut lengkap.
ALTER TABLE `tbl_produk`
  ADD COLUMN `diskon`  decimal(5,2) NOT NULL DEFAULT 0,
  ADD COLUMN `gambar`  varchar(500) DEFAULT NULL,
  ADD COLUMN `terjual` int(11) NOT NULL DEFAULT 0,
  ADD COLUMN `rating`  decimal(2,1) NOT NULL DEFAULT 5.0;

ALTER TABLE `tbl_transaksi`
  ADD COLUMN `status_pembayaran` enum('menunggu','dibayar','dibatalkan','kedaluwarsa') NOT NULL DEFAULT 'dibayar',
  ADD COLUMN `metode_pembayaran` varchar(30) DEFAULT NULL,
  ADD COLUMN `kode_invoice` varchar(30) DEFAULT NULL,
  ADD COLUMN `batas_bayar` datetime DEFAULT NULL,
  ADD COLUMN `dibayar_pada` datetime DEFAULT NULL,
  ADD COLUMN `bukti_bayar` varchar(255) DEFAULT NULL;
