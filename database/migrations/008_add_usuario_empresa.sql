ALTER TABLE usuario
    ADD COLUMN IF NOT EXISTS empresa_id BIGINT REFERENCES empresa(id) ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS ix_usuario_empresa_ativo ON usuario (empresa_id, ativo);
