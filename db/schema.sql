-- Studio Pro Subscription Box Management Platform — schema (MySQL 8 / InnoDB / utf8mb4)
-- Run via api/install.php (creates tables + seeds demo data).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  phone         VARCHAR(40)  DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('subscriber','merchant','ops') NOT NULL DEFAULT 'subscriber',
  token         CHAR(64)     DEFAULT NULL,
  token_expires DATETIME     DEFAULT NULL,
  created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS addresses (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  label             VARCHAR(60)  DEFAULT 'Home',
  line1             VARCHAR(190) NOT NULL,
  line2             VARCHAR(190) DEFAULT '',
  city              VARCHAR(90)  NOT NULL,
  state             VARCHAR(90)  DEFAULT '',
  postal_code       VARCHAR(20)  NOT NULL,
  country           CHAR(2)      DEFAULT 'US',
  validated         TINYINT(1)   DEFAULT 0,
  validation_source VARCHAR(40)  DEFAULT '',
  created_at        DATETIME     DEFAULT CURRENT_TIMESTAMP,
  KEY idx_addr_user (user_id),
  CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plans (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(120) NOT NULL,
  description      TEXT,
  price            DECIMAL(10,2) NOT NULL,
  billing_interval ENUM('monthly','quarterly') NOT NULL DEFAULT 'monthly',
  is_active        TINYINT(1) DEFAULT 1,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Box Customization Engine: each plan can have custom fields (e.g. flavor, size)
CREATE TABLE IF NOT EXISTS variant_fields (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id     INT UNSIGNED NOT NULL,
  field_key   VARCHAR(60) NOT NULL,
  field_label VARCHAR(120) NOT NULL,
  options_json TEXT NOT NULL,            -- JSON array of allowed values
  cutoff_reuse TINYINT(1) DEFAULT 1,     -- 1 = choice applies to every future box until changed
  sort        TINYINT DEFAULT 0,
  KEY idx_vf_plan (plan_id),
  CONSTRAINT fk_vf_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS subscriptions (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NOT NULL,
  plan_id             INT UNSIGNED NOT NULL,
  address_id          INT UNSIGNED DEFAULT NULL,
  status              ENUM('active','paused','canceled','skipped') NOT NULL DEFAULT 'active',
  current_period_start DATE NOT NULL,
  current_period_end  DATE NOT NULL,
  next_charge_at      DATE NOT NULL,
  pause_until         DATE DEFAULT NULL,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sub_user (user_id),
  KEY idx_sub_status (status),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customizations (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  field_id        INT UNSIGNED NOT NULL,
  field_value     VARCHAR(190) NOT NULL,
  updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cust (subscription_id, field_id),
  CONSTRAINT fk_cust_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
  CONSTRAINT fk_cust_field FOREIGN KEY (field_id) REFERENCES variant_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS skip_requests (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  cycle_date      DATE NOT NULL,           -- the box cycle being skipped
  reason          VARCHAR(190) DEFAULT '',
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_skip (subscription_id, cycle_date),
  CONSTRAINT fk_skip_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS addons (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plan_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(120) NOT NULL,
  description VARCHAR(255) DEFAULT '',
  price       DECIMAL(10,2) NOT NULL DEFAULT 0,
  sku         VARCHAR(60)  DEFAULT '',
  is_active   TINYINT(1) DEFAULT 1,
  CONSTRAINT fk_add_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS box_line_items (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  cycle_date      DATE NOT NULL,
  kind            ENUM('base','addon','customization') NOT NULL DEFAULT 'base',
  item_name       VARCHAR(190) NOT NULL,
  sku             VARCHAR(60) DEFAULT '',
  qty             INT NOT NULL DEFAULT 1,
  unit_price      DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_price     DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bl_sub (subscription_id, cycle_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  provider        VARCHAR(40) NOT NULL DEFAULT 'demo',
  provider_ref    VARCHAR(120) DEFAULT '',
  amount          DECIMAL(10,2) NOT NULL,
  status          ENUM('pending','succeeded','failed','refunded') NOT NULL DEFAULT 'pending',
  failure_reason  VARCHAR(190) DEFAULT '',
  paid_at         DATETIME DEFAULT NULL,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pay_sub (subscription_id),
  CONSTRAINT fk_pay_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Failed Payment Recovery (Dunning): 3-stage sequence (email x2, sms x1)
CREATE TABLE IF NOT EXISTS dunning_events (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  payment_id      INT UNSIGNED DEFAULT NULL,
  stage           TINYINT NOT NULL DEFAULT 1,
  channel         ENUM('email','sms') NOT NULL DEFAULT 'email',
  subject         VARCHAR(190) DEFAULT '',
  body            TEXT,
  sent_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dunning_sub (subscription_id)
) ENGINE=InnoDB;

-- Subscriber engagement signals used by the churn engine
CREATE TABLE IF NOT EXISTS subscriber_signals (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  signal_type ENUM('email_open','email_click','payment_update','ticket','login','skip') NOT NULL,
  signal_date DATE NOT NULL,
  meta_json   TEXT,
  KEY idx_sig_user (user_id, signal_type, signal_date),
  CONSTRAINT fk_sig_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS churn_scores (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  score           TINYINT NOT NULL,                     -- 0..100
  risk            ENUM('low','medium','high') NOT NULL,
  source          ENUM('ai','rules') NOT NULL,
  features_json   TEXT,
  scored_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_churn_sub (subscription_id, scored_at),
  CONSTRAINT fk_churn_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shipments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subscription_id INT UNSIGNED NOT NULL,
  cycle_date      DATE NOT NULL,
  carrier         VARCHAR(40) NOT NULL,
  tracking_number VARCHAR(80) DEFAULT '',
  label_url       VARCHAR(255) DEFAULT '',
  cost            DECIMAL(10,2) DEFAULT 0,
  status          ENUM('created','label_purchased','picked_up','delivered','returned') DEFAULT 'created',
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ship_sub (subscription_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS packing_runs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cycle_date  DATE NOT NULL,
  plan_id     INT UNSIGNED NOT NULL,
  total_boxes INT NOT NULL DEFAULT 0,
  status      ENUM('pending','printed','packed','done') DEFAULT 'pending',
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pr_run (cycle_date, plan_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS packing_items (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  run_id    INT UNSIGNED NOT NULL,
  sku       VARCHAR(60) DEFAULT '',
  item_name VARCHAR(190) NOT NULL,
  qty       INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_pi_run FOREIGN KEY (run_id) REFERENCES packing_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type            VARCHAR(60) NOT NULL,     -- e.g. skip, swap, addon_added, payment_failed
  subscription_id INT UNSIGNED DEFAULT NULL,
  meta_json       TEXT,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ev_sub (subscription_id, type)
) ENGINE=InnoDB;