# NyxCloud — contexto do projeto para agentes de IA

## Objetivo do sistema

O NyxCloud é um painel web em PHP para acompanhar ambientes de backup da Acronis.
O sistema possui autenticação, usuários internos, empresas/clientes, dispositivos,
planos de proteção, execuções, alertas, janelas de execução, integrações e auditoria
administrativa.

O painel deve ser simples para pessoas com pouca familiaridade técnica, mas sem
esconder informações importantes para administradores.

## Estrutura principal

- `painel-demo/`: interface protegida do painel. `index.php` é o painel principal;
  `app.js` concentra a montagem e interação das seções; `styles.css` contém os
  estilos compartilhados; `alertas.php`, `alertas.js` e `alertas-react.js` cuidam
  da área de alertas.
- `back/`: páginas e endpoints HTTP, autenticação, middleware e APIs consumidas
  pelo frontend.
- `back/auth/`: JWT, middleware e regras de sessão.
- `back/api/`: endpoints de login, logout, usuário atual, contas, auditoria,
  credenciais Acronis, alertas e janelas de execução.
- `app/Services/`: regras de negócio e integração com a Acronis. Alterações de
  dados operacionais devem preferencialmente ficar aqui, não duplicadas no JS.
- `database/migrations/`: migrations PostgreSQL e migrations adicionais para MySQL.
- `.env.example`: referência das variáveis locais. Segredos reais ficam somente no
  `.env`, nunca no código ou em commits.

## Regras de negócio importantes

### Dados da Acronis

- Nunca inventar, estimar silenciosamente ou apresentar como atual um dado que a
  Acronis não retornou.
- Quando não houver informação suficiente, usar `--`, “Sem histórico” ou uma
  mensagem equivalente claramente identificada.
- Não mostrar dados antigos como se fossem atuais. Se houver cache ou atualização
  em andamento, deixar isso explícito na interface.
- A lista de clientes deve priorizar informações de gestão: dispositivos, planos,
  ritmo diário de execuções, status e ações. Evitar repetir na lista principal
  último backup, origem e volume quando essas informações já estão disponíveis em
  “Ver atividades”.
- O ritmo diário deve ser calculado com execuções reais retornadas pela integração.
  Não deduzir a quantidade de execuções pela quantidade de planos.
- A mediana de volume atualmente é uma referência histórica por dispositivo e
  plano. Não descrevê-la como uma mediana específica de horário sem implementar
  essa regra de forma explícita.
- Contagens de alertas, filtros, período, deduplicação e preferências de visibilidade
  devem usar a mesma fonte e critérios para evitar números diferentes entre telas.

### Usuários e permissões

Existem três perfis operacionais:

- **Administrador**: acesso amplo conforme as permissões atribuídas.
- **Operador**: consulta e rotinas operacionais permitidas, sem acesso aos usuários
  administradores ou às áreas exclusivas de administração geral.
- **Somente leitura**: consulta indicadores, clientes, alertas e relatórios, sem
  alterações sensíveis.

Permissões devem ser verificadas por ação e no backend, não apenas ocultando
elementos do frontend. Exemplos de ações: visualizar alertas, alterar preferências,
criar usuário, alterar senha, alterar integração, visualizar auditoria e editar
janelas de execução.

A aba **Administração** é exclusiva do administrador geral e reúne usuários,
empresas, integrações, auditoria, sessões, saúde da Acronis e diagnósticos técnicos.
Operadores não devem enxergar contas administradoras.

A aba **Notificações** é exclusiva do perfil administrador (`alerts.notifications`).
O administrador geral configura apenas o recebimento dos alertas gerais do ambiente;
não escolhe uma empresa. O administrador vinculado a uma empresa configura somente
o próprio escopo, sem interferir nas demais empresas. Destinatários de conta devem
ser validados contra o escopo no backend; e-mails externos precisam ser validados e
as alterações devem gerar auditoria. Não exibir histórico de envios nessa aba.

### Segurança

- Preservar CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` e
  `Permissions-Policy`.
- Em produção, cookies devem usar `Secure`, `HttpOnly` e `SameSite=Lax` ou `Strict`.
- JWT deve ter expiração curta; o modo “lembrar de mim” pode ter prazo maior e
  precisa continuar claramente separado da sessão normal.
- Logout deve invalidar/remover corretamente a sessão ou o token.
- Operações que alteram dados precisam de proteção CSRF e verificação de permissão:
  usuários, contas Acronis, janelas, configurações, exclusões e preferências.
- Nunca exibir ou registrar client secret, senha, JWT ou conteúdo sensível de
  credenciais nos logs, respostas ou telas.
- Ações administrativas relevantes devem gerar auditoria com responsável, data,
  IP, ação e resultado.

## Padrões de implementação

- Preservar o comportamento atual antes de ampliar funcionalidades.
- Fazer mudanças pequenas, estruturadas e compatíveis com instalações existentes.
- Preferir funções e regras compartilhadas a duplicação entre telas.
- Manter a interface em português, com textos claros e sem jargão desnecessário.
- Garantir layout responsivo, principalmente para celular; textos não devem ficar
  cortados ou depender apenas de tooltip.
- Ao alterar arquivos JS ou CSS carregados por PHP, atualizar o versionamento de
  cache (`?v=...`) quando necessário.
- Ao alterar schema, criar uma migration nova. Não editar uma migration já aplicada
  para corrigir dados existentes.
- Não alterar ou apagar mudanças existentes do usuário sem autorização explícita.
- Não usar `git reset --hard`, `git checkout --` ou exclusões amplas.

## Fluxo recomendado para qualquer tarefa

1. Ler o código relacionado e verificar as regras existentes antes de editar.
2. Identificar se a mudança afeta frontend, endpoint, serviço, banco, permissões ou
   mais de uma camada.
3. Implementar a menor alteração completa que resolva o problema, mantendo o fluxo
   atual funcionando.
4. Validar PHP com `php -l` nos arquivos alterados e executar `git diff --check`.
5. Se houver alteração de banco, revisar a migration e explicar como aplicá-la.
6. Informar no final o que foi alterado, quais validações foram feitas e qualquer
   ponto que ainda precise de teste manual.

## Banco de dados atual

O ambiente atual do projeto usa **MySQL** como conexão padrão, definido por
`DB_CONNECTION=mysql` no `.env`. O PostgreSQL continua suportado como alternativa
compatível, mas não deve ser tratado como o banco padrão deste ambiente.

Ao alterar código que acessa o banco, manter compatibilidade com os dois drivers
quando a funcionalidade já possuir esse suporte. Consultas, tipos JSON, `RETURNING`,
datas e migrations podem ter diferenças entre MySQL e PostgreSQL; verificar o driver
real antes de aplicar uma correção.

## Comandos locais

Use o PHP do XAMPP no PowerShell:

```powershell
& C:\xampp\php\php.exe -S 127.0.0.1:8000 -t .
```

Para migrations MySQL, que são as usadas pelo ambiente atual:

```powershell
& C:\xampp\php\php.exe database\migrate.php mysql
```

Para migrations PostgreSQL, somente quando essa conexão for configurada:

```powershell
& C:\xampp\php\php.exe database\migrate.php pgsql
```

O driver usado pela aplicação é definido por `DB_CONNECTION`. Não alterar essa
variável automaticamente durante uma migration ou correção de código.
