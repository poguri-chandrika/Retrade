-- Track per-user read state for negotiations
CREATE TABLE IF NOT EXISTS negotiation_reads (
  negotiation_id INT NOT NULL,
  user_id INT NOT NULL,
  last_read_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY (negotiation_id, user_id),
  FOREIGN KEY (negotiation_id) REFERENCES negotiations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
