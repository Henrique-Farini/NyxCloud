ALTER TABLE usuario
    ADD COLUMN administrador_geral BOOLEAN NOT NULL DEFAULT FALSE AFTER perfil;

UPDATE usuario u
LEFT JOIN usuario_empresa ue ON ue.usuario_id = u.id
SET u.administrador_geral = TRUE
WHERE u.perfil = 'admin' AND ue.usuario_id IS NULL;
