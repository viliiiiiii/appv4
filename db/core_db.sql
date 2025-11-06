-- Core governance database schema
CREATE TABLE roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_slug VARCHAR(50) UNIQUE NOT NULL,
  label VARCHAR(80) NOT NULL
);

CREATE TABLE sectors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  key_slug VARCHAR(50) UNIQUE NOT NULL,
  name VARCHAR(80) NOT NULL
);

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE NOT NULL,
  pass_hash VARCHAR(255) NOT NULL,
  role_id INT NOT NULL,
  sector_id INT NULL,
  suspended_at DATETIME NULL,
  suspended_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  FOREIGN KEY (sector_id) REFERENCES sectors(id)
);

CREATE TABLE activity_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32) NULL,
  entity_id BIGINT NULL,
  meta JSON NULL,
  ip VARBINARY(16) NULL,
  ua VARCHAR(255) NULL,
  INDEX (ts),
  INDEX (user_id),
  INDEX (action)
);

INSERT IGNORE INTO roles (key_slug, label) VALUES
  ('viewer', 'Viewer'),
  ('admin', 'Admin'),
  ('root', 'Root');

INSERT IGNORE INTO sectors (key_slug, name) VALUES
  ('fo', 'FO'),
  ('technical', 'Technical'),
  ('it', 'IT');
