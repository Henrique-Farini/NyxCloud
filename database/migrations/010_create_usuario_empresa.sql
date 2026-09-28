CREATE TABLE IF NOT EXISTS usuario_empresa (
    usuario_id BIGINT NOT NULL REFERENCES usuario(id) ON DELETE CASCADE,
    empresa_id BIGINT NOT NULL REFERENCES empresa(id) ON DELETE CASCADE,
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (usuario_id, empresa_id)
);

CREATE INDEX IF NOT EXISTS ix_usuario_empresa_empresa ON usuario_empresa (empresa_id, usuario_id);
