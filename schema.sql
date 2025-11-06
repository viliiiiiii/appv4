CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','user') NOT NULL DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE buildings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  building_id INT NOT NULL,
  room_number VARCHAR(50) NOT NULL,
  label VARCHAR(190) NULL,
  UNIQUE KEY(building_id, room_number),
  FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
);

CREATE TABLE tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  building_id INT NOT NULL,
  room_id INT NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  priority ENUM('', 'low','low/mid','mid','mid/high','high') NOT NULL DEFAULT '',
  assigned_to VARCHAR(190) NULL,
  status ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
  due_date DATE NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE RESTRICT,
  FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT
);

CREATE TABLE task_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  s3_key VARCHAR(255) NOT NULL,
  url VARCHAR(500) NOT NULL,
  position TINYINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY(task_id, position),
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
