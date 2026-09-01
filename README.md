# NyxCloud — autenticação

## Configuração

1. Crie o banco PostgreSQL indicado por `DB_NAME` (ou `DB_DATABASE`).
2. Copie `.env.example` para `.env` e preencha `DB_PASSWORD` apenas localmente.
3. Gere um `JWT_SECRET` aleatório com pelo menos 32 caracteres.
4. Garanta que PHP tenha `pdo_pgsql` habilitado.
5. Execute as migrations sempre que publicar uma alteração de schema:

```bash
php database/migrate.php
```

O executor cria `schema_migrations`, aplica os arquivos SQL em ordem alfabética,
registra cada versão e usa um lock do PostgreSQL para evitar duas execuções
simultâneas. Ele é idempotente: executar novamente aplica somente migrations novas.

No PowerShell com o PHP do XAMPP:

```powershell
& C:\xampp\php\php.exe database\migrate.php
```

As variáveis `DB_DATABASE`/`DB_USERNAME` também são aceitas como aliases de
`DB_NAME`/`DB_USER`, facilitando uma futura migração para o scaffold Laravel.

## Rotas

- `POST /back/api/login.php`: recebe `email` e `senha`, retorna JWT e cria cookie HttpOnly.
- `GET /back/api/me.php`: retorna usuário autenticado. Aceita `Authorization: Bearer <token>` ou cookie JWT.
- `GET /back/painel.php`: página protegida pelo middleware JWT.
- `GET /back/logout.php`: remove cookie JWT.

## Executar localmente

O projeto usa PHP. Não abra `index.html` diretamente pelo Explorer nem pelo Live Server, pois esses modos não interpretam arquivos `.php` e o navegador pode baixá-los.

Na raiz do projeto, execute:

```powershell
& C:\xampp\php\php.exe -S 127.0.0.1:8000 -t .
```

Depois acesse <http://127.0.0.1:8000/index.html> e clique em **Login**. A página será carregada por `back/index.php` e o formulário usará `back/api/login.php`.

Conta inicial:

```powershell
& C:\xampp\php\php.exe tools\upsert_henriquewt.php
```

As migrations não criam mais credenciais padrão. Em produção, crie o primeiro
administrador por script operacional seguro ou diretamente no banco, depois use
a aba **Contas** para manter usuários e a aba **Auditoria** para revisar mudanças.
