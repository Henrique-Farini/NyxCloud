CREATE TABLE IF NOT EXISTS usuario_auditoria (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ator_id BIGINT NULL,
    alvo_usuario_id BIGINT NULL,
    acao VARCHAR(80) NOT NULL,
    detalhes JSON NOT NULL,
    ip VARCHAR(64),
    user_agent VARCHAR(500),
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_usuario_auditoria_criado_em (criado_em),
    KEY ix_usuario_auditoria_ator (ator_id),
    KEY ix_usuario_auditoria_alvo (alvo_usuario_id),
    CONSTRAINT fk_auditoria_ator FOREIGN KEY (ator_id) REFERENCES usuario(id) ON DELETE SET NULL,
    CONSTRAINT fk_auditoria_alvo FOREIGN KEY (alvo_usuario_id) REFERENCES usuario(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
