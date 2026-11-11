-- Migrasi role staf TokoKu menjadi admin dan kasir.
-- index.php juga menjalankan migrasi role ini secara otomatis.
ALTER TABLE tbl_user MODIFY role ENUM('admin','kasir','user') NOT NULL DEFAULT 'kasir';
UPDATE tbl_user SET role='kasir' WHERE role='user';

-- Akun awal dibuat otomatis oleh index.php menggunakan password_hash():
-- adminbaru / Admin123!
-- kasir / Kasir123!
