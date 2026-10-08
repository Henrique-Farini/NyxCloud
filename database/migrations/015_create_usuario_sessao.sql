CREATE TABLE IF NOT EXISTS usuario_sessao (
    id VARCHAR(64) PRIMARY KEY,
    usuario_id INTEGER NOT NULL,
    jti VARCHAR(64) NOT NULL UNIQUE,
    ip VARCHAR(64),
    user_agent VARCHAR(500),
    criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ultimo_uso_em TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expira_em TIMESTAMPTZ NOT NULL,
    revogado_em TIMESTAMPTZ NULL
);

CREATE INDEX IF NOT EXISTS ix_usuario_sessao_usuario ON usuario_sessao (usuario_id);
CREATE INDEX IF NOT EXISTS ix_usuario_sessao_ativo ON usuario_sessao (revogado_em, expira_em);
