<?php require_once __DIR__ . '/_auth_guard.php'; ?>
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
  <link rel="stylesheet" href="styles.css?v=20260817-7">
  <link rel="stylesheet" href="alertas.css?v=20260817-1430">
</head>
<body data-mode="dark">
  <div class="console-frame">
    <aside class="rail" id="rail">
      <div class="rail-brand">
        <a href="index.php" aria-label="NyxCloud"><img src="../assets/img/icone.png" alt=""><span>NYX<span>CLOUD</span></span></a>
        <button id="railClose" aria-label="Fechar menu">&times;</button>
      </div>
      <div class="tenant-switch"><span class="tenant-mark">N</span><span><b>NyxCloud Enterprise</b><small>Ambiente de produção</small></span><i data-lucide="chevrons-up-down"></i></div>
      <div class="rail-scroll">
        <nav class="rail-nav" aria-label="Navegação do painel">
          <span class="rail-caption">Monitoramento</span>
          <a class="rail-item" href="index.php#summary"><i data-lucide="layout-dashboard"></i><span>Visão geral</span></a>
          <a class="rail-item" href="index.php#recent"><i data-lucide="database-zap"></i><span>Execuções</span><em>0</em></a>
          <a class="rail-item is-current" href="alertas.php"><i data-lucide="siren"></i><span>Alertas</span><em class="danger-count">0</em></a>
          <a class="rail-item" href="index.php#storage"><i data-lucide="hard-drive"></i><span>Armazenamento</span></a>
          <a class="rail-item" href="index.php#schedules"><i data-lucide="calendar-clock"></i><span>Resumo</span></a>
          <a class="rail-item" href="index.php#windows"><i data-lucide="waypoints"></i><span>Janelas</span></a>
          <span class="rail-caption">Ambiente</span>
          <a class="rail-item" href="index.php#clients"><i data-lucide="building-2"></i><span>Clientes</span></a>
          <a class="rail-item" href="index.php#daily-devices"><i data-lucide="server-cog"></i><span>Infraestrutura</span></a>
          <a class="rail-item" href="index.php#reports"><i data-lucide="chart-no-axes-combined"></i><span>Análises</span></a>
          <a class="rail-item" href="#alert-events"><i data-lucide="scroll-text"></i><span>Auditoria</span></a>
        </nav>
      </div>
      <div class="rail-bottom">
        <div class="platform-state"><span class="state-dot"></span><div><b>Painel conectado</b><small>Dados sincronizados com a Acronis</small></div></div>
        <button class="profile-strip" id="profileButton"><span class="profile-avatar">S</span><span><b>Supremo</b><small>Administrador</small></span><i data-lucide="more-horizontal"></i></button>
      </div>
    </aside>

    <button class="rail-scrim" id="railScrim" type="button" aria-label="Fechar menu"></button>

    <main class="workspace">
      <header class="command-bar">
        <div class="command-left"><button class="mobile-trigger" id="railOpen" aria-label="Abrir menu" aria-expanded="true"><i data-lucide="menu"></i></button><div class="crumb"><span>Painel</span><i data-lucide="chevron-right"></i><b>Alertas de backup</b></div></div>
        <div class="command-right">
          <label class="global-search"><i data-lucide="search"></i><input id="globalAlertSearch" type="search" placeholder="Buscar cliente, dispositivo ou erro" aria-label="Pesquisar alertas"></label>
          <a class="command-icon" href="#alert-events" aria-label="Ir para lista de alertas" title="Ir para lista de alertas"><i data-lucide="bell"></i><span></span></a>
          <button class="mode-switch" id="modeSwitch" aria-label="Alternar tema"><i data-lucide="sun"></i><span></span><i data-lucide="moon"></i></button>
          <button class="command-user"><span class="profile-avatar">S</span><span class="command-user-copy"><b>Supremo</b><small>Admin</small></span><i data-lucide="chevron-down"></i></button>
        </div>
      </header>

      <div class="alerts-body">
        <section class="alerts-heading">
          <div><div class="section-kicker"><span class="pulse"></span> CENTRAL DE ALERTAS - AO VIVO</div><h1>Alertas de backups</h1><p>Cliente, dispositivo, IP, tamanho e motivo reunidos para diagnóstico rápido.</p></div>
          <label class="range-picker"><i data-lucide="calendar-days"></i><select id="periodFilter" aria-label="Período"><option value="today">Hoje</option><option value="yesterday">Ontem</option><option value="7">Últimos 7 dias</option><option value="30" selected>Últimos 30 dias</option></select><i data-lucide="chevron-down"></i></label>
        </section>

        <section class="alert-kpis" aria-label="Resumo dos alertas">
          <article class="alert-kpi violet-kpi"><div class="alert-kpi-top"><span class="alert-icon"><i data-lucide="database"></i></span><span class="kpi-trend">Monitorado</span></div><span>Total no período</span><strong data-alert-count="0">0</strong><small>Eventos retornados pela API</small></article>
          <article class="alert-kpi green-kpi"><div class="alert-kpi-top"><span class="alert-icon"><i data-lucide="circle-check"></i></span><span class="kpi-trend">Saudável</span></div><span>Resolvidos</span><strong data-alert-count="0">0</strong><small>Alertas fechados ou limpos</small></article>
          <article class="alert-kpi red-kpi"><div class="alert-kpi-top"><span class="alert-icon"><i data-lucide="circle-x"></i></span><span class="kpi-trend down">Prioridade</span></div><span>Falhas abertas</span><strong data-alert-count="0">0</strong><small>Itens que exigem intervenção</small></article>
          <article class="alert-kpi amber-kpi"><div class="alert-kpi-top"><span class="alert-icon"><i data-lucide="clock-3"></i></span><span class="kpi-trend warn">Acompanhando</span></div><span>Em andamento</span><strong data-alert-count="0">0</strong><small>Eventos ainda em processamento</small></article>
        </section>

        <section class="filter-panel" aria-label="Filtros de alertas">
          <div class="filter-field"><label for="statusFilter">Status</label><select id="statusFilter"><option value="all">Todos</option><option value="failed">Falha</option><option value="success">Sucesso</option><option value="running">Em andamento</option></select></div>
          <div class="filter-field"><label for="priorityFilter">Prioridade</label><select id="priorityFilter"><option value="all">Todas</option><option value="critical">Crítica</option><option value="high">Alta</option><option value="medium">Média</option><option value="low">Baixa</option></select></div>
          <div class="filter-field"><label for="clientFilter">Cliente</label><select id="clientFilter"><option value="all">Todos os clientes</option></select></div>
          <div class="filter-field"><label for="serverFilter">Dispositivo</label><select id="serverFilter"><option value="all">Todos os dispositivos</option></select></div>
          <label class="search-filter"><span>Buscar</span><i data-lucide="search"></i><input id="searchFilter" type="search" placeholder="Erro, cliente, dispositivo ou IP"></label>
          <button class="clear-filter" id="clearFilters"><i data-lucide="rotate-ccw"></i> Limpar</button>
          <button class="apply-filter" id="applyFilters"><i data-lucide="list-filter"></i> Filtrar</button>
        </section>

        <section class="alert-table-surface" id="alert-events">
          <div class="table-heading"><div><div class="surface-eyebrow">EVENTOS</div><h2>Lista de alertas <b id="failureBadge">0 falhas</b></h2></div><div class="export-group"><button id="exportCsv" data-tooltip="Exportar CSV"><i data-lucide="file-spreadsheet"></i><span>CSV</span></button><button id="exportExcel" data-tooltip="Exportar Excel"><i data-lucide="table-2"></i><span>Excel</span></button><button id="exportPdf" data-tooltip="Exportar PDF"><i data-lucide="file-text"></i><span>PDF</span></button></div></div>
          <div class="responsive-table"><table id="alertsTable"><thead><tr><th data-sort="status">Status <i data-lucide="arrow-up-down"></i></th><th data-sort="client">Cliente <i data-lucide="arrow-up-down"></i></th><th data-sort="server">Dispositivo / alerta <i data-lucide="arrow-up-down"></i></th><th>IP / origem</th><th data-sort="error">Motivo <i data-lucide="arrow-up-down"></i></th><th data-sort="time">Horário <i data-lucide="arrow-up-down"></i></th><th>Plano</th><th>Tamanho</th><th>Ações</th></tr></thead><tbody id="alertRows"><tr><td class="table-empty" colspan="9">Carregando alertas...</td></tr></tbody></table></div>
          <div class="table-footer"><span id="resultSummary">Carregando alertas...</span><div class="pagination" id="pagination"></div></div>
        </section>
      </div>
    </main>
  </div>

  <dialog class="alert-details" id="alertDetailsDialog" aria-labelledby="alertDetailsTitle">
    <div class="alert-details-head"><div><span>DETALHES DO EVENTO</span><h2 id="alertDetailsTitle">Alerta</h2></div><button type="button" id="closeAlertDetails" aria-label="Fechar detalhes"><i data-lucide="x"></i></button></div>
    <dl id="alertDetailsContent"></dl>
  </dialog>
  <div class="alerts-toast" id="alertsToast" role="status" aria-live="polite"></div>
  <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
  <script src="alertas.js?v=20260817-4"></script>
</body>
</html>
