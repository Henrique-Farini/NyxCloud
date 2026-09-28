ALTER TABLE usuario
    ADD COLUMN empresa_id BIGINT NULL,
    ADD KEY ix_usuario_empresa_ativo (empresa_id, ativo),
    ADD CONSTRAINT fk_usuario_empresa FOREIGN KEY (empresa_id) REFERENCES empresa(id) ON DELETE SET NULL;
