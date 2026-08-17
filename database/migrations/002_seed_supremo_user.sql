-- NyxCloud - seed adicional de autenticacao

CREATE EXTENSION IF NOT EXISTS pgcrypto;

INSERT INTO usuario (nome, email, senha_hash, ativo)
VALUES (
    'Supremo',
    'supremo@email.com',
    crypt('supremo123', gen_salt('bf', 12)),
    TRUE
)
ON CONFLICT ((LOWER(email))) DO UPDATE
SET
    nome = EXCLUDED.nome,
    senha_hash = EXCLUDED.senha_hash,
    ativo = TRUE,
    atualizado_em = NOW();
