CREATE DATABASE IF NOT EXISTS toko_sumber_barokah CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE toko_sumber_barokah;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('pelanggan','kasir','admin') NOT NULL DEFAULT 'pelanggan',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role ENUM('pelanggan','kasir','admin') NOT NULL,
  permission VARCHAR(30) NOT NULL,
  PRIMARY KEY (role, permission)
) ENGINE=InnoDB;

CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nama VARCHAR(150) NOT NULL,
  satuan ENUM('dus','pak','sak','slop','kt','bal','tali','lsn','kg','st','pcs') NOT NULL,
  harga_beli INT NOT NULL DEFAULT 0,
  harga_jual INT NOT NULL DEFAULT 0,
  stok INT NOT NULL DEFAULT 0,               -- jumlah stok; pelanggan hanya melihat ada/kosong
  promo_min INT NOT NULL DEFAULT 0,      -- minimal beli agar promo berlaku
  promo_harga INT NOT NULL DEFAULT 0,    -- harga satuan promo
  INDEX (nama)
) ENGINE=InnoDB;

CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  status ENUM('keranjang','baru','akan dikirim','dikirim') NOT NULL DEFAULT 'keranjang',
  metode_bayar ENUM('qris') NULL,
  bayar_status ENUM('menunggu','lunas') NULL,
  checkout_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL,
  harga_satuan INT NOT NULL,
  harga_beli INT NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE sales (            -- transaksi untuk laporan (offline & online terkirim)
  id INT AUTO_INCREMENT PRIMARY KEY,
  kode VARCHAR(20) NOT NULL UNIQUE,
  sumber ENUM('offline','online') NOT NULL,
  metode ENUM('tunai','qris') NOT NULL DEFAULT 'tunai',
  order_id INT NULL,
  kasir_id INT NULL,
  pelanggan VARCHAR(100) NULL,
  total INT NOT NULL,
  bayar INT NOT NULL,
  kembali INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (sumber, created_at)
) ENGINE=InnoDB;

CREATE TABLE sale_items (       -- nama & harga disalin supaya laporan tidak berubah saat barang diedit
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  nama_barang VARCHAR(150) NOT NULL,
  satuan VARCHAR(10) NOT NULL,
  qty INT NOT NULL,
  harga_satuan INT NOT NULL,
  harga_beli INT NOT NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE settings (
  k VARCHAR(50) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  username VARCHAR(50) NOT NULL,
  aksi VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO role_permissions (role, permission) VALUES
 ('pelanggan','katalog'),('pelanggan','pesan'),
 ('kasir','barang'),('kasir','orders'),('kasir','pos'),('kasir','laporan'),('kasir','qris'),
 ('admin','users'),('admin','roles'),('admin','log');

INSERT INTO products (nama,satuan,harga_beli,harga_jual,stok,promo_min,promo_harga) VALUES
 ('Beras Premium 25kg','sak',280000,300000,100,0,0),
 ('Minyak Goreng 2L','dus',210000,228000,100,10,215000),
 ('Gula Pasir 1kg','bal',560000,590000,100,0,0);

CREATE TABLE login_attempts (   -- percobaan login gagal, dipakai untuk pembatasan (rate limit)
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(50) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, created_at),
  INDEX (username, created_at)
) ENGINE=InnoDB;
