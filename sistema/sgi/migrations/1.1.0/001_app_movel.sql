-- SGI 1.1.0 — aplicação móvel, trabalho sem ligação, tokens, QR, calendário, indicadores

CREATE TABLE IF NOT EXISTS devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  user_agent VARCHAR(255) NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  revoked_at DATETIME NULL,
  KEY idx_dev_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  token_type ENUM('access','refresh') NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  replaced_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tok_dev (device_id),
  CONSTRAINT fk_tok_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idempotency_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  idem_key VARCHAR(100) NOT NULL,
  endpoint VARCHAR(120) NOT NULL,
  status_code SMALLINT NOT NULL,
  response MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_idem (user_id, idem_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NULL,
  endpoint VARCHAR(700) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL UNIQUE,
  p256dh VARCHAR(255) NULL,
  auth VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_ok_at DATETIME NULL,
  last_error VARCHAR(255) NULL,
  KEY idx_push_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Equipamentos, extintores e locais (com QR)
CREATE TABLE IF NOT EXISTS assets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  kind ENUM('equipamento','extintor','local') NOT NULL DEFAULT 'equipamento',
  code VARCHAR(60) NOT NULL,
  name VARCHAR(190) NOT NULL,
  location VARCHAR(190) NULL,
  brand VARCHAR(120) NULL,
  serial_no VARCHAR(120) NULL,
  notes TEXT NULL,
  next_check_date DATE NULL,
  form_template_id INT UNSIGNED NULL,
  qr_token CHAR(32) NOT NULL UNIQUE,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_asset_code (org_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Calendário do SGI no servidor
CREATE TABLE IF NOT EXISTS calendar_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  event_type ENUM('auditoria','capacitacao','manutencao','revisao','vencimento_legal','reuniao','outro') NOT NULL DEFAULT 'outro',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  description TEXT NULL,
  owner_user_id INT UNSIGNED NULL,
  status ENUM('planeado','realizado','cancelado') NOT NULL DEFAULT 'planeado',
  recurrence ENUM('nenhuma','mensal','trimestral','semestral','anual') NOT NULL DEFAULT 'nenhuma',
  reminder_days INT NOT NULL DEFAULT 7,
  last_reminder_for DATE NULL,
  related_entity VARCHAR(40) NULL,
  related_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cal_org_date (org_id, start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicators (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  standard VARCHAR(40) NULL,
  unit VARCHAR(40) NULL,
  target DECIMAL(14,4) NULL,
  direction ENUM('maior','menor') NOT NULL DEFAULT 'maior',
  frequency ENUM('mensal','trimestral','semestral','anual') NOT NULL DEFAULT 'mensal',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ind_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS indicator_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  indicator_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  value DECIMAL(14,4) NOT NULL,
  note VARCHAR(500) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_iv (indicator_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_batches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  entity VARCHAR(40) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  rows_total INT NOT NULL,
  rows_ok INT NOT NULL,
  rows_error INT NOT NULL,
  status ENUM('previa','aplicado','cancelado') NOT NULL DEFAULT 'previa',
  payload MEDIUMTEXT NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
