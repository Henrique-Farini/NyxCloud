<?php require_once __DIR__ . '/_auth_guard.php'; ?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Painel operacional NyxCloud para monitoramento de backups e protecao">
  <title>Painel Operacional - NyxCloud</title>
  <link rel="icon" href="../assets/img/fav.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles.css?v=20260901-integrations-1">
</head>
<body data-mode="dark">
  <a class="skip-link" href="#mainContent">Pular para o conteudo</a>
  <div class="console-frame">
    <aside class="rail" id="rail">
      <div class="rail-brand">
        <a href="../index.html" aria-label="NyxCloud">
          <img src="../assets/img/icone.png" alt="">
          <span>NYX<span>CLOUD</span></span>
        </a>
        <button id="railClose" type="button" aria-label="Fechar menu">&times;</button>
      </div>
      <div class="tenant-switch"><span class="tenant-mark">N</span><span><b>NyxCloud</b><small>Ambiente de producao</small></span><i data-lucide="chevrons-up-down"></i></div>
      <div class="rail-scroll">
        <nav class="rail-nav" aria-label="Navegacao do painel">
          <span class="rail-caption">Monitoramento</span>
          <a class="rail-item is-current" href="#overview" data-section="overview"><i data-lucide="layout-dashboard"></i><span>Visao geral</span></a>
          <a class="rail-item" href="#executions" data-section="executions"><i data-lucide="database-zap"></i><span>Execucoes</span><em>--</em></a>
          <a class="rail-item" href="#alerts" data-section="alerts"><i data-lucide="siren"></i><span>Alertas</span><em class="danger-count">--</em></a>
          <a class="rail-item" href="#storage" data-section="storage"><i data-lucide="hard-drive"></i><span>Armazenamento</span></a>
          <a class="rail-item" href="#summary" data-section="summary"><i data-lucide="calendar-clock"></i><span>Resumo</span></a>
          <a class="rail-item" href="#windows" data-section="windows"><i data-lucide="waypoints"></i><span>Janelas</span></a>
          <span class="rail-caption">Ambiente</span>
          <a class="rail-item" href="#clients" data-section="clients"><i data-lucide="building-2"></i><span>Clientes</span></a>
          <a class="rail-item" href="#accounts" data-section="accounts"><i data-lucide="users-round"></i><span>Contas</span></a>
          <a class="rail-item" href="#integrations" data-section="integrations"><i data-lucide="key-round"></i><span>Integrações</span></a>
          <a class="rail-item" href="#infrastructure" data-section="infrastructure"><i data-lucide="server-cog"></i><span>Infraestrutura</span></a>
          <a class="rail-item" href="#analytics" data-section="analytics"><i data-lucide="chart-no-axes-combined"></i><span>Analises</span></a>
          <a class="rail-item" href="#audit" data-section="audit"><i data-lucide="scroll-text"></i><span>Auditoria</span></a>
        </nav>
      </div>
      <div class="rail-bottom">
        <div class="platform-state"><span class="state-dot"></span><div><b>Painel conectado</b><small>Dados sincronizados com a Acronis</small></div></div>
        <button class="profile-strip" id="profileButton"><span class="profile-avatar" id="railProfileAvatar">?</span><span><b id="railProfileName">Carregando perfil</b><small id="railProfileRole">Validando sessao</small></span><i data-lucide="more-horizontal"></i></button>
      </div>
    </aside>
    <button class="rail-scrim" id="railScrim" type="button" aria-label="Fechar menu"></button>
    <main class="workspace" id="mainContent" tabindex="-1">
      <header class="command-bar">
        <div class="command-left"><button class="mobile-trigger" id="railOpen" aria-label="Abrir menu" aria-expanded="true"><i data-lucide="menu"></i></button><div class="crumb"><span>Painel</span><i data-lucide="chevron-right"></i><b id="sectionTitle">Visao geral</b></div></div>
        <div class="command-right"><span class="sync-status" id="syncStatus" role="status" aria-live="polite"><i data-lucide="refresh-cw"></i><span>Sincronizando</span></span><label class="global-search"><i data-lucide="search"></i><input id="globalSearch" type="search" placeholder="Buscar cliente ou maquina" aria-label="Pesquisar cliente ou maquina"></label><a class="command-icon" href="alertas.php" aria-label="Abrir central de alertas" title="Abrir central de alertas"><i data-lucide="bell"></i><span></span></a><button class="mode-switch" id="modeSwitch" type="button" aria-label="Alternar tema"><i data-lucide="sun"></i><span></span><i data-lucide="moon"></i></button><button class="command-user" id="commandUserButton" type="button"><span class="profile-avatar" id="commandProfileAvatar">?</span><span class="command-user-copy"><b id="commandProfileName">Carregando perfil</b><small id="commandProfileRole">Validando sessao</small></span><i data-lucide="chevron-down"></i></button></div>
      </header>
      <div class="workspace-body">
        <section class="section-intro" id="sectionIntro" hidden><div><span class="surface-eyebrow">AREA OPERACIONAL</span><h1 id="sectionIntroTitle">Secao</h1><p id="sectionIntroText"></p></div><span class="section-updated"><i data-lucide="database"></i><span id="sectionUpdated">Dados da Acronis</span></span></section>
        <section class="hero-row" data-panel-section="overview"><div><div class="section-kicker" id="operationDate"><span class="pulse"></span> OPERACAO AO VIVO</div><h1>Visao operacional de protecao</h1><p>Leitura rapida de clientes, maquinas, backups, alertas e capacidade consumida.</p></div><div class="hero-actions"><button class="outline-action" type="button" data-toast="Periodo analisado: ultimos 30 dias"><i data-lucide="calendar-days"></i> Ultimos 30 dias</button><button class="solid-action" type="button" id="refreshDashboard"><i data-lucide="refresh-cw"></i><span>Atualizar dados</span></button></div></section>
        <section class="signal-band" data-panel-section="overview"><div class="signal-main"><span class="signal-icon"><i data-lucide="shield-check"></i></span><div><b id="signalTitle">Protecao monitorada</b><span id="signalText">Resumo consolidado da operacao de backup e do ambiente protegido.</span></div></div><div class="signal-metrics"><span><b id="metricSuccess">--</b><small>Taxa de sucesso</small></span><span><b id="metricClients">--</b><small>Clientes monitorados</small></span><span><b id="metricDevices">--</b><small>Dispositivos protegidos</small></span></div></section>
        <section class="kpi-layout" aria-label="Indicadores principais" data-panel-section="overview">
          <article class="kpi-tile"><div class="kpi-heading"><span class="kpi-glyph violet"><i data-lucide="database-backup"></i></span><span class="trend up" id="trendA">AO VIVO</span></div><span class="kpi-name">Backups concluidos</span><strong class="kpi-number">--</strong><small id="kpiHintA">Carregando dados da Acronis...</small><div class="micro-chart" id="microA"></div></article>
          <article class="kpi-tile"><div class="kpi-heading"><span class="kpi-glyph cyan"><i data-lucide="database"></i></span><span class="trend up" id="trendB">TOTAL</span></div><span class="kpi-name">Backups processados</span><strong class="kpi-number">--</strong><small id="kpiHintB">Carregando dados da Acronis...</small><div class="micro-chart" id="microB"></div></article>
          <article class="kpi-tile"><div class="kpi-heading"><span class="kpi-glyph amber"><i data-lucide="triangle-alert"></i></span><span class="trend warn" id="trendC">ATENCAO</span></div><span class="kpi-name">Backups com falha</span><strong class="kpi-number">--</strong><small id="kpiHintC">Carregando dados da Acronis...</small><div class="micro-chart" id="microC"></div></article>
          <article class="kpi-tile"><div class="kpi-heading"><span class="kpi-glyph teal"><i data-lucide="cloud"></i></span><span class="trend up" id="trendD">CAPACIDADE</span></div><span class="kpi-name">Espaco protegido</span><strong class="kpi-number">--</strong><small id="kpiHintD">Carregando dados da Acronis...</small><div class="micro-chart" id="microD"></div></article>
        </section>
        <section class="visual-grid" data-section-container>
          <article class="surface status-surface" data-panel-section="analytics" data-panel-extra-sections="overview"><div class="surface-head"><div><span class="surface-eyebrow">SAUDE DOS BACKUPS</span><h2>Resultado das execucoes</h2><small class="surface-note">Tarefas concluidas nos ultimos 30 dias</small></div><button class="surface-menu" data-toast="Visao resumida dos status"><i data-lucide="sliders-horizontal"></i></button></div><div id="statusDonut" class="main-chart"></div><div class="surface-foot"><span><i class="legend-green"></i> Sucesso <b id="legendSuccess">0%</b></span><span><i class="legend-red"></i> Falha <b id="legendFailed">0%</b></span><span><i class="legend-gray"></i> Outros <b id="legendOther">0%</b></span></div></article>
          <article class="surface timeline-surface" data-panel-section="analytics" data-panel-extra-sections="overview"><div class="surface-head"><div><span class="surface-eyebrow">VOLUME REAL</span><h2>Execucoes por dia</h2><small class="surface-note">Ultimos 21 dias disponiveis na Acronis</small></div><div class="chart-legend"><span><i class="legend-violet"></i>Total</span></div></div><div id="executionLine" class="main-chart"></div></article>
          <article class="surface storage-surface" id="storage" data-panel-section="storage" data-panel-extra-sections="overview"><div class="surface-head"><div><span class="surface-eyebrow">CAPACIDADE</span><h2>Uso do armazenamento</h2></div><button class="surface-menu" data-toast="Capacidade somada do ambiente"><i data-lucide="ellipsis"></i></button></div><div id="storageRadial" class="main-chart"></div><div class="capacity-row"><span><i class="legend-violet"></i> Em uso <b id="storageUsed">--</b></span><span><i class="legend-blue"></i> Fonte <b id="storageFree">--</b></span></div><div class="storage-client-list" id="storageClientList"><small>Carregando clientes...</small></div></article>
          <article class="surface history-surface" data-panel-section="analytics" data-panel-extra-sections="overview"><div class="surface-head"><div><span class="surface-eyebrow">HISTORICO REAL</span><h2>Desempenho diario dos backups</h2><small class="surface-note">Grafico: ultimos 21 dias. Tabela: historico completo disponivel.</small></div><span class="health-badge"><i></i> Acronis</span></div><div class="daily-overview"><div><small>Hoje</small><b id="dailyToday">--</b><span>execucoes</span></div><div><small>Media diaria</small><b id="dailyAverage">--</b><span>execucoes</span></div><div><small>Taxa de sucesso</small><b id="dailySuccessRate">--</b><span>no periodo</span></div><div><small>Volume processado</small><b id="dailyVolume">--</b><span>no periodo</span></div></div><div id="historyArea" class="wide-chart"></div><div class="daily-table-wrap"><table class="daily-table"><thead><tr><th>Dia realizado</th><th>Total</th><th>Sucesso</th><th>Falhas</th><th>Tamanho processado</th></tr></thead><tbody id="dailyHistoryRows"><tr><td colspan="5">Carregando historico diario...</td></tr></tbody></table></div></article>
          <article class="surface client-surface" data-panel-section="clients" data-panel-extra-sections="overview"><div class="surface-head"><div><span class="surface-eyebrow">CLIENTES COM MAIS CARGA</span><h2>Dispositivos por cliente</h2></div><button class="surface-menu" data-toast="Top clientes por quantidade de dispositivos"><i data-lucide="ellipsis"></i></button></div><div id="clientBar" class="main-chart"></div></article>
        </section>
        <section class="data-grid data-grid-single" data-panel-section="executions">
          <article class="surface table-surface" id="recent"><div class="surface-head"><div><span class="surface-eyebrow">MAQUINAS RECENTES</span><h2>Ultimos dispositivos com atividade</h2></div><button class="text-action" data-toast="Leitura resumida das maquinas mais recentes">Atualizado agora <i data-lucide="arrow-up-right"></i></button></div><div class="data-scroll"><table><thead><tr><th>Cliente</th><th>Hostname</th><th>Plano</th><th>Ultimo backup</th><th>Status</th><th>Dias</th><th>Tamanho</th><th>IP</th></tr></thead><tbody><tr><td><b>Carregando</b><small>Aguardando API</small></td><td>--</td><td>--</td><td class="mono">--</td><td><em class="job-state queued">Aguardando</em></td><td>--</td><td>--</td><td>--</td></tr></tbody></table></div></article>
        </section>
        <section class="surface daily-devices-surface" id="daily-devices" data-panel-section="infrastructure"><div class="surface-head"><div><span class="surface-eyebrow">EXECUCOES POR DISPOSITIVO</span><h2>Hoje e ontem em ordem alfabetica</h2><small class="surface-note">Uma linha por dispositivo e plano, usando a ultima execucao de cada dia</small></div><span class="health-badge"><i></i> Atualizacao automatica</span></div><div class="daily-device-columns"><article><div class="daily-device-head"><div><small>HOJE</small><b id="todayExecutionDate">--</b></div><strong id="todayExecutionCount">--</strong></div><div class="daily-device-list" id="todayExecutionRows"><div class="daily-device-empty">Carregando execucoes de hoje...</div></div></article><article><div class="daily-device-head"><div><small>ONTEM</small><b id="yesterdayExecutionDate">--</b></div><strong id="yesterdayExecutionCount">--</strong></div><div class="daily-device-list" id="yesterdayExecutionRows"><div class="daily-device-empty">Carregando execucoes de ontem...</div></div></article></div></section>
        <section class="immersive-alerts" data-panel-section="alerts"><iframe src="alertas.php?inside=1" title="Central de alertas" loading="lazy"></iframe></section>
        <section class="accounts-grid" data-panel-section="accounts">
          <article class="surface accounts-surface">
            <div class="surface-head">
              <div>
                <span class="surface-eyebrow">GESTAO DE ACESSO</span>
                <h2>Contas do painel</h2>
                <small class="surface-note">Crie acessos para administradores, operadores ou usuarios com somente leitura.</small>
              </div>
              <span class="health-badge"><i></i> Controle interno</span>
            </div>
            <div id="accountsAccessNotice" class="accounts-empty" hidden>Voce nao tem permissao para gerenciar contas.</div>
            <div class="data-scroll accounts-table-wrap">
              <table class="accounts-table">
                <thead>
                  <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Ultimo login</th><th>Criado em</th><th>Acoes</th></tr>
                </thead>
                <tbody id="accountsRows">
                  <tr><td colspan="7">Carregando contas...</td></tr>
                </tbody>
              </table>
            </div>
          </article>
          <article class="surface account-form-surface">
            <div class="surface-head">
              <div>
                <span class="surface-eyebrow">NOVA CONTA</span>
                <h2>Criar usuario com permissao menor</h2>
                <small class="surface-note">Use "Operador" para rotinas do dia a dia e "Somente leitura" para consulta do painel.</small>
              </div>
            </div>
            <form id="accountForm" class="account-form">
              <label><span>Nome</span><input type="text" name="nome" minlength="3" maxlength="150" placeholder="Ex.: Equipe Operacional" required></label>
              <label><span>E-mail</span><input type="email" name="email" placeholder="usuario@empresa.com" required></label>
              <label><span>Perfil</span><select name="perfil" id="accountRoleSelect"><option value="leitura">Somente leitura</option><option value="operador">Operador</option><option value="admin">Administrador</option></select></label>
              <label><span>Senha inicial</span><input type="password" name="senha" minlength="8" placeholder="Minimo de 8 caracteres" required></label>
              <label class="account-checkbox"><input type="checkbox" name="ativo" checked><span>Conta ativa ao criar</span></label>
              <div class="account-permission-hint" id="accountPermissionHint">Somente leitura: visualiza indicadores sem acesso administrativo.</div>
              <button class="solid-action" type="submit" id="accountSubmitButton"><i data-lucide="user-plus"></i><span>Criar conta</span></button>
              <p class="account-form-message" id="accountFormMessage" aria-live="polite"></p>
            </form>
          </article>
        </section>
        <section class="accounts-grid integrations-grid" data-panel-section="integrations">
          <article class="surface accounts-surface">
            <div class="surface-head">
              <div>
                <span class="surface-eyebrow">ACRONIS API</span>
                <h2>Integrações cadastradas</h2>
                <small class="surface-note">Escolha qual ambiente o painel usa para buscar dados.</small>
              </div>
              <span class="health-badge"><i></i> Secret protegido</span>
            </div>
            <div id="integrationsAccessNotice" class="accounts-empty" hidden>Voce nao tem permissao para gerenciar integrações.</div>
            <div class="integration-list" id="integrationRows">
              <div class="accounts-empty">Carregando integrações...</div>
            </div>
          </article>
          <article class="surface account-form-surface">
            <div class="surface-head">
              <div>
                <span class="surface-eyebrow">NOVA INTEGRAÇÃO</span>
                <h2>Acronis BR ou US</h2>
                <small class="surface-note">O secret fica salvo criptografado. Depois de ativar, o cache da Acronis é limpo.</small>
              </div>
            </div>
            <form id="integrationForm" class="account-form">
              <input type="hidden" name="id">
              <label><span>Nome</span><input type="text" name="name" minlength="2" maxlength="80" placeholder="Ex.: Acronis US" required></label>
              <label><span>Região</span><select name="region"><option value="BR">BR</option><option value="US">US</option><option value="EU">EU</option><option value="OTHER">Outro</option></select></label>
              <label><span>URL base</span><input type="url" name="base_url" placeholder="https://br-cloud.acronis.com" required></label>
              <label><span>Client ID</span><input type="text" name="client_id" autocomplete="off" required></label>
              <label><span>Client secret</span><input type="password" name="client_secret" autocomplete="new-password" placeholder="Obrigatório ao criar. Vazio mantém atual."></label>
              <label class="account-checkbox"><input type="checkbox" name="active" checked><span>Ativar após salvar</span></label>
              <button class="solid-action" type="submit" id="integrationSubmitButton"><i data-lucide="save"></i><span>Salvar integração</span></button>
              <p class="account-form-message" id="integrationFormMessage" aria-live="polite"></p>
            </form>
          </article>
        </section>
        <section class="surface audit-surface" data-panel-section="audit">
          <div class="surface-head">
            <div>
              <span class="surface-eyebrow">TRILHA ADMINISTRATIVA</span>
              <h2>Auditoria de contas</h2>
              <small class="surface-note">Eventos de criacao, edicao, troca de perfil, status e senha.</small>
            </div>
            <span class="health-badge"><i></i> Somente administradores</span>
          </div>
          <div class="data-scroll audit-table-wrap">
            <table class="audit-table">
              <thead>
                <tr><th>Data</th><th>Acao</th><th>Ator</th><th>Alvo</th><th>IP</th><th>Detalhes</th></tr>
              </thead>
              <tbody id="auditRows">
                <tr><td colspan="6">Carregando auditoria...</td></tr>
              </tbody>
            </table>
          </div>
        </section>
        <section class="bottom-grid" data-section-container><article class="surface schedule-surface" id="schedules" data-panel-section="summary" data-panel-extra-sections="overview"><div class="surface-head"><div><span class="surface-eyebrow">RESUMO EXECUTIVO</span><h2>Leitura operacional rapida</h2></div><button class="text-action" data-toast="Resumo atualizado com os dados atuais">Atualizar leitura <i data-lucide="arrow-up-right"></i></button></div><div class="schedule-list" id="summaryList"><div><time>OK</time><b>Backups com sucesso</b><span>Execucoes concluidas sem erro</span><em id="summarySuccess">--</em></div><div><time>ALR</time><b>Alertas ativos</b><span>Itens que exigem verificacao</span><em id="summaryAlerts">--</em></div><div><time>CLI</time><b>Clientes</b><span>Empresas visiveis no tenant</span><em id="summaryClients">--</em></div><div><time>DSP</time><b>Dispositivos</b><span>Maquinas com protecao mapeada</span><em id="summaryDevices">--</em></div></div></article><article class="surface fleet-surface" id="clients" data-panel-section="clients" data-panel-extra-sections="overview"><div class="surface-head"><div><span class="surface-eyebrow">AMBIENTE PROTEGIDO</span><h2>Panorama do ambiente</h2></div><span class="health-badge"><i></i> Operacional</span></div><div class="fleet-stats"><div><span>Clientes</span><b id="fleetClients">--</b></div><div><span>Dispositivos</span><b id="fleetDevices">--</b></div><div><span>Backups</span><b id="fleetBackups">--</b></div><div><span>Falhas</span><b id="fleetFailures">--</b></div><div><span>Sucesso</span><b id="fleetSuccess">--</b></div><div><span>Espaco</span><b id="fleetStorage">--</b></div></div></article></section>
        <section class="surface windows-surface" id="windows" data-panel-section="windows"><div class="surface-head"><div><span class="surface-eyebrow">JANELAS DE EXECUCAO</span><h2>Meta x realizado por empresa e plano</h2></div><div class="window-actions"><button class="text-action" type="button" id="manageWindowRules" hidden><i data-lucide="settings-2"></i> Editar regras</button><button class="text-action" data-toast="Comparativo baseado nas execucoes reais da Acronis">Dados reais <i data-lucide="arrow-up-right"></i></button></div></div><div class="windows-toolbar"><label class="windows-search"><i data-lucide="search"></i><input type="search" id="windowsSearch" placeholder="Pesquisar cliente na aba Janelas" aria-label="Pesquisar cliente nas janelas"></label></div><div class="windows-summary" id="windowsSummary">Carregando monitoramento por janela...</div><div class="windows-scroll"><div class="windows-list" id="windowsList"><div class="window-card is-empty"><div><b>Carregando monitoramento</b><small>Aguardando retorno da API</small></div></div></div></div></section>
        <footer class="console-footer"><span>Painel Operacional NyxCloud - Ambiente conectado</span><span>v2.5.0 - <a href="../back/index.php">Acessar login</a></span></footer>
      </div>
    </main>
  </div>
  <dialog class="window-rules-dialog" id="windowRulesDialog">
    <form method="dialog" class="window-rules-modal">
      <div class="surface-head">
        <div>
          <span class="surface-eyebrow">REGRAS VERSIONADAS</span>
          <h2>Editar janelas de backup</h2>
          <small class="surface-note" id="windowRulesMeta">Carregando regras...</small>
        </div>
        <button type="submit" class="surface-menu" aria-label="Fechar"><i data-lucide="x"></i></button>
      </div>
      <label class="window-rules-field"><span>JSON das regras</span><textarea id="windowRulesEditor" spellcheck="false"></textarea></label>
      <p class="account-form-message" id="windowRulesMessage" aria-live="polite"></p>
      <div class="window-rules-actions">
        <button class="outline-action" type="button" id="reloadWindowRules"><i data-lucide="rotate-ccw"></i><span>Recarregar</span></button>
        <button class="solid-action" type="button" id="saveWindowRules"><i data-lucide="save"></i><span>Salvar regras</span></button>
      </div>
    </form>
  </dialog>
  <div id="toast" class="console-toast" role="status" aria-live="polite"></div>
  <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1"></script>
  <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
<script src="app.js?v=20260901-integrations-1"></script>
</body>
</html>
