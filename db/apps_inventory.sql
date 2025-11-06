CREATE TABLE inventory_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(100) UNIQUE,
  name VARCHAR(200) NOT NULL,
  sector_id INT NULL,
  quantity INT NOT NULL DEFAULT 0,
  location VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE inventory_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  item_id BIGINT NOT NULL,
  direction ENUM('in','out') NOT NULL,
  amount INT NOT NULL,
  reason VARCHAR(200) NULL,
  user_id INT NULL,
  ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES inventory_items(id),
  INDEX (item_id, ts)
);
