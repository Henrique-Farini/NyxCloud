CREATE TABLE IF NOT EXISTS alertas_email_configuracao (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    escopo VARCHAR(20) NOT NULL,
    empresa_id BIGINT NULL,
    escopo_chave VARCHAR(100) NOT NULL UNIQUE,
    ativo TINYINT(1) NOT NULL DEFAULT 0,
    modo VARCHAR(20) NOT NULL DEFAULT 'actionable',
    frequencia VARCHAR(20) NOT NULL DEFAULT 'instant',
    criado_por BIGINT NULL,
    atualizado_por BIGINT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT ck_alertas_email_config_escopo CHECK (escopo IN ('global', 'empresa')),
    CONSTRAINT ck_alertas_email_config_modo CHECK (modo IN ('all', 'actionable')),
    CONSTRAINT ck_alertas_email_config_frequencia CHECK (frequencia IN ('instant', 'hourly', 'daily')),
    CONSTRAINT fk_alertas_email_config_empresa FOREIGN KEY (empresa_id) REFERENCES empresa(id) ON DELETE CASCADE,
    CONSTRAINT fk_alertas_email_config_criado_por FOREIGN KEY (criado_por) REFERENCES usuario(id) ON DELETE SET NULL,
    CONSTRAINT fk_alertas_email_config_atualizado_por FOREIGN KEY (atualizado_por) REFERENCES usuario(id) ON DELETE SET NULL,
    INDEX ix_alertas_email_config_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alertas_email_destinatario (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    configuracao_id BIGINT NOT NULL,
    usuario_id BIGINT NULL,
    email VARCHAR(254) NULL,
    nome VARCHAR(180) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_alertas_email_dest_alvo CHECK ((usuario_id IS NOT NULL AND email IS NULL) OR (usuario_id IS NULL AND email IS NOT NULL)),
    CONSTRAINT fk_alertas_email_dest_config FOREIGN KEY (configuracao_id) REFERENCES alertas_email_configuracao(id) ON DELETE CASCADE,
    CONSTRAINT fk_alertas_email_dest_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
    UNIQUE KEY ux_alertas_email_dest_usuario (configuracao_id, usuario_id),
    UNIQUE KEY ux_alertas_email_dest_email (configuracao_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
