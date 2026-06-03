-- Workflow communication operational tables
CREATE TABLE IF NOT EXISTS Vati_Payfiller_Workflow_Communications (
  communication_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id VARCHAR(64) NOT NULL,
  case_id BIGINT NULL,
  component_key VARCHAR(64) NOT NULL,
  role_key VARCHAR(32) NOT NULL,
  action_key VARCHAR(64) NOT NULL,
  template_id BIGINT NULL,
  subject VARCHAR(500) NOT NULL,
  body MEDIUMTEXT NULL,
  checklist_json JSON NULL,
  notes TEXT NULL,
  deadline_label VARCHAR(64) NULL,
  sent_by_user_id BIGINT NULL,
  sent_by_name VARCHAR(255) NULL,
  sent_at DATETIME NOT NULL,
  delivery_status VARCHAR(32) NOT NULL DEFAULT 'sent',
  workflow_version INT NULL,
  PRIMARY KEY (communication_id),
  KEY idx_wc_app (application_id),
  KEY idx_wc_case (case_id),
  KEY idx_wc_component (component_key),
  KEY idx_wc_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS workflow_communication_events (
  event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  communication_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  event_meta JSON NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (event_id),
  KEY idx_wce_comm (communication_id),
  CONSTRAINT fk_wce_comm FOREIGN KEY (communication_id) REFERENCES Vati_Payfiller_Workflow_Communications(communication_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

