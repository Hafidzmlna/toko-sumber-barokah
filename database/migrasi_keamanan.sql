-- Jalankan SEKALI di HeidiSQL (login root).
USE toko_sumber_barokah;
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  username VARCHAR(50) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip, created_at),
  INDEX (username, created_at)
) ENGINE=InnoDB;
