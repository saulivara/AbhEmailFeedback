-- Create database (rename if you prefer)
CREATE DATABASE IF NOT EXISTS alanwalker_feedback
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE alanwalker_feedback;

-- Table to store ratings
CREATE TABLE IF NOT EXISTS email_ratings (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rating        TINYINT UNSIGNED NOT NULL,              -- 1..5
  preference    ENUM('more','less','stop') NOT NULL,    -- user's choice
  comments      TEXT NULL,
  campaign_uid  VARCHAR(64)  NULL,
  subscriber_uid VARCHAR(64) NULL,
  email         VARCHAR(255) NULL,
  list_uid      VARCHAR(64)  NULL,
  subject       VARCHAR(255) NULL,
  page_url      TEXT NULL,
  referrer      TEXT NULL,
  user_agent    TEXT NULL,
  tz            VARCHAR(64)  NULL,
  ip_address    VARCHAR(45)  NULL,                      -- IPv4/IPv6
  PRIMARY KEY (id),
  INDEX idx_created_at (created_at),
  INDEX idx_campaign (campaign_uid),
  INDEX idx_subscriber (subscriber_uid),
  INDEX idx_email (email)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC;