CREATE TABLE IF NOT EXISTS Vati_Payfiller_Verifier_Component_Capabilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    component_key VARCHAR(64) NOT NULL,
    routing_priority TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vp_vcc_user_component (user_id, component_key),
    KEY idx_vp_vcc_user_enabled_priority (user_id, is_enabled, routing_priority),
    KEY idx_vp_vcc_component_enabled_priority (component_key, is_enabled, routing_priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

