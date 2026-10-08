-- SGI 1.0.0 — núcleo
-- Compatível com MySQL 5.7+/8 e MariaDB 10.3+ sem privilégio SUPER (sem triggers, sem DEFINER).

CREATE TABLE IF NOT EXISTS settings (
  skey VARCHAR(100) NOT NULL PRIMARY KEY,
  svalue MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organizations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  legal_name VARCHAR(255) NULL,
  tax_id VARCHAR(60) NULL,
  address VARCHAR(255) NULL,
  logo_path VARCHAR(255) NULL,
  status ENUM('ativa','suspensa') NOT NULL DEFAULT 'ativa',
  is_library_source TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('plataforma','admin','direcao','coordenador','supervisor','operario') NOT NULL DEFAULT 'operario',
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_users_org (org_id),
  CONSTRAINT fk_users_org FOREIGN KEY (org_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NULL,
  ip VARCHAR(64) NOT NULL,
  org_id INT UNSIGNED NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_email (email, created_at),
  KEY idx_la_ip (ip, created_at),
  KEY idx_la_org (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Biblioteca da plataforma (modelos neutralizados) — não pertence a nenhuma organização.
CREATE TABLE IF NOT EXISTS library_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(40) NOT NULL,
  doc_type VARCHAR(80) NOT NULL,
  source_file VARCHAR(255) NOT NULL UNIQUE,
  neutral_path VARCHAR(255) NULL,
  scan_findings TEXT NULL,
  status ENUM('pendente_revisao','aprovado','rejeitado') NOT NULL DEFAULT 'pendente_revisao',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documentos controlados / modelos de cada organização
CREATE TABLE IF NOT EXISTS templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  code VARCHAR(80) NOT NULL,
  title VARCHAR(255) NOT NULL,
  category VARCHAR(40) NOT NULL,
  doc_type VARCHAR(80) NOT NULL,
  is_form TINYINT(1) NOT NULL DEFAULT 1,
  current_version_id INT UNSIGNED NULL,
  requires_approval TINYINT(1) NOT NULL DEFAULT 0,
  approver_role VARCHAR(30) NULL,
  required_fields TEXT NULL,
  library_id INT UNSIGNED NULL,
  source_file VARCHAR(255) NULL,
  status ENUM('vigente','obsoleto') NOT NULL DEFAULT 'vigente',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tpl_org_code (org_id, code),
  KEY idx_tpl_org_cat (org_id, category),
  CONSTRAINT fk_tpl_org FOREIGN KEY (org_id) REFERENCES organizations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS template_versions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  template_id INT UNSIGNED NOT NULL,
  version_no INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  sha256 CHAR(64) NOT NULL,
  reason VARCHAR(500) NULL,
  trusted TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('vigente','obsoleta') NOT NULL DEFAULT 'vigente',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tv (template_id, version_no),
  KEY idx_tv_org (org_id),
  CONSTRAINT fk_tv_tpl FOREIGN KEY (template_id) REFERENCES templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS read_confirmations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  template_version_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rc (template_version_id, user_id),
  KEY idx_rc_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registos (rascunho no MySQL → final com SHA-256)
CREATE TABLE IF NOT EXISTS records (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  template_id INT UNSIGNED NOT NULL,
  template_version_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  status ENUM('rascunho','pendente_aprovacao','final') NOT NULL DEFAULT 'rascunho',
  data_json MEDIUMTEXT NOT NULL,
  lock_version INT UNSIGNED NOT NULL DEFAULT 1,
  parent_record_id INT UNSIGNED NULL,
  version_no INT NOT NULL DEFAULT 1,
  correction_reason VARCHAR(500) NULL,
  pending_final_html MEDIUMTEXT NULL,
  final_path VARCHAR(255) NULL,
  final_sha256 CHAR(64) NULL,
  final_size INT UNSIGNED NULL,
  archive_category VARCHAR(60) NULL,
  archive_type VARCHAR(80) NULL,
  archive_year SMALLINT NULL,
  archive_month TINYINT NULL,
  created_by INT UNSIGNED NOT NULL,
  updated_by INT UNSIGNED NULL,
  finalized_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finalized_at DATETIME NULL,
  KEY idx_rec_org_status (org_id, status),
  KEY idx_rec_archive (org_id, archive_category, archive_type, archive_year, archive_month),
  KEY idx_rec_parent (parent_record_id),
  CONSTRAINT fk_rec_org FOREIGN KEY (org_id) REFERENCES organizations(id),
  CONSTRAINT fk_rec_tpl FOREIGN KEY (template_id) REFERENCES templates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS record_approvals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  record_id INT UNSIGNED NOT NULL,
  approver_role VARCHAR(30) NULL,
  requested_by INT UNSIGNED NOT NULL,
  status ENUM('pendente','aprovado','rejeitado') NOT NULL DEFAULT 'pendente',
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  comment VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ap_org_status (org_id, status),
  CONSTRAINT fk_ap_rec FOREIGN KEY (record_id) REFERENCES records(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'ficheiro',
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  caption VARCHAR(500) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_att_entity (org_id, entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogos
CREATE TABLE IF NOT EXISTS positions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  code VARCHAR(40) NULL,
  name VARCHAR(190) NOT NULL,
  department VARCHAR(120) NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pos (org_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  id_number VARCHAR(60) NULL,
  position_id INT UNSIGNED NULL,
  department VARCHAR(120) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(60) NULL,
  hired_at DATE NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_w_org (org_id),
  UNIQUE KEY uq_w_idn (org_id, id_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  tax_id VARCHAR(60) NULL,
  category VARCHAR(120) NULL,
  contact VARCHAR(190) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(60) NULL,
  status ENUM('aprovado','em_avaliacao','suspenso') NOT NULL DEFAULT 'em_avaliacao',
  last_evaluation DATE NULL,
  score DECIMAL(5,2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_s_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Não conformidades e ações
CREATE TABLE IF NOT EXISTS nonconformities (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  origin VARCHAR(60) NULL,
  standard VARCHAR(40) NULL,
  nc_type ENUM('nc','observacao','oportunidade','incidente','acidente') NOT NULL DEFAULT 'nc',
  severity ENUM('baixa','media','alta') NOT NULL DEFAULT 'media',
  status ENUM('aberta','em_tratamento','fechada') NOT NULL DEFAULT 'aberta',
  root_cause TEXT NULL,
  detected_at DATE NULL,
  due_date DATE NULL,
  owner_user_id INT UNSIGNED NULL,
  record_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  UNIQUE KEY uq_nc_code (org_id, code),
  KEY idx_nc_org_status (org_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS actions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  nc_id INT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  action_type ENUM('corretiva','preventiva','melhoria','imediata') NOT NULL DEFAULT 'corretiva',
  owner_user_id INT UNSIGNED NULL,
  due_date DATE NULL,
  status ENUM('pendente','em_curso','concluida','cancelada') NOT NULL DEFAULT 'pendente',
  efficacy ENUM('nao_avaliada','eficaz','nao_eficaz') NOT NULL DEFAULT 'nao_avaliada',
  closed_at DATETIME NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_act_org_status (org_id, status),
  KEY idx_act_nc (nc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trainings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  topic VARCHAR(120) NULL,
  provider VARCHAR(190) NULL,
  planned_date DATE NULL,
  hours DECIMAL(6,2) NULL,
  status ENUM('planeada','realizada','cancelada') NOT NULL DEFAULT 'planeada',
  efficacy_note TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tr_org (org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS training_participants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NOT NULL,
  training_id INT UNSIGNED NOT NULL,
  worker_id INT UNSIGNED NOT NULL,
  attended TINYINT(1) NOT NULL DEFAULT 0,
  result VARCHAR(120) NULL,
  UNIQUE KEY uq_tp (training_id, worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  body VARCHAR(1000) NULL,
  link VARCHAR(255) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_n_user (user_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NULL,
  entity_id INT UNSIGNED NULL,
  details TEXT NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_org (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_name VARCHAR(255) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  reason VARCHAR(60) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
