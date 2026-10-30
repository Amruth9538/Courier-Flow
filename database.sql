CREATE DATABASE IF NOT EXISTS parcel_delivery_system;
USE parcel_delivery_system;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) DEFAULT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','rider') NOT NULL,
  phone VARCHAR(30) DEFAULT NULL,
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS riders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  vehicle_type VARCHAR(50) DEFAULT NULL,
  license_number VARCHAR(100) DEFAULT NULL,
  profile_photo VARCHAR(255) DEFAULT NULL,
  is_online TINYINT(1) DEFAULT 0,
  is_available TINYINT(1) DEFAULT 1,
  current_latitude DECIMAL(10,8) DEFAULT NULL,
  current_longitude DECIMAL(11,8) DEFAULT NULL,
  last_seen TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parcels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tracking_number VARCHAR(50) NOT NULL UNIQUE,
  recipient_name VARCHAR(150) NOT NULL,
  recipient_phone VARCHAR(30) NOT NULL,
  recipient_address TEXT NOT NULL,
  recipient_latitude DECIMAL(10,8) DEFAULT NULL,
  recipient_longitude DECIMAL(11,8) DEFAULT NULL,
  recipient_geocoded_at TIMESTAMP NULL DEFAULT NULL,
  pickup_address TEXT DEFAULT NULL,
  parcel_type VARCHAR(100) DEFAULT NULL,
  weight_kg DECIMAL(6,2) DEFAULT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'Pending',
  assigned_rider_id INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (assigned_rider_id) REFERENCES riders(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parcel_status_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parcel_id INT NOT NULL,
  status VARCHAR(50) NOT NULL,
  remarks TEXT DEFAULT NULL,
  photo_path VARCHAR(255) DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rider_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rider_id INT NOT NULL,
  latitude DECIMAL(10,8) NOT NULL,
  longitude DECIMAL(11,8) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One shared, latest route result for each delivery. Both the rider and admin
-- dashboards read this record so they always show the same distance and ETA.
CREATE TABLE IF NOT EXISTS delivery_route_snapshots (
  parcel_id INT PRIMARY KEY,
  rider_id INT NOT NULL,
  start_latitude DECIMAL(10,8) NOT NULL,
  start_longitude DECIMAL(11,8) NOT NULL,
  end_latitude DECIMAL(10,8) NOT NULL,
  end_longitude DECIMAL(11,8) NOT NULL,
  distance_km DECIMAL(10,2) DEFAULT NULL,
  duration_min DECIMAL(10,1) DEFAULT NULL,
  route_geometry LONGTEXT DEFAULT NULL,
  calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
  FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The admin's original A-to-C planned route is kept separate from live data.
CREATE TABLE IF NOT EXISTS parcel_route_plans (
  parcel_id INT PRIMARY KEY,
  rider_id INT NOT NULL,
  start_latitude DECIMAL(10,8) NOT NULL,
  start_longitude DECIMAL(11,8) NOT NULL,
  end_latitude DECIMAL(10,8) NOT NULL,
  end_longitude DECIMAL(11,8) NOT NULL,
  distance_km DECIMAL(10,2) DEFAULT NULL,
  duration_min DECIMAL(10,1) DEFAULT NULL,
  route_geometry LONGTEXT NOT NULL,
  backup_distance_km DECIMAL(10,2) DEFAULT NULL,
  backup_duration_min DECIMAL(10,1) DEFAULT NULL,
  backup_route_geometry LONGTEXT DEFAULT NULL,
  planned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
  FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each rider location is tied to the parcel that was actively being delivered.
CREATE TABLE IF NOT EXISTS parcel_route_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  parcel_id INT NOT NULL,
  rider_id INT NOT NULL,
  latitude DECIMAL(10,8) NOT NULL,
  longitude DECIMAL(11,8) NOT NULL,
  recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_route_history_parcel_time (parcel_id, recorded_at),
  FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
  FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parcel_id INT NOT NULL,
  rider_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
  FOREIGN KEY (rider_id) REFERENCES riders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT DEFAULT NULL,
  action VARCHAR(255) NOT NULL,
  details TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (username, full_name, email, password, role, phone, status) VALUES
('admin', 'System Administrator', 'admin@example.com', '$2y$10$F8PpzSLPEXxUAwaZksGaXud0X.bkBIu4uw4fFDbuRu8LOi7.0nQm.', 'admin', '0123456789', 'active'),
('rider', 'Rider User', 'rider@example.com', '$2y$10$F8PpzSLPEXxUAwaZksGaXud0X.bkBIu4uw4fFDbuRu8LOi7.0nQm.', 'rider', '0987654321', 'active')
ON DUPLICATE KEY UPDATE
username = VALUES(username),
full_name = VALUES(full_name),
password = VALUES(password),
role = VALUES(role),
phone = VALUES(phone),
status = VALUES(status);

INSERT INTO riders (user_id, vehicle_type, license_number, is_online, is_available) VALUES
(2, 'Scooter', 'XYZ789', 0, 1)
ON DUPLICATE KEY UPDATE
vehicle_type = VALUES(vehicle_type),
license_number = VALUES(license_number),
is_online = VALUES(is_online),
is_available = VALUES(is_available);
