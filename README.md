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

## Migrations MySQL adicionais

`DB_CONNECTION` seleciona a conexao da aplicacao (`pgsql` por padrao ou `mysql`).
O PostgreSQL continua sendo o destino padrao das migrations sem argumento.
Para criar o schema tambem em um banco MySQL existente, preencha `MYSQL_HOST`,
`MYSQL_PORT`, `MYSQL_DATABASE`, `MYSQL_USER` e `MYSQL_PASSWORD` no `.env`.
Essas variaveis sao independentes de `DB_*`. O MySQL local usa a porta 3307.
Habilite a extensao PHP `pdo_mysql` e execute:

```powershell
& C:\xampp\php\php.exe database\migrate.php mysql
```

As migrations MySQL ficam em `database/migrations/mysql`, criam `usuario` e
`usuario_auditoria` e registram as versoes em `schema_migrations` no banco MySQL.
O executor usa um lock por banco para impedir execucoes simultaneas. Como DDL
MySQL faz commit implicito, cada arquivo deve conter uma unica instrucao
idempotente; em caso de falha, corrija a causa e execute novamente.
Este schema destina-se a um banco novo; tabelas existentes nao sao reconciliadas.
As migrations nao copiam dados nem alteram `DB_CONNECTION`.
O login e a sessao suportam MySQL; consultas administrativas com SQL especifico
de PostgreSQL ainda precisam de adaptacao antes de usar essas funcoes no MySQL.

```powershell
& C:\xampp\php\php.exe database\migrate.php pgsql
```

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
