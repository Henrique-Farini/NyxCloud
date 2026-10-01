ALTER TABLE alertas_preferencias
    MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN usuario_id BIGINT NOT NULL DEFAULT 0 AFTER id,
    ADD UNIQUE KEY ux_alertas_preferencias_usuario (usuario_id);
