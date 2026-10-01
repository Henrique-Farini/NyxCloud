<?php
require_once __DIR__ . '/_auth_guard.php';
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$cleanAlertsRoute = preg_match('#/alertas(?:/|$)#i', $requestPath) === 1;
$alertsAssetPrefix = $cleanAlertsRoute ? '../painel-demo/' : '';
$alertsPanelPath = $cleanAlertsRoute ? '../painel/' : 'index.php';
$alertsSelfPath = $cleanAlertsRoute ? '../alertas/' : 'alertas.php';
$podeGerenciarAlertas = normalizarPerfil((string) ($usuarioPainel['perfil'] ?? '')) !== 'leitura';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Central de alertas operacionais dos backups NyxCloud">
  <title>Alertas de Backups - NyxCloud</title>
  <link rel="icon" href="../assets/img/fav.ico">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= htmlspecialchars($alertsAssetPrefix, ENT_QUOTES, 'UTF-8') ?>styles.css?v=20261001-page-transition-1">
  <link rel="stylesheet" href="<?= htmlspecialchars($alertsAssetPrefix, ENT_QUOTES, 'UTF-8') ?>alertas.css?v=20261001-panel-aligned-1">
</head>
<body data-mode="dark">
  <a class="skip-link" href="#alert-events">Pular para os alertas</a>
  <div class="console-frame">
    <aside class="rail" id="rail">
      <div class="rail-brand">
        <a href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>" aria-label="NyxCloud"><img src="../assets/img/icone.png" alt=""><span>NYX<span>CLOUD</span></span></a>
        <button id="railClose" type="button" aria-label="Fechar menu">&times;</button>
      </div>
      <div class="tenant-switch"><span class="tenant-mark">N</span><span><b>NyxCloud</b><small>Ambiente de producao</small></span><i data-lucide="chevrons-up-down"></i></div>
      <div class="rail-scroll">
        <nav class="rail-nav" aria-label="Navegação do painel">
          <span class="rail-caption">Monitoramento</span>
          <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#overview"><i data-lucide="layout-dashboard"></i><span>Visão geral</span></a>
          <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#executions"><i data-lucide="database-zap"></i><span>Atividade recente</span><em>--</em></a>
          <a class="rail-item is-current" href="<?= htmlspecialchars($alertsSelfPath, ENT_QUOTES, 'UTF-8') ?>"><i data-lucide="siren"></i><span>Alertas</span><em class="danger-count">--</em></a>
          <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#storage"><i data-lucide="hard-drive"></i><span>Armazenamento</span></a>
          <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#schedules"><i data-lucide="calendar-clock"></i><span>Resumo</span></a>
          <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#windows"><i data-lucide="waypoints"></i><span>Janelas de execução</span></a>
          <span class="rail-caption">Ambiente</span>
          <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#clients"><i data-lucide="building-2"></i><span>Clientes</span></a>
           <?php if (usuarioPodeGerenciarContas($usuarioPainel)): ?>
           <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#accounts"><i data-lucide="users-round"></i><span>Contas</span></a>
           <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#integrations"><i data-lucide="key-round"></i><span>Integrações</span></a>
           <?php endif; ?>
          <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#infrastructure"><i data-lucide="server-cog"></i><span>Execuções por dispositivo</span></a>
          <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#analytics"><i data-lucide="chart-no-axes-combined"></i><span>Análises</span></a>
           <?php if (usuarioPodeGerenciarContas($usuarioPainel)): ?><a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#audit"><i data-lucide="scroll-text"></i><span>Auditoria administrativa</span></a><?php endif; ?>
          <span class="rail-caption">Usuário</span>
          <a class="rail-item" href="<?= htmlspecialchars($alertsPanelPath, ENT_QUOTES, 'UTF-8') ?>#profile"><i data-lucide="circle-user-round"></i><span>Meu perfil</span></a>
        </nav>
      </div>
      <div class="rail-bottom">
        <div class="platform-state"><span class="state-dot"></span><div><b>Painel conectado</b><small>Dados sincronizados com a Acronis</small></div></div>
      </div>
    </aside>

    <button class="rail-scrim" id="railScrim" type="button" aria-label="Fechar menu"></button>

    <main class="workspace" id="mainContent" tabindex="-1">
      <header class="command-bar">
        <div class="command-left"><button class="mobile-trigger" id="railOpen" aria-label="Abrir menu" aria-expanded="true"><i data-lucide="menu"></i></button><div class="crumb"><span>Painel</span><i data-lucide="chevron-right"></i><b>Alertas</b></div></div>
        <div class="command-right">
          <span class="sync-status" id="alertsSyncStatus" role="status" aria-live="polite"><i data-lucide="refresh-cw"></i><span>Sincronizando</span></span>
          <label class="global-search"><i data-lucide="search"></i><input id="globalAlertSearch" type="search" placeholder="Buscar cliente, dispositivo ou erro" aria-label="Pesquisar alertas"></label>
          <a class="command-icon" href="#alert-events" aria-label="Ir para lista de alertas" title="Ir para lista de alertas"><i data-lucide="bell"></i><span></span></a>
          <button class="mode-switch" id="modeSwitch" type="button" aria-label="Alternar tema"><i data-lucide="sun"></i><span></span><i data-lucide="moon"></i></button>
          <button class="command-user" id="commandProfileButton" type="button"><span class="profile-avatar" id="alertsCommandProfileAvatar">U</span><span class="command-user-copy"><b id="alertsCommandProfileName">Usuario</b><small id="alertsCommandProfileRole">Carregando perfil</small></span><i data-lucide="chevron-down"></i></button>
        </div>
      </header>

      <div class="alerts-body">
        <section class="alerts-heading">
          <div><div class="section-kicker"><span class="pulse"></span> CENTRAL DE ALERTAS · AO VIVO</div><h1>Alertas</h1><p>Identifique, priorize e trate ocorrências de backup em um único lugar.</p></div>
          <label class="range-picker"><i data-lucide="calendar-days"></i><select id="periodFilter" aria-label="Período"><option value="today">Hoje</option><option value="yesterday">Ontem</option><option value="7">Últimos 7 dias</option><option value="30" selected>Últimos 30 dias</option></select><i data-lucide="chevron-down"></i></label>
        </section>

        <nav class="alert-view-tabs" aria-label="Visualização da central de alertas">
          <button type="button" class="is-active" data-alert-tab="events" aria-selected="true"><i data-lucide="bell"></i> Alertas</button>
          <?php if ($podeGerenciarAlertas): ?><button type="button" data-alert-tab="visibility" aria-selected="false"><i data-lucide="network"></i> Dispositivos e planos</button><?php endif; ?>
        </nav>

        <section class="alert-visibility-panel" id="alertVisibilityPanel" hidden>
          <div class="visibility-panel-head"><div><span class="surface-eyebrow">CONFIGURAÇÃO DE EXIBIÇÃO</span><h2>Escolha o que deve gerar alertas</h2><p>Selecione clientes, máquinas, planos e os tipos de alerta de cada máquina.</p></div><div class="visibility-panel-actions"><label class="visibility-category-filter"><span>Ver categoria</span><select id="visibilityCategoryFilter"><option value="all">Todas as categorias</option><option value="failedbackup">Falha de backup</option><option value="missing">Não executou</option><option value="nofiles">Sem arquivos</option><option value="offline">Máquina offline</option><option value="size">Tamanho anormal</option><option value="backup">Outros de backup</option></select></label><button class="apply-filter" id="applyAlertVisibility" type="button"><i data-lucide="check"></i> Salvar seleção</button></div></div>
          <div class="alert-scope-tree" id="alertScopeOptions"></div>
          <div class="visibility-panel-foot"><span>Itens desmarcados ficam fora da lista de alertas, mas não são removidos da Acronis.</span><button class="clear-filter" id="resetAlertVisibility" type="button">Mostrar todos</button></div>
        </section>

        <section class="alert-kpis" aria-label="Resumo dos alertas">
          <article class="alert-kpi violet-kpi" data-status-filter="all" role="button" tabindex="0" aria-pressed="true"><div class="alert-kpi-top"><span class="alert-icon"><i data-lucide="database"></i></span><span class="kpi-trend">Monitorado</span></div><span>Total no período</span><strong data-alert-count="0">0</strong><small>Mostrar todos os eventos</small></article>
          <article class="alert-kpi green-kpi" data-status-filter="success" role="button" tabindex="0" aria-pressed="false"><div class="alert-kpi-top"><span class="alert-icon"><i data-lucide="circle-check"></i></span><span class="kpi-trend">Saudável</span></div><span>Resolvidos</span><strong data-alert-count="0">0</strong><small>Filtrar alertas fechados ou limpos</small></article>
          <article class="alert-kpi red-kpi" data-status-filter="failed" role="button" tabindex="0" aria-pressed="false"><div class="alert-kpi-top"><span class="alert-icon"><i data-lucide="circle-x"></i></span><span class="kpi-trend down">Prioridade</span></div><span>Pendências abertas</span><strong data-alert-count="0">0</strong><small>Itens que exigem intervenção</small></article>
          <article class="alert-kpi amber-kpi" data-status-filter="running" role="button" tabindex="0" aria-pressed="false"><div class="alert-kpi-top"><span class="alert-icon"><i data-lucide="clock-3"></i></span><span class="kpi-trend warn">Acompanhando</span></div><span>Em andamento</span><strong data-alert-count="0">0</strong><small>Filtrar eventos em processamento</small></article>
        </section>

        <section class="alert-focus-strip" aria-live="polite" aria-label="Prioridade de ação">
          <span>Prioridade</span>
          <strong id="actionQueueTitle">Carregando</strong>
          <small id="actionQueueText">Mostrando item mais importante do período.</small>
          <div id="actionQueueBadges" hidden></div>
        </section>

        <section class="filter-panel" aria-label="Filtros de alertas">
          <div class="filter-field"><label for="statusFilter">Status</label><select id="statusFilter"><option value="all">Todos</option><option value="failed">Falha</option><option value="success">Sucesso</option><option value="known">Marcado como conhecido</option><option value="running">Em andamento</option></select></div>
          <div class="filter-field"><label for="priorityFilter">Prioridade</label><select id="priorityFilter"><option value="all">Todas</option><option value="critical">Crítica</option><option value="high">Alta</option><option value="medium">Média</option><option value="low">Baixa</option></select></div>
          <div class="filter-field"><label for="clientFilter">Cliente</label><select id="clientFilter"><option value="all">Todos os clientes</option></select></div>
          <div class="filter-field"><label for="serverFilter">Dispositivo</label><select id="serverFilter"><option value="all">Todos os dispositivos</option></select></div>
          <label class="search-filter"><span>Buscar</span><i data-lucide="search"></i><input id="searchFilter" type="search" placeholder="Erro, cliente, dispositivo ou IP"></label>
          <button class="clear-filter" id="clearFilters"><i data-lucide="rotate-ccw"></i> Limpar</button>
          <button class="apply-filter" id="applyFilters"><i data-lucide="list-filter"></i> Filtrar</button>
        </section>

        <section class="quick-alert-filters" aria-label="Filtros rápidos por causa">
          <button type="button" class="is-active" data-quick-filter="all">Todos <b data-quick-count="all">0</b></button>
          <button type="button" data-quick-filter="offline">Offline <b data-quick-count="offline">0</b></button>
          <button type="button" data-quick-filter="backup">Backup pendente <b data-quick-count="backup">0</b></button>
          <button type="button" data-quick-filter="hidden">Ocultos <b data-quick-count="hidden">0</b></button>
        </section>

        <section class="alert-table-surface" id="alert-events">
          <div class="table-heading"><div><div class="surface-eyebrow">EVENTOS</div><h2>Lista de alertas <b id="failureBadge">0 pendências</b></h2></div><div class="table-actions"><button id="restoreHiddenAlerts" class="table-restore" type="button" hidden><i data-lucide="eye"></i><span>Restaurar ocultos</span></button><button id="toggleAlertsMaximize" class="table-maximize" type="button" aria-pressed="false" aria-label="Maximizar lista de alertas"><i data-lucide="maximize-2"></i><span>Maximizar</span></button><div class="export-group"><button id="exportCsv" type="button" data-tooltip="Exportar CSV"><i data-lucide="file-spreadsheet"></i><span>CSV</span></button><button id="exportExcel" type="button" data-tooltip="Exportar Excel"><i data-lucide="table-2"></i><span>Excel</span></button><button id="exportPdf" type="button" data-tooltip="Exportar PDF"><i data-lucide="file-text"></i><span>PDF</span></button></div></div></div>
          <div class="responsive-table"><table id="alertsTable" aria-describedby="resultSummary"><thead><tr><th data-sort="status" role="button" tabindex="0">Status <i data-lucide="arrow-up-down"></i></th><th data-sort="client" role="button" tabindex="0">Cliente <i data-lucide="arrow-up-down"></i></th><th data-sort="server" role="button" tabindex="0">Dispositivo <i data-lucide="arrow-up-down"></i></th><th>IP</th><th data-sort="error" role="button" tabindex="0">Motivo <i data-lucide="arrow-up-down"></i></th><th data-sort="time" role="button" tabindex="0" aria-sort="descending">Horário <i data-lucide="arrow-up-down"></i></th><th>Plano</th><th>Tamanho</th><th>Ações</th></tr></thead><tbody id="alertRows"><tr><td class="table-empty" colspan="9">Carregando alertas...</td></tr></tbody></table></div>
          <div class="table-footer"><span id="resultSummary">Carregando alertas...</span><div class="pagination" id="pagination"></div></div>
        </section>
      </div>
    </main>
  </div>

  <dialog class="alert-details" id="alertDetailsDialog" aria-labelledby="alertDetailsTitle">
    <div id="alertDetailsReactRoot"></div>
  </dialog>
  <div class="alerts-toast" id="alertsToast" role="status" aria-live="polite"></div>
  <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
  <script src="<?= htmlspecialchars($alertsAssetPrefix, ENT_QUOTES, 'UTF-8') ?>alertas.js?v=20261001-dashboard-count-1"></script>
  <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
  <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
  <script src="<?= htmlspecialchars($alertsAssetPrefix, ENT_QUOTES, 'UTF-8') ?>alertas-react.js?v=20260928-language-5"></script>
</body>
</html>
