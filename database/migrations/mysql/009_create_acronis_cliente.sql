CREATE TABLE IF NOT EXISTS acronis_cliente (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    empresa_id BIGINT NOT NULL,
    conta_acronis_id VARCHAR(191) NOT NULL,
    tenant_acronis_id VARCHAR(191) NOT NULL,
    nome VARCHAR(255) NOT NULL,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY ux_acronis_cliente_tenant (conta_acronis_id, tenant_acronis_id),
    KEY ix_acronis_cliente_empresa_ativo (empresa_id, ativo),
    CONSTRAINT fk_acronis_cliente_empresa FOREIGN KEY (empresa_id) REFERENCES empresa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
