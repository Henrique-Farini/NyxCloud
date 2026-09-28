CREATE TABLE IF NOT EXISTS alertas_preferencias (
    id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    dados JSONB NOT NULL DEFAULT '{}'::jsonb,
    atualizado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
