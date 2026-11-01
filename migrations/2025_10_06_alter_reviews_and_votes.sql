-- Alter reviews and add real-life features tables

-- Add updated_at and change default status to 'pending' for moderation
ALTER TABLE reviews 
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL 
  AFTER created_at;

-- Not all MySQL versions support IF NOT EXISTS on MODIFY. Use TRY/CATCH contextually.
-- Set default to pending for new rows (may require proper privileges)
ALTER TABLE reviews MODIFY status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'pending';

-- Helpful votes (one vote per user per review)
CREATE TABLE IF NOT EXISTS review_votes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  review_id INT NOT NULL,
  user_id INT NOT NULL,
  helpful TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vote (review_id, user_id),
  CONSTRAINT fk_vote_review FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_vote_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Abuse reports (one report per user per review)
CREATE TABLE IF NOT EXISTS review_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  review_id INT NOT NULL,
  reporter_id INT NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report (review_id, reporter_id),
  CONSTRAINT fk_report_review FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
  CONSTRAINT fk_report_user FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
);
