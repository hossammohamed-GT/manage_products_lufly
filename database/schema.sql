-- LUFLY Catalog Database Schema
-- This file is executed once during installation

-- Products table
CREATE TABLE IF NOT EXISTS products (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(190) NOT NULL DEFAULT '',
    brand VARCHAR(190) NOT NULL DEFAULT 'lufly',
    status VARCHAR(50) NOT NULL DEFAULT 'Running',
    description TEXT NOT NULL,
    notes TEXT NOT NULL,
    source_file VARCHAR(255) NOT NULL DEFAULT '',
    source_row INT NOT NULL DEFAULT 0,
    source_extra TEXT NOT NULL,
    archived INT NOT NULL DEFAULT 0,
    verified INT NOT NULL DEFAULT 0,
    version INT NOT NULL DEFAULT 1,
    created_at VARCHAR(30) NOT NULL,
    updated_at VARCHAR(30) NOT NULL,
    INDEX idx_code (code),
    INDEX idx_archived (archived),
    INDEX idx_verified (verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media table (product images and attachments)
CREATE TABLE IF NOT EXISTS media (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    path VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'photo',
    caption VARCHAR(255) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    previewable INT NOT NULL DEFAULT 1,
    source_column VARCHAR(20) NOT NULL DEFAULT '',
    source_row INT NOT NULL DEFAULT 0,
    sha256 VARCHAR(64) NOT NULL DEFAULT '',
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product (product_id),
    INDEX idx_kind (kind),
    INDEX idx_previewable (previewable)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings table (key-value pairs for app settings)
CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
