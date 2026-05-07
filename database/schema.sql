CREATE DATABASE IF NOT EXISTS globalmt CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE globalmt;

CREATE TABLE IF NOT EXISTS admin_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transfers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference_number VARCHAR(80) NOT NULL UNIQUE,
  full_name VARCHAR(200) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  iban VARCHAR(64) NOT NULL,
  bank_name VARCHAR(200) NOT NULL,
  from_platform VARCHAR(120) NOT NULL,
  status ENUM('Pending','Processing','Sent','Delivered','Failed') NOT NULL DEFAULT 'Pending',
  transaction_date DATE NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_transaction_date (transaction_date)
) ENGINE=InnoDB;

INSERT INTO admin_users (username, password_hash)
SELECT 'admin', '$2y$10$gTAbYmWzJTwk9ruGQvn.FOi03VHZBH76lf340mX5WxWW037jvNIRC'
WHERE NOT EXISTS (
  SELECT 1 FROM admin_users WHERE username = 'admin'
);

INSERT INTO transfers (reference_number, full_name, amount, currency, iban, bank_name, from_platform, status, transaction_date, notes)
SELECT 'GMT-2024-001234', 'Rohan Sharma', 500.00, 'USD', 'GB29NWBK60161331926819', 'State Bank of India', 'Web Portal', 'Delivered', '2024-05-01', 'Delivered to recipient account'
WHERE NOT EXISTS (
  SELECT 1 FROM transfers WHERE reference_number = 'GMT-2024-001234'
);
