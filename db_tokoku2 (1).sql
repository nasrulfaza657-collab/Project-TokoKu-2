-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 11, 2026 at 07:38 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_tokoku2`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `CariPelanggan` (IN `p_id` INT)   BEGIN
    SELECT *
    FROM tbl_pelanggan
    WHERE id_pelanggan = p_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `HitungPelanggan` (OUT `jumlah` INT)   BEGIN
    SELECT COUNT(*)
    INTO jumlah
    FROM tbl_pelanggan;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `LaporanTransaksi` (IN `p_id_pelanggan` INT)   BEGIN
    SELECT 
        t.id_transaksi,
        t.tanggal,
        p.nama_pelanggan,
        p.kota,
        t.total_belanja
    FROM tbl_transaksi t
    JOIN tbl_pelanggan p 
    ON t.id_pelanggan = p.id_pelanggan
    WHERE t.id_pelanggan = p_id_pelanggan;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `TambahPelanggan` (IN `p_nama` VARCHAR(100), IN `p_kota` VARCHAR(50))   BEGIN
    INSERT INTO tbl_pelanggan (nama_pelanggan, kota)
    VALUES (p_nama, p_kota);
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `TampilPelanggan` ()   BEGIN
    SELECT * FROM tbl_pelanggan;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `HitungDiskon` (`harga` DECIMAL(12,2), `diskon` DECIMAL(5,2)) RETURNS DECIMAL(12,2) DETERMINISTIC NO SQL BEGIN
    RETURN harga * (diskon / 100);
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `HitungPajak` (`harga` DECIMAL(12,2)) RETURNS DECIMAL(12,2) DETERMINISTIC NO SQL BEGIN
    RETURN harga * 0.11;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `HitungTotal` (`harga` DECIMAL(12,2), `jumlah` INT) RETURNS DECIMAL(12,2) DETERMINISTIC NO SQL BEGIN
    RETURN harga * jumlah;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `KategoriBelanja` (`total` DECIMAL(12,2)) RETURNS VARCHAR(30) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC NO SQL BEGIN
    RETURN CASE
        WHEN total >= 1000000 THEN 'Pelanggan Gold'
        WHEN total >= 500000 THEN 'Pelanggan Silver'
        ELSE 'Pelanggan Reguler'
    END;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `KonversiNilai` (`nilai` INT) RETURNS VARCHAR(5) CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC NO SQL BEGIN
    RETURN CASE
        WHEN nilai >= 85 THEN 'A'
        WHEN nilai >= 75 THEN 'B'
        WHEN nilai >= 65 THEN 'C'
        ELSE 'D'
    END;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `log_pelanggan`
--

CREATE TABLE `log_pelanggan` (
  `id_log` int(11) NOT NULL,
  `aktivitas` varchar(50) DEFAULT NULL,
  `id_pelanggan` int(11) DEFAULT NULL,
  `waktu` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `log_pelanggan`
--

INSERT INTO `log_pelanggan` (`id_log`, `aktivitas`, `id_pelanggan`, `waktu`) VALUES
(1, 'INSERT PELANGGAN', 6, '2026-11-09 04:11:20'),
(2, 'INSERT PELANGGAN', 7, '2026-11-11 00:56:22'),
(3, 'INSERT PELANGGAN', 8, '2026-11-11 02:35:25'),
(4, 'INSERT PELANGGAN', 9, '2026-11-11 02:36:06'),
(5, 'INSERT PELANGGAN', 10, '2026-11-11 02:38:28'),
(6, 'INSERT PELANGGAN', 11, '2026-11-11 02:45:25'),
(7, 'INSERT PELANGGAN', 12, '2026-11-11 03:58:05'),
(8, 'INSERT PELANGGAN', 13, '2026-11-11 06:05:07'),
(9, 'INSERT PELANGGAN', 14, '2026-11-11 06:05:53');

-- --------------------------------------------------------

--
-- Table structure for table `log_perubahan_pelanggan`
--

CREATE TABLE `log_perubahan_pelanggan` (
  `id_log` int(11) NOT NULL,
  `id_pelanggan` int(11) DEFAULT NULL,
  `nama_lama` varchar(100) DEFAULT NULL,
  `nama_baru` varchar(100) DEFAULT NULL,
  `waktu` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `log_perubahan_pelanggan`
--

INSERT INTO `log_perubahan_pelanggan` (`id_log`, `id_pelanggan`, `nama_lama`, `nama_baru`, `waktu`) VALUES
(1, 4, 'Dewi', 'Rina Wijaya', '2026-11-09 04:12:31');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_detail_transaksi`
--

CREATE TABLE `tbl_detail_transaksi` (
  `id_detail` int(11) NOT NULL,
  `id_transaksi` int(11) NOT NULL,
  `id_produk` int(11) NOT NULL,
  `jumlah` int(11) NOT NULL,
  `harga_satuan` decimal(12,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_detail_transaksi`
--

INSERT INTO `tbl_detail_transaksi` (`id_detail`, `id_transaksi`, `id_produk`, `jumlah`, `harga_satuan`, `subtotal`) VALUES
(1, 5, 4, 1, 4000.00, 4000.00),
(2, 6, 5, 2, 3500.00, 7000.00),
(3, 7, 5, 1, 3500.00, 3500.00),
(4, 8, 4, 1, 4000.00, 4000.00),
(5, 9, 4, 1, 4000.00, 4000.00),
(6, 10, 5, 1, 3500.00, 3500.00),
(7, 11, 3, 1, 17000.00, 17000.00),
(8, 12, 2, 1, 18000.00, 18000.00),
(9, 13, 4, 1, 4000.00, 4000.00),
(10, 14, 5, 1, 3500.00, 3500.00),
(11, 15, 5, 1, 3500.00, 3500.00),
(12, 16, 3, 1, 20000.00, 20000.00),
(13, 17, 5, 1, 3500.00, 3500.00),
(14, 18, 4, 1, 4000.00, 4000.00),
(15, 19, 1, 1, 90000.00, 90000.00),
(16, 20, 1, 1, 90000.00, 90000.00);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_pelanggan`
--

CREATE TABLE `tbl_pelanggan` (
  `id_pelanggan` int(11) NOT NULL,
  `nama_pelanggan` varchar(100) DEFAULT NULL,
  `kota` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_pelanggan`
--

INSERT INTO `tbl_pelanggan` (`id_pelanggan`, `nama_pelanggan`, `kota`) VALUES
(1, 'Andi', 'Ponorogo'),
(2, 'Budi', 'Madiun'),
(3, 'Citra', 'Surabaya'),
(4, 'Rina Wijaya', 'Ponorogo'),
(5, 'Dewi', 'Ponorogo'),
(6, 'Rina', 'Ponorogo'),
(7, 'Administrator', ''),
(8, 'Pelanggan Umum', ''),
(9, 'Pelanggan Umum', ''),
(10, 'Pelanggan Umum', ''),
(11, 'faza', ''),
(12, 'Pelanggan Umum', ''),
(13, 'Pelanggan Umum', ''),
(14, 'Pelanggan Umum', '');

--
-- Triggers `tbl_pelanggan`
--
DELIMITER $$
CREATE TRIGGER `after_insert_pelanggan` AFTER INSERT ON `tbl_pelanggan` FOR EACH ROW BEGIN
    INSERT INTO log_pelanggan (aktivitas, id_pelanggan)
    VALUES ('INSERT PELANGGAN', NEW.id_pelanggan);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_update_pelanggan` AFTER UPDATE ON `tbl_pelanggan` FOR EACH ROW BEGIN
    INSERT INTO log_perubahan_pelanggan (id_pelanggan, nama_lama, nama_baru)
    VALUES (OLD.id_pelanggan, OLD.nama_pelanggan, NEW.nama_pelanggan);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_produk`
--

CREATE TABLE `tbl_produk` (
  `id_produk` int(11) NOT NULL,
  `nama_produk` varchar(150) NOT NULL,
  `kategori` varchar(100) DEFAULT NULL,
  `harga` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stok` int(11) NOT NULL DEFAULT 0,
  `deskripsi` text DEFAULT NULL,
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  `diskon` decimal(5,2) NOT NULL DEFAULT 0.00,
  `gambar` varchar(500) DEFAULT NULL,
  `terjual` int(11) NOT NULL DEFAULT 0,
  `rating` decimal(2,1) NOT NULL DEFAULT 5.0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_produk`
--

INSERT INTO `tbl_produk` (`id_produk`, `nama_produk`, `kategori`, `harga`, `stok`, `deskripsi`, `dibuat_pada`, `diskon`, `gambar`, `terjual`, `rating`) VALUES
(1, 'Beras Premium 5 Kg', 'Sembako', 90000.00, 19, 'Beras premium kemasan 5 kilogram', '2026-11-11 00:53:48', 0.00, 'assets/uploads/produk/d48e7d11d71dd513.png', 1, 5.0),
(2, 'Minyak Goreng 1 L', 'Sembako', 18000.00, 29, 'Minyak goreng kemasan 1 liter', '2026-11-11 00:53:48', 0.00, 'assets/uploads/produk/453747d2661ab642.jpg', 1, 5.0),
(3, 'Gula Pasir 1 Kg', 'Sembako', 20000.00, 23, 'Gula pasir kemasan 1 kilogram', '2026-11-11 00:53:48', 0.00, 'assets/uploads/produk/69d1aa2d8a67f9b9.jpg', 2, 5.0),
(4, 'Air Mineral 600 ml', 'Minuman', 4000.00, 43, 'Air mineral botol 600 ml', '2026-11-11 00:53:48', 0.00, 'assets/uploads/produk/07ac6071048c3ba9.webp', 4, 5.0),
(5, 'Mi Instan', 'Makanan', 3500.00, 53, 'Mi instan aneka rasa', '2026-11-11 00:53:48', 0.00, 'assets/uploads/produk/792766dc9eff8aa6.webp', 7, 5.0);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_transaksi`
--

CREATE TABLE `tbl_transaksi` (
  `id_transaksi` int(11) NOT NULL,
  `tanggal` date DEFAULT NULL,
  `id_pelanggan` int(11) DEFAULT NULL,
  `total_belanja` decimal(12,2) DEFAULT NULL,
  `id_user` int(11) DEFAULT NULL,
  `status_pembayaran` enum('menunggu','dibayar','dibatalkan','kedaluwarsa') NOT NULL DEFAULT 'dibayar',
  `metode_pembayaran` varchar(30) DEFAULT NULL,
  `kode_invoice` varchar(30) DEFAULT NULL,
  `batas_bayar` datetime DEFAULT NULL,
  `dibayar_pada` datetime DEFAULT NULL,
  `bukti_bayar` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_transaksi`
--

INSERT INTO `tbl_transaksi` (`id_transaksi`, `tanggal`, `id_pelanggan`, `total_belanja`, `id_user`, `status_pembayaran`, `metode_pembayaran`, `kode_invoice`, `batas_bayar`, `dibayar_pada`, `bukti_bayar`) VALUES
(1, '2026-10-01', 1, 250000.00, NULL, 'dibayar', NULL, NULL, NULL, NULL, NULL),
(2, '2026-10-02', 2, 350000.00, NULL, 'dibayar', NULL, NULL, NULL, NULL, NULL),
(3, '2026-10-03', 1, 150000.00, NULL, 'dibayar', NULL, NULL, NULL, NULL, NULL),
(4, '2026-10-04', 3, 500000.00, NULL, 'dibayar', NULL, NULL, NULL, NULL, NULL),
(5, '2026-11-11', 7, 4000.00, 1, 'dibayar', NULL, NULL, NULL, NULL, NULL),
(6, '2026-11-11', 4, 7000.00, NULL, 'dibayar', 'qris', 'INV2611116A948', '2026-11-12 08:25:16', '2026-11-11 08:25:33', NULL),
(7, '2026-11-11', 7, 3500.00, NULL, 'dibayar', 'bca', 'INV261111F15AC', '2026-11-12 08:33:03', '2026-11-11 08:33:14', NULL),
(8, '2026-11-11', 7, 4000.00, NULL, 'dibayar', 'gopay', 'INV26111106B0E', '2026-11-12 08:36:27', '2026-11-11 08:36:34', NULL),
(9, '2026-11-11', 1, 4000.00, NULL, 'dibayar', 'dana', 'INV261111856BE', '2026-11-12 08:38:12', '2026-11-11 08:38:16', NULL),
(10, '2026-11-11', 7, 3500.00, NULL, 'dibayar', 'qris', 'INV2611113CB42', '2026-11-12 08:39:12', '2026-11-11 08:44:00', NULL),
(11, '2026-11-11', 7, 17000.00, NULL, 'dibayar', 'bri', 'INV26111188910', '2026-11-12 08:42:27', '2026-11-11 08:43:29', NULL),
(12, '2026-11-11', 2, 18000.00, NULL, 'dibayar', 'qris', 'INV261111E2400', '2026-11-12 08:44:53', '2026-11-11 08:44:56', NULL),
(13, '2026-11-11', 6, 4000.00, NULL, 'dibayar', 'qris', 'INV26111157F40', '2026-11-12 09:15:31', '2026-11-11 09:15:35', NULL),
(14, '2026-11-11', 8, 3500.00, 4, 'dibayar', 'tunai', 'POS261111093525', NULL, '2026-11-11 09:35:25', NULL),
(15, '2026-11-11', 9, 3500.00, 4, 'dibayar', 'qris', 'POS261111093606', NULL, '2026-11-11 09:36:06', NULL),
(16, '2026-11-11', 10, 20000.00, 3, 'dibayar', 'qris', 'POS261111093828', NULL, '2026-11-11 09:38:28', NULL),
(17, '2026-11-11', 11, 3500.00, 4, 'dibayar', 'qris', 'POS261111094525', '2026-11-12 09:45:25', '2026-11-11 09:45:32', NULL),
(18, '2026-11-11', 12, 4000.00, 4, 'dibayar', 'qris', 'POS261111105805', '2026-11-12 10:58:05', '2026-11-11 10:58:14', NULL),
(19, '2026-11-11', 13, 90000.00, 3, 'dibayar', 'qris', 'POS261111130508', '2026-11-12 13:05:08', '2026-11-11 13:05:14', NULL),
(20, '2026-11-11', 14, 90000.00, 3, 'dibatalkan', 'qris', 'POS261111130553', '2026-11-12 13:05:53', NULL, NULL);

--
-- Triggers `tbl_transaksi`
--
DELIMITER $$
CREATE TRIGGER `before_insert_transaksi` BEFORE INSERT ON `tbl_transaksi` FOR EACH ROW BEGIN
    IF NEW.total_belanja < 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Total belanja tidak boleh negatif';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_user`
--

CREATE TABLE `tbl_user` (
  `id_user` int(11) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','kasir','user') NOT NULL DEFAULT 'kasir',
  `dibuat_pada` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_user`
--

INSERT INTO `tbl_user` (`id_user`, `nama`, `username`, `password`, `role`, `dibuat_pada`) VALUES
(1, 'Administrator', 'admin', '$2y$12$6pi2c/p8Z5C0rHrASciCUOAER4H5tqnAUdk1b8KkpDMnKZokwtJ7K', 'admin', '2026-11-11 00:53:48'),
(2, 'Kasir', 'user', '$2y$12$VkZjM19HO4WA2qqRnOqj0eWI9MHJsZtyVGDrVNSorDNbFDJGDWnf2', 'kasir', '2026-11-11 00:53:48'),
(3, 'Administrator Toko', 'adminbaru', '$2y$10$eHZ0gQNMFL74Eq0b16.4ieYD6y/n/SWZy1jaBftg6Ad12DOdNvYra', 'admin', '2026-11-11 02:31:00'),
(4, 'Petugas Kasir', 'kasir', '$2y$10$jSqHJLhDH0H6IcdEmclWM.FPeJhrhOcHB4IUjrpMqWJPL6fbdWUpu', 'kasir', '2026-11-11 02:31:00');

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_pelanggan`
-- (See below for the actual view)
--
CREATE TABLE `view_pelanggan` (
`id_pelanggan` int(11)
,`nama_pelanggan` varchar(100)
,`kota` varchar(50)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_total_belanja`
-- (See below for the actual view)
--
CREATE TABLE `view_total_belanja` (
`id_pelanggan` int(11)
,`nama_pelanggan` varchar(100)
,`total_transaksi` bigint(21)
,`total_belanja` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_transaksi_pelanggan`
-- (See below for the actual view)
--
CREATE TABLE `view_transaksi_pelanggan` (
`id_transaksi` int(11)
,`tanggal` date
,`nama_pelanggan` varchar(100)
,`kota` varchar(50)
,`total_belanja` decimal(12,2)
);

-- --------------------------------------------------------

--
-- Structure for view `view_pelanggan`
--
DROP TABLE IF EXISTS `view_pelanggan`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_pelanggan`  AS SELECT `tbl_pelanggan`.`id_pelanggan` AS `id_pelanggan`, `tbl_pelanggan`.`nama_pelanggan` AS `nama_pelanggan`, `tbl_pelanggan`.`kota` AS `kota` FROM `tbl_pelanggan` ;

-- --------------------------------------------------------

--
-- Structure for view `view_total_belanja`
--
DROP TABLE IF EXISTS `view_total_belanja`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_total_belanja`  AS SELECT `p`.`id_pelanggan` AS `id_pelanggan`, `p`.`nama_pelanggan` AS `nama_pelanggan`, count(`t`.`id_transaksi`) AS `total_transaksi`, coalesce(sum(`t`.`total_belanja`),0) AS `total_belanja` FROM (`tbl_pelanggan` `p` left join `tbl_transaksi` `t` on(`p`.`id_pelanggan` = `t`.`id_pelanggan`)) GROUP BY `p`.`id_pelanggan`, `p`.`nama_pelanggan` ;

-- --------------------------------------------------------

--
-- Structure for view `view_transaksi_pelanggan`
--
DROP TABLE IF EXISTS `view_transaksi_pelanggan`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_transaksi_pelanggan`  AS SELECT `t`.`id_transaksi` AS `id_transaksi`, `t`.`tanggal` AS `tanggal`, `p`.`nama_pelanggan` AS `nama_pelanggan`, `p`.`kota` AS `kota`, `t`.`total_belanja` AS `total_belanja` FROM (`tbl_transaksi` `t` join `tbl_pelanggan` `p` on(`t`.`id_pelanggan` = `p`.`id_pelanggan`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `log_pelanggan`
--
ALTER TABLE `log_pelanggan`
  ADD PRIMARY KEY (`id_log`);

--
-- Indexes for table `log_perubahan_pelanggan`
--
ALTER TABLE `log_perubahan_pelanggan`
  ADD PRIMARY KEY (`id_log`);

--
-- Indexes for table `tbl_detail_transaksi`
--
ALTER TABLE `tbl_detail_transaksi`
  ADD PRIMARY KEY (`id_detail`),
  ADD KEY `fk_detail_produk` (`id_produk`),
  ADD KEY `idx_detail_transaksi` (`id_transaksi`);

--
-- Indexes for table `tbl_pelanggan`
--
ALTER TABLE `tbl_pelanggan`
  ADD PRIMARY KEY (`id_pelanggan`);

--
-- Indexes for table `tbl_produk`
--
ALTER TABLE `tbl_produk`
  ADD PRIMARY KEY (`id_produk`),
  ADD KEY `idx_produk_nama` (`nama_produk`),
  ADD KEY `idx_produk_kategori` (`kategori`);

--
-- Indexes for table `tbl_transaksi`
--
ALTER TABLE `tbl_transaksi`
  ADD PRIMARY KEY (`id_transaksi`),
  ADD KEY `id_pelanggan` (`id_pelanggan`),
  ADD KEY `idx_transaksi_user` (`id_user`);

--
-- Indexes for table `tbl_user`
--
ALTER TABLE `tbl_user`
  ADD PRIMARY KEY (`id_user`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `log_pelanggan`
--
ALTER TABLE `log_pelanggan`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `log_perubahan_pelanggan`
--
ALTER TABLE `log_perubahan_pelanggan`
  MODIFY `id_log` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tbl_detail_transaksi`
--
ALTER TABLE `tbl_detail_transaksi`
  MODIFY `id_detail` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `tbl_pelanggan`
--
ALTER TABLE `tbl_pelanggan`
  MODIFY `id_pelanggan` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `tbl_produk`
--
ALTER TABLE `tbl_produk`
  MODIFY `id_produk` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `tbl_transaksi`
--
ALTER TABLE `tbl_transaksi`
  MODIFY `id_transaksi` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `tbl_user`
--
ALTER TABLE `tbl_user`
  MODIFY `id_user` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_detail_transaksi`
--
ALTER TABLE `tbl_detail_transaksi`
  ADD CONSTRAINT `fk_detail_produk` FOREIGN KEY (`id_produk`) REFERENCES `tbl_produk` (`id_produk`),
  ADD CONSTRAINT `fk_detail_transaksi` FOREIGN KEY (`id_transaksi`) REFERENCES `tbl_transaksi` (`id_transaksi`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_transaksi`
--
ALTER TABLE `tbl_transaksi`
  ADD CONSTRAINT `fk_transaksi_user` FOREIGN KEY (`id_user`) REFERENCES `tbl_user` (`id_user`) ON DELETE SET NULL,
  ADD CONSTRAINT `tbl_transaksi_ibfk_1` FOREIGN KEY (`id_pelanggan`) REFERENCES `tbl_pelanggan` (`id_pelanggan`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
