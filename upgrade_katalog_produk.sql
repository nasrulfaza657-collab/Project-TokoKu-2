-- Jalankan file ini jika database db_tokoku lama sudah pernah diimpor.
USE `db_tokoku`;

-- --------------------------------------------------------
-- Katalog Produk TokoKu (tambahan; tabel lama tetap dipertahankan)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tbl_produk` (
  `id_produk` int(11) NOT NULL AUTO_INCREMENT,
  `nama_produk` varchar(150) NOT NULL,
  `kategori` varchar(80) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `harga` decimal(12,2) NOT NULL DEFAULT 0,
  `stok` int(11) NOT NULL DEFAULT 0,
  `diskon` decimal(5,2) NOT NULL DEFAULT 0,
  `gambar` varchar(500) DEFAULT NULL,
  `terjual` int(11) NOT NULL DEFAULT 0,
  `rating` decimal(2,1) NOT NULL DEFAULT 5.0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_produk`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tbl_produk` (`nama_produk`,`kategori`,`deskripsi`,`harga`,`stok`,`diskon`,`gambar`,`terjual`,`rating`)
SELECT * FROM (
 SELECT 'Headset Gaming RGB Surround','Elektronik','Headset gaming dengan mikrofon jernih dan lampu RGB.',259000,24,15,'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=700',128,4.9 UNION ALL
 SELECT 'Mechanical Keyboard 87 Keys','Elektronik','Keyboard mekanikal compact untuk gaming dan produktivitas.',489000,18,10,'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=700',86,4.8 UNION ALL
 SELECT 'Mouse Wireless Ergonomis','Elektronik','Mouse wireless nyaman digunakan untuk aktivitas harian.',179000,35,20,'https://images.unsplash.com/photo-1527814050087-3793815479db?w=700',215,4.9 UNION ALL
 SELECT 'Smartwatch Active Series','Aksesoris','Jam pintar dengan fitur pemantau aktivitas dan notifikasi.',399000,12,12,'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=700',74,4.7 UNION ALL
 SELECT 'Tas Ransel Minimalis','Fashion','Tas harian dengan ruang penyimpanan luas dan desain simpel.',189000,20,0,'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=700',53,4.8 UNION ALL
 SELECT 'Sneakers Casual Daily','Fashion','Sepatu kasual ringan untuk penggunaan sehari-hari.',329000,15,18,'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=700',102,4.9 UNION ALL
 SELECT 'Lampu Meja LED','Rumah Tangga','Lampu meja minimalis dengan pencahayaan nyaman.',99000,40,5,'https://images.unsplash.com/photo-1507473885765-e6ed057f782c?w=700',41,4.6 UNION ALL
 SELECT 'Botol Minum Stainless','Rumah Tangga','Botol minum tahan suhu untuk menemani aktivitas.',89000,50,0,'https://images.unsplash.com/photo-1602143407151-7111542de6e8?w=700',167,4.8
) AS sample_products
WHERE NOT EXISTS (SELECT 1 FROM `tbl_produk` LIMIT 1);
