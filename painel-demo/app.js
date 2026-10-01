(() => {
  'use strict';

  const root = document.body;
  const rail = document.getElementById('rail');
  const toast = document.getElementById('toast');
  const chartStore = new Map();
  let toastTimer;

  const state = {
    me: null,
    dashboard: null,
    customers: [],
    devices: [],
    devicesLoading: true,
    devicesError: '',
    dailyExecutions: null,
    dailyExecutionsError: '',
    alerts: [],
    alertsLoaded: false,
    executionWindows: null,
    executionWindowsError: '',
    accounts: [],
    companies: [],
    accountsMeta: { perfis: {}, empresas: [], viewer: null, administrador_geral: false },
    accountsError: '',
    integrations: [],
    integrationsError: '',
    audit: [],
    auditError: '',
    windowRules: null,
    windowRulesError: '',
    windowsSearch: '',
    globalSearch: '',
    executionSearch: '',
    executionStatus: 'all',
    executionSort: 'recent',
    clientsSearch: '',
    clientsSort: 'name'
  };

  const dataRequests = new Map();
  let sectionActivationId = 0;

  const theme = {
    key: 'nyxcloud-console-mode',
    get() {
      return localStorage.getItem(this.key) || 'dark';
    },
    apply(mode) {
      root.dataset.mode = mode === 'light' ? 'light' : 'dark';
      localStorage.setItem(this.key, root.dataset.mode);
      setTimeout(refreshChartTheme, 0);
    },
    toggle() {
      this.apply(this.get() === 'dark' ? 'light' : 'dark');
    }
  };

  const colors = () => ({
    violet: '#713fa9',
    violet2: '#a880da',
    cyan: '#3278b8',
    green: '#16a34a',
    red: '#c9364e',
    amber: '#c07c0b',
    text: root.dataset.mode === 'light' ? '#637083' : '#95a5bb',
    grid: root.dataset.mode === 'light' ? '#e8e8e8' : 'rgba(164,184,211,.11)'
  });

  const chartHeight = (large, medium = large, small = medium) => {
    if (window.innerWidth <= 700) return small;
    if (window.innerWidth <= 1320) return medium;
    return large;
  };

  const chartBase = height => ({
    chart: {
      height,
      background: 'transparent',
      fontFamily: 'Space Grotesk, Inter, sans-serif',
      foreColor: colors().text,
      toolbar: { show: false },
      animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches, easing: 'easeinout', speed: 620, animateGradually: { enabled: true, delay: 70 } },
      parentHeightOffset: 0
    },
    dataLabels: { enabled: false },
    grid: {
      borderColor: colors().grid,
      strokeDashArray: 3,
      padding: { top: 8, right: 14, bottom: 0, left: 10 }
    },
    xaxis: {
      labels: {
        hideOverlappingLabels: true,
        rotate: 0,
        trim: true,
        style: { colors: colors().text, fontSize: '10px', fontWeight: 600 }
      },
      axisBorder: { show: false },
      axisTicks: { show: false }
    },
    yaxis: {
      labels: { style: { colors: colors().text, fontSize: '10px', fontWeight: 600 } }
    },
    legend: {
      position: 'bottom',
      fontSize: '10px',
      fontWeight: 600,
      labels: { colors: colors().text },
      markers: { width: 7, height: 7, radius: 7 },
      itemMargin: { horizontal: 10, vertical: 4 }
    },
    tooltip: {
      theme: root.dataset.mode,
      shared: true,
      intersect: false,
      fillSeriesColor: false,
      style: { fontSize: '11px', fontFamily: 'Space Grotesk, Inter, sans-serif' }
    },
    noData: { text: 'Sem dados no periodo', align: 'center', verticalAlign: 'middle' }
  });

  const mount = (id, options) => {
    const node = document.getElementById(id);
    if (!node || !window.ApexCharts) {
      return;
    }

    const previous = chartStore.get(id);
    if (previous) {
      previous.destroy();
    }

    node.classList.remove('chart-fallback');
    node.replaceChildren();
    const chart = new ApexCharts(node, options);
    chart.render();
    chartStore.set(id, chart);
  };

  const mountFallbackBars = (id, values, labels, color) => {
    const node = document.getElementById(id);
    if (!node) return;
    const data = values.map(value => Math.max(0, Number(value || 0)));
    const max = Math.max(...data, 1);
    const chart = document.createElement('div');
    chart.className = 'fallback-bars';
    data.forEach((value, index) => {
      const item = document.createElement('div');
      const bar = document.createElement('i');
      const label = document.createElement('small');
      item.title = `${labels[index] || '--'}: ${fmtInt(value)}`;
      bar.style.height = `${value > 0 ? Math.max(4, (value / max) * 100) : 0}%`;
      bar.style.background = color;
      label.textContent = labels[index] || '--';
      item.append(bar, label);
      chart.append(item);
    });
    node.classList.add('chart-fallback');
    node.replaceChildren(chart);
  };

  const mountFallbackRing = (id, values, ringColors, center, label) => {
    const node = document.getElementById(id);
    if (!node) return;
    const total = Math.max(values.reduce((sum, value) => sum + Math.max(0, Number(value || 0)), 0), 1);
    let cursor = 0;
    const stops = values.map((value, index) => {
      const start = cursor;
      cursor += (Math.max(0, Number(value || 0)) / total) * 100;
      return `${ringColors[index]} ${start}% ${cursor}%`;
    });
    const ring = document.createElement('div');
    const copy = document.createElement('div');
    const value = document.createElement('b');
    const description = document.createElement('small');
    ring.className = 'fallback-ring';
    ring.style.background = `conic-gradient(${stops.join(',')})`;
    copy.className = 'fallback-ring-copy';
    value.textContent = center;
    description.textContent = label;
    copy.append(value, description);
    ring.append(copy);
    node.classList.add('chart-fallback');
    node.replaceChildren(ring);
  };

  const renderStatusDonutCenter = total => {
    const node = document.getElementById('statusDonut');
    if (!node) return;
    node.querySelector('.status-donut-center')?.remove();
    const center = document.createElement('div');
    center.className = 'status-donut-center';
    center.innerHTML = `<strong>${escapeHtml(fmtInt(total))}</strong><span>TOTAL</span>`;
    node.append(center);
  };

  const setText = (id, value) => {
    const node = document.getElementById(id);
    if (node) {
      node.textContent = value;
    }
  };

  const cookieValue = name => document.cookie
    .split(';')
    .map(value => value.trim())
    .find(value => value.startsWith(`${name}=`))
    ?.slice(name.length + 1) || '';
  const csrfToken = () => state.me?.csrf_token || decodeURIComponent(cookieValue('csrf_token'));
  const authHeaders = options => {
    const method = String(options.method || 'GET').toUpperCase();
    if (['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      return {};
    }

    const csrf = csrfToken();
    return csrf ? { 'X-CSRF-Token': csrf } : {};
  };
  const redirectToLogin = () => {
    const cleanPanel = /\/painel(?:\/|$)/i.test(window.location.pathname);
    const login = new URL(cleanPanel ? '../login/' : '../back/index.php', window.location.href);
    login.searchParams.set('next', window.location.pathname + window.location.search + window.location.hash);
    window.location.replace(login.href);
  };

  const clientErrorMessage = (message, fallback = 'Não foi possível carregar os dados agora. Tente novamente em instantes.') => {
    const text = String(message || '').trim();
    if (!text || /\.php(?:\b|[?#])/i.test(text) || /tempo esgotado|timed out|failed to fetch|networkerror|load failed|abort/i.test(text)) {
      return fallback;
    }
    return text;
  };

  const fetchJson = async (endpoint, options = {}) => {
    const controller = new AbortController();
    const timeoutMs = Number(options.timeout || 12000);
    const timeout = window.setTimeout(() => controller.abort(), timeoutMs);
    let response;
    try {
      response = await fetch(`../back/api/${endpoint}`, {
        method: options.method || 'GET',
        headers: {
          Accept: 'application/json',
          ...(options.body ? { 'Content-Type': 'application/json' } : {}),
          ...authHeaders(options)
        },
        credentials: 'include',
        body: options.body ? JSON.stringify(options.body) : undefined,
        signal: controller.signal
      });
    } catch (error) {
      if (error?.name === 'AbortError') {
        console.warn('[NyxCloud] A requisição excedeu o tempo limite.', { endpoint, error });
        throw new Error('Não foi possível carregar os dados agora. Tente novamente em instantes.');
      }
      console.warn('[NyxCloud] Falha de comunicação com o serviço.', { endpoint, error });
      throw new Error(clientErrorMessage(error?.message));
    } finally {
      window.clearTimeout(timeout);
    }

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (response.status === 401) {
      localStorage.removeItem('access_token');
      sessionStorage.removeItem('access_token');
      redirectToLogin();
      throw new Error('Autenticacao necessaria.');
    }

    if (!response.ok || !payload?.success) {
      const message = clientErrorMessage(payload?.message, 'Não foi possível concluir esta operação. Tente novamente em instantes.');
      console.warn('[NyxCloud] A API não concluiu a requisição.', { endpoint, status: response.status, message: payload?.message });
      throw new Error(message);
    }

    return payload.data;
  };

  const notify = message => {
    if (!toast) {
      return;
    }

    toast.textContent = message;
    toast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 2600);
  };

  const uiLocale = () => localStorage.getItem('nyxcloud_language') === 'en-US' ? 'en-US' : 'pt-BR';
  const fmtInt = value => Number(value || 0).toLocaleString(uiLocale());
  const fmtPercent = value => `${Number(value || 0).toFixed(2)}%`;
  const profileInitial = value => (String(value || '').trim().charAt(0) || 'U').toUpperCase();
  const formatDateTime = value => {
    if (!value) return '--';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '--' : date.toLocaleString(uiLocale(), {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  };
  const accountRoleHint = role => ({
    admin: 'Administrador: acesso total ao painel e ao gerenciamento de contas.',
    operador: 'Operador: acompanha a operacao do painel sem administrar usuarios.',
    leitura: 'Somente leitura: visualiza indicadores sem acesso administrativo.'
  }[String(role || '').toLowerCase()] || 'Selecione um perfil para ver a descricao.');

  const countUp = (element, target, suffix = '') => {
    if (!element) {
      return;
    }

    const start = performance.now();
    const tick = now => {
      const progress = Math.min((now - start) / 1100, 1);
      element.textContent = `${Math.round(target * (1 - Math.pow(1 - progress, 3))).toLocaleString(uiLocale())}${suffix}`;
      if (progress < 1) {
        requestAnimationFrame(tick);
      }
    };
    requestAnimationFrame(tick);
  };

  const formatBytes = bytes => {
    if (!Number.isFinite(bytes) || bytes <= 0) {
      return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let value = bytes;
    let index = 0;
    while (value >= 1024 && index < units.length - 1) {
      value /= 1024;
      index += 1;
    }

    return `${value.toFixed(index >= 3 ? 1 : 0)} ${units[index]}`;
  };

  const renderDailyHistoryLegacy = series => {
    const body = document.getElementById('dailyHistoryRows');
    if (!body) {
      return;
    }

    body.replaceChildren();
    if (!series.length) {
      body.innerHTML = '<tr><td colspan="5">Nenhuma tarefa diaria retornada pelo Acronis.</td></tr>';
      return;
    }

    [...series].sort((a, b) => String(a.date || '').localeCompare(String(b.date || ''))).forEach(item => {
      const row = document.createElement('tr');
      const date = document.createElement('td');
      const executions = document.createElement('td');
      const success = document.createElement('td');
      const failed = document.createElement('td');
      const size = document.createElement('td');
      const parsedDate = item.date ? new Date(`${item.date}T12:00:00`) : null;
      date.textContent = parsedDate && !Number.isNaN(parsedDate.getTime())
        ? parsedDate.toLocaleDateString(uiLocale(), { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
        : item.label || '--';
      executions.textContent = `${fmtInt(item.backups || 0)} execuções`;
      success.textContent = fmtInt(item.success || 0);
      failed.textContent = fmtInt(item.failed || 0);
      size.textContent = formatBytes(Number(item.bytes || 0));
      row.append(date, executions, success, failed, size);
      body.append(row);
    });
  };

  const renderDailyHistory = series => {
    const body = document.getElementById('dailyHistoryRows');
    if (!body) {
      return;
    }

    const ordered = [...series].sort((a, b) => String(a.date || '').localeCompare(String(b.date || '')));
    const activeDays = ordered.filter(item => Number(item.backups || 0) > 0);
    const totalExecutions = ordered.reduce((sum, item) => sum + Number(item.backups || 0), 0);
    const totalSuccess = ordered.reduce((sum, item) => sum + Number(item.success || 0), 0);
    const totalBytes = ordered.reduce((sum, item) => sum + Number(item.bytes || 0), 0);
    const latest = ordered[ordered.length - 1] || {};

    setText('dailyToday', fmtInt(latest.backups || 0));
    setText('dailyAverage', fmtInt(activeDays.length ? Math.round(totalExecutions / activeDays.length) : 0));
    setText('dailySuccessRate', totalExecutions > 0 ? `${((totalSuccess / totalExecutions) * 100).toFixed(1)}%` : '0%');
    setText('dailyVolume', formatBytes(totalBytes));
    body.replaceChildren();

    if (!ordered.length) {
      body.innerHTML = '<tr><td colspan="5">Nenhuma tarefa diaria retornada pelo Acronis.</td></tr>';
      return;
    }

    ordered.forEach(item => {
      const row = document.createElement('tr');
      const parsedDate = item.date ? new Date(`${item.date}T12:00:00`) : null;
      const values = [
        parsedDate && !Number.isNaN(parsedDate.getTime())
          ? parsedDate.toLocaleDateString(uiLocale(), { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
          : item.label || '--',
        fmtInt(item.backups || 0),
        fmtInt(item.success || 0),
        fmtInt(item.failed || 0),
        formatBytes(Number(item.bytes || 0))
      ];

      values.forEach((value, index) => {
        const cell = document.createElement('td');
        cell.textContent = value;
        if (index === 2) cell.className = 'daily-good';
        if (index === 3) cell.className = Number(item.failed || 0) > 0 ? 'daily-bad' : 'daily-zero';
        row.append(cell);
      });
      body.append(row);
    });
  };

  const toShortTime = value => {
    if (!value) {
      return '--';
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '--' : date.toLocaleString(uiLocale(), {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  };

  const diffLabel = iso => {
    if (!iso) {
      return '--';
    }

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
      return '--';
    }

    const diffMs = Math.max(0, Date.now() - date.getTime());
    const days = Math.floor(diffMs / 86400000);
    return `${days} dia${days === 1 ? '' : 's'}`;
  };

  const statusMeta = rawStatus => {
    const status = String(rawStatus || '').toLowerCase();
    if (status.includes('fail') || status.includes('error')) {
      return { className: 'failed', label: 'Falha' };
    }
    if (status.includes('warn') || status.includes('queue') || status.includes('pending')) {
      return { className: 'queued', label: 'Atencao' };
    }
    if (status.includes('run') || status.includes('progress')) {
      return { className: 'queued', label: 'Em andamento' };
    }
    if (status.includes('success') || status.includes('ok') || status.includes('idle')) {
      return { className: 'success', label: 'Concluido' };
    }

    return { className: 'queued', label: 'Indefinido' };
  };

  const backupDaysLabel = device => {
    if (device?.dias_sem_backup === 0) {
      return '0 dias';
    }
    if (Number.isInteger(device?.dias_sem_backup) && device.dias_sem_backup > 0) {
      return `${device.dias_sem_backup} dias`;
    }

    return diffLabel(device?.ultimo_backup || device?.last_backup);
  };

  const backupSizeLabel = device => {
    const text = String(device?.tamanho_realizado || '').trim();
    return text !== '' ? text : 'Nao informado';
  };

  const planEntries = device => {
    const details = Array.isArray(device?.planos_detalhes) ? device.planos_detalhes : [];
    if (details.length) {
      return details.map(detail => ({
        plano: normalizeText(detail?.plano || 'Sem plano'),
        ultimo_backup: detail?.ultimo_backup || device?.ultimo_backup || '',
        dias_sem_backup: Number.isInteger(detail?.dias_sem_backup) ? detail.dias_sem_backup : null,
        tamanho_realizado: String(detail?.tamanho_realizado || 'Nao informado').trim() || 'Nao informado',
        status: detail?.status || device?.status || 'unknown'
      }));
    }

    return extractPlans(device).map(plan => ({
      plano: normalizeText(plan),
      ultimo_backup: device?.ultimo_backup || '',
      dias_sem_backup: Number.isInteger(device?.dias_sem_backup) ? device.dias_sem_backup : null,
      tamanho_realizado: backupSizeLabel(device),
      status: device?.status || 'unknown'
    }));
  };

  const backupDaysFromEntry = entry => {
    if (entry?.dias_sem_backup === 0) {
      return '0 dias';
    }
    if (Number.isInteger(entry?.dias_sem_backup) && entry.dias_sem_backup > 0) {
      return `${entry.dias_sem_backup} dias`;
    }

    return diffLabel(entry?.ultimo_backup);
  };

  const extractPlans = device => {
    const source = String(device?.plano || device?.plans || '').trim();
    if (source === '') {
      return ['Sem plano'];
    }

    return [...new Set(source.split(';').map(plan => plan.trim()).filter(Boolean))];
  };

  const normalizeText = value => {
    const text = String(value || '').trim();
    return text === '' ? '--' : text;
  };

  const cleanLabel = value => {
    const text = normalizeText(value);
    return text === '--' ? text : text.replace(/\s+/g, ' ').trim();
  };
  const escapeHtml = value => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const canManageAccounts = () => Boolean(state.me?.pode_acessar_contas || state.me?.pode_gerenciar_contas);
  const canManageAdmin = () => Boolean(state.me?.pode_gerenciar_contas);
  const sectionPermissions = {
    admin: new Set(['overview', 'executions', 'alerts', 'storage', 'summary', 'windows', 'clients', 'accounts', 'integrations', 'infrastructure', 'analytics', 'audit', 'profile']),
    operador: new Set(['overview', 'executions', 'alerts', 'storage', 'summary', 'windows', 'clients', 'accounts', 'infrastructure', 'analytics', 'profile']),
    leitura: new Set(['overview', 'executions', 'alerts', 'storage', 'summary', 'windows', 'clients', 'infrastructure', 'analytics', 'profile'])
  };
  const uiTranslations = {
    'Visão geral': 'Overview', 'Atividade recente': 'Recent activity', 'Alertas': 'Alerts', 'Armazenamento': 'Storage',
    'Resumo': 'Summary', 'Janelas de execução': 'Execution windows', 'Clientes': 'Clients', 'Contas': 'Accounts',
    'Integrações': 'Integrations', 'Execuções por dispositivo': 'Executions by device', 'Análises': 'Analytics',
    'Auditoria administrativa': 'Administrative audit', 'Meu perfil': 'My profile', 'Monitoramento': 'Monitoring',
    'Ambiente': 'Environment', 'Usuário': 'User', 'Atualizar dados': 'Refresh data', 'Dados reais': 'Real data',
    'Buscar cliente ou maquina': 'Search client or machine', 'Pesquisar cliente na aba Janelas': 'Search client in Windows',
    'Pesquisar nas execuções': 'Search executions', 'Status': 'Status', 'Todos': 'All', 'Concluído': 'Completed',
    'Falha': 'Failed', 'Atenção': 'Attention', 'Mais recente': 'Most recent', 'Mais antigo': 'Oldest',
    'Cliente A–Z': 'Client A–Z', 'Hoje': 'Today', 'Ontem': 'Yesterday', 'Últimos 7 dias': 'Last 7 days',
    'Últimos 30 dias': 'Last 30 days', 'Salvar alterações': 'Save changes', 'Dados e segurança': 'Data and security',
    'Visão geral': 'Overview', 'Somente leitura': 'Read-only', 'Operador': 'Operator', 'Administrador': 'Administrator',
    'Ações': 'Actions', 'Salvar': 'Save', 'Recarregar': 'Reload', 'Fechar': 'Close', 'Carregando': 'Loading',
    'Sincronizando': 'Synchronizing', 'Atualizado agora': 'Updated now', 'Painel conectado': 'Panel connected',
    'Dados sincronizados com a Acronis': 'Data synchronized with Acronis',
    'Visão operacional de proteção': 'Protection operations overview',
    'O essencial para entender a saúde dos backups e decidir o que precisa de atenção agora.': 'Everything you need to understand backup health and decide what needs attention now.',
    'Proteção monitorada': 'Monitored protection', 'Resumo consolidado da operação de backup e do ambiente protegido.': 'Consolidated summary of backup operations and the protected environment.',
    'Taxa de sucesso': 'Success rate', 'Melhor indicador': 'Best indicator', 'Monitorados': 'Monitored', 'Protegidos': 'Protected',
    'Backups concluídos': 'Completed backups', 'Backups processados': 'Processed backups', 'Backups com falha': 'Failed backups',
    'Espaço protegido': 'Protected space', 'Resultado das execuções': 'Execution results', 'Tarefas concluídas nos últimos 30 dias': 'Tasks completed in the last 30 days',
    'Sucesso': 'Success', 'Outros': 'Other', 'Execuções por dia': 'Executions per day', 'Últimos 21 dias disponíveis na Acronis': 'Last 21 days available in Acronis',
    'Prioridades da operação': 'Operational priorities', 'Os sinais que merecem uma próxima ação.': 'Signals that require the next action.',
    'Falhas no período': 'Failures in period', 'Clientes sem histórico': 'Clients without history', 'Última atividade': 'Last activity',
    'Recência por cliente': 'Recency by client', 'Quando ocorreu o último backup registrado.': 'When the last recorded backup occurred.',
    'clientes com histórico': 'clients with history', 'Até 24 horas': 'Within 24 hours', 'De 2 a 7 dias': '2 to 7 days', 'Mais de 7 dias ou sem histórico': 'More than 7 days or no history',
    'Uso do armazenamento': 'Storage usage', 'Em uso': 'In use', 'Fonte': 'Source', 'Desempenho diário dos backups': 'Daily backup performance',
    'Gráfico dos últimos 21 dias e histórico completo disponível.': 'Chart of the last 21 days with full history available.', 'execuções': 'executions', 'Média diária': 'Daily average',
    'no período': 'in period', 'Volume processado': 'Processed volume', 'Dia realizado': 'Execution day', 'Falhas': 'Failures', 'Tamanho processado': 'Processed size',
    'Clientes e últimos backups': 'Clients and latest backups', 'Todos os clientes, seus dispositivos e a atividade de backup mais recente.': 'All clients, their devices and the latest backup activity.',
    'na carteira atual': 'in current portfolio', 'Com backup registrado': 'With recorded backup', 'aguardando dados': 'awaiting data', 'Backup mais recente': 'Latest backup', 'Mais dispositivos': 'Most devices',
    'Ritmo operacional': 'Operational pace', 'Últimos 7 dias comparados com os 7 dias anteriores': 'Last 7 days compared with the previous 7 days',
    'Tendência': 'Trend', 'últimos 7 dias': 'last 7 days', 'Causas que mais se repetem': 'Most frequent causes', 'Alertas agrupados por tipo para priorizar correções': 'Alerts grouped by type to prioritize fixes',
    'Recomendações acionáveis': 'Actionable recommendations', 'Sugestões baseadas nos sinais atuais do ambiente': 'Suggestions based on current environment signals',
    'Ultimos dispositivos com atividade': 'Latest active devices', 'Filtre por status e ordene pelo último backup ou cliente.': 'Filter by status and sort by latest backup or client.',
    'Hostname': 'Hostname', 'Ultimo backup': 'Latest backup', 'Dias': 'Days', 'Aguardando API': 'Waiting for API', 'Aguardando': 'Waiting',
    'Hoje e ontem em ordem alfabetica': 'Today and yesterday in alphabetical order', 'Uma linha por dispositivo e plano, usando a ultima execucao de cada dia': 'One row per device and plan, using the latest execution of each day',
    'Atualizacao automatica': 'Automatic update', 'Contas do painel': 'Panel accounts', 'Crie acessos para administradores, operadores ou usuarios com somente leitura.': 'Create access for administrators, operators or read-only users.',
    'Controle interno': 'Internal control', 'Voce nao tem permissao para gerenciar contas.': 'You do not have permission to manage accounts.', 'Ultimo login': 'Last login', 'Criado em': 'Created at',
    'Criar usuario com permissao menor': 'Create a lower-permission user', 'Senha inicial': 'Initial password', 'Conta ativa ao criar': 'Active account on creation',
    'Somente leitura: visualiza indicadores sem acesso administrativo.': 'Read-only: view indicators without administrative access.', 'Criar conta': 'Create account',
    'Integrações cadastradas': 'Registered integrations', 'Escolha qual ambiente o painel usa para buscar dados.': 'Choose which environment the panel uses to fetch data.',
    'Secret protegido': 'Protected secret', 'Voce nao tem permissao para gerenciar integrações.': 'You do not have permission to manage integrations.', 'Região': 'Region', 'Outro': 'Other',
    'Ativar após salvar': 'Activate after saving', 'Salvar integração': 'Save integration', 'Auditoria de contas': 'Account audit',
    'Eventos de criacao, edicao, troca de perfil, status e senha.': 'Events involving creation, editing, profile changes, status and password.', 'Somente administradores': 'Administrators only',
    'Data': 'Date', 'Acao': 'Action', 'Ator': 'Actor', 'Alvo': 'Target', 'Detalhes': 'Details', 'Perfil e segurança': 'Profile and security',
    'Informações gerais, permissões e configurações de acesso.': 'General information, permissions and access settings.', 'Contato principal': 'Primary contact',
    'Identificação': 'Identification', 'Conta ativa': 'Active account', 'Idioma': 'Language', 'Minhas permissões': 'My permissions', 'Carregando dados da Acronis...': 'Loading Acronis data...',
    'Atividade recente por dispositivo, plano, horario, status e volume processado.': 'Recent activity by device, plan, time, status and processed volume.',
    'Volume protegido consolidado e distribuicao do consumo entre clientes.': 'Consolidated protected volume and usage distribution among clients.',
    'Indicadores essenciais para leitura executiva rapida da operacao.': 'Essential indicators for a quick executive view of operations.',
    'Comparativo entre horarios esperados e execucoes realizadas por empresa e plano.': 'Comparison between expected times and executions by company and plan.',
    'Distribuicao dos dispositivos protegidos e concentracao da carga por cliente.': 'Distribution of protected devices and workload concentration by client.',
    'Execucoes de hoje e ontem organizadas por dispositivo para verificacao operacional.': 'Today and yesterday executions organized by device for operational review.',
    'Investigue tendências, compare períodos, identifique causas recorrentes e priorize ações.': 'Investigate trends, compare periods, identify recurring causes and prioritize actions.',
    'Central de alertas operacionais.': 'Operational alerts center.', 'Eventos administrativos de criacao, edicao e seguranca de contas.': 'Administrative events involving account creation, editing and security.',
    'Informacoes gerais, permissoes e seguranca da sua conta.': 'General information, permissions and security for your account.',
    'Acesso total ao painel, contas, integrações, auditoria e configurações.': 'Full access to the panel, accounts, integrations, audit and settings.',
    'Consulta dados operacionais e executa rotinas permitidas.': 'View operational data and perform permitted routines.',
    'Consulta indicadores, clientes, alertas e relatórios.': 'View indicators, clients, alerts and reports.',
    'Concluído': 'Completed', 'Concluída': 'Completed', 'Não informado': 'Not provided', 'Nao informado': 'Not provided',
    'Sem informação': 'No information', 'Sem informacao': 'No information', 'Sem plano': 'No plan', 'Aguardando dados': 'Waiting for data',
    'Cliente não identificado': 'Unidentified client', 'Cliente nao identificado': 'Unidentified client',
    'Máquina não identificada': 'Unidentified machine', 'Maquina nao identificada': 'Unidentified machine',
    'Nenhum cliente encontrado.': 'No clients found.', 'Nenhum resultado encontrado.': 'No results found.', 'Sem dados disponíveis': 'No data available',
    'Sem dados disponiveis': 'No data available', 'Proteção': 'Protection', 'Backup': 'Backup', 'Em andamento': 'In progress',
    'Pendente': 'Pending', 'Desconhecido': 'Unknown', 'Desativado': 'Disabled', 'Ativo': 'Active', 'Inativo': 'Inactive',
    'Nenhuma falha identificada no período atual.': 'No failures identified in the current period.', 'Aguardando a lista de clientes da Acronis.': 'Waiting for the Acronis client list.',
    'Aguardando clientes': 'Waiting for clients', 'clientes monitorados': 'monitored clients', 'com último backup há mais de 7 dias.': 'with the last backup more than 7 days ago.',
    'Nenhuma execução diária disponível para consulta.': 'No daily execution available for review.', 'execuções registradas nesse dia.': 'executions recorded on that day.',
    'Falha ao carregar janelas': 'Failed to load windows', 'Tente abrir esta secao novamente.': 'Try opening this section again.',
    'Nenhuma janela retornada pela API.': 'No windows returned by the API.', 'Nenhum cliente encontrado para': 'No client found for',
    'Sem janelas configuradas': 'No windows configured', 'Sem resultado': 'No results', 'Ajuste o nome do cliente para localizar a janela correta.': 'Adjust the client name to find the correct window.',
    'Indisponivel': 'Unavailable', 'Carregando resultado dos backups...': 'Loading backup results...', 'Verificando a cobertura da carteira...': 'Checking portfolio coverage...',
    'Consultando a série diária...': 'Querying the daily series...', 'Carregando cobertura...': 'Loading coverage...', 'Calculando recomendações...': 'Calculating recommendations...'
  };
  const applyLanguage = () => {
    const language = localStorage.getItem('nyxcloud_language') || 'pt-BR';
    document.documentElement.lang = language;
    if (language === 'pt-BR') return;
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    const entries = Object.entries(uiTranslations).sort((left, right) => right[0].length - left[0].length);
    nodes.forEach(node => {
      const original = node.nodeValue || '';
      if (!original.trim()) return;
      let translated = original;
      entries.forEach(([source, target]) => { translated = translated.replaceAll(source, target); });
      if (translated !== original) node.nodeValue = translated;
    });
    document.querySelectorAll('[placeholder]').forEach(element => {
      const translated = uiTranslations[element.getAttribute('placeholder')];
      if (translated) element.setAttribute('placeholder', translated);
    });
  };
  let languageObserver = null;
  const startLanguageObserver = () => {
    if (languageObserver) return;
    languageObserver = new MutationObserver(() => applyLanguage());
    languageObserver.observe(document.body, { childList: true, subtree: true });
  };
  const canAccessSection = section => sectionPermissions[String(state.me?.perfil || '').toLowerCase()]?.has(section) ?? false;
  const applyRoleVisibility = () => {
    if (!state.me) return;
    const restricted = String(state.me?.perfil || '').toLowerCase() === 'admin'
      ? new Set()
      : new Set(['accounts', 'integrations', 'audit']);
    document.querySelectorAll('.rail-item[data-section]').forEach(item => {
      item.hidden = !canAccessSection(item.dataset.section || '');
    });
    document.querySelectorAll('[data-panel-section]').forEach(panel => {
      const sections = `${panel.dataset.panelSection || ''} ${panel.dataset.panelExtraSections || ''}`.split(/\s+/).filter(Boolean);
      if (sections.some(section => restricted.has(section))) {
        panel.hidden = true;
      }
    });
    if (root.dataset.activeSection && !canAccessSection(root.dataset.activeSection)) {
      activateSection('overview', false);
    }
  };

  const renderProfile = () => {
    if (!state.me) {
      ['commandProfileName'].forEach(id => setText(id, 'Carregando perfil'));
      ['commandProfileRole'].forEach(id => setText(id, 'Validando sessao'));
      ['railProfileAvatar', 'commandProfileAvatar'].forEach(id => setText(id, '?'));
      return;
    }

    const user = state.me;
    const name = cleanLabel(user.nome || user.email || 'Sessao sem nome');
    const role = cleanLabel(user.perfil_nome || user.perfil || 'Perfil nao informado');
    ['commandProfileName'].forEach(id => setText(id, name));
    ['commandProfileRole'].forEach(id => setText(id, role));
    ['railProfileAvatar', 'commandProfileAvatar'].forEach(id => setText(id, profileInitial(name)));
    renderProfilePage();
    applyRoleVisibility();
    const manageRules = document.getElementById('manageWindowRules');
    if (manageRules) manageRules.hidden = !canManageAdmin();
  };

  const renderProfilePage = () => {
    if (!state.me) return;
    const role = state.me.perfil_nome || state.me.perfil || 'Perfil nao informado';
    const securityPanel = document.querySelector('[data-page-profile-panel="security"]');
    if (securityPanel && !securityPanel.querySelector('.profile-access-cards')) {
      const cards = document.createElement('div'); cards.className = 'profile-access-cards';
      cards.innerHTML = '<div><span>Meu cargo</span><strong id="pageProfileRoleCard">--</strong></div><div><span>Minhas permissões</span><strong id="pageProfilePermissionsCard">--</strong></div>';
      securityPanel.prepend(cards);
    }
    if (securityPanel && !securityPanel.querySelector('.profile-security-info')) {
      const info = document.createElement('div'); info.className = 'profile-security-info';
      info.innerHTML = '<div><span>Último acesso</span><strong id="pageProfileLastLogin">--</strong></div><div><span>Conta criada em</span><strong id="pageProfileCreatedAt">--</strong></div><div><span>Proteção</span><strong>Senha protegida</strong></div><div><span>Sessão</span><strong>Ativa neste navegador</strong></div>';
      securityPanel.append(info);
    }
    setText('pageProfileSummaryName', state.me.nome || '--'); setText('pageProfileSummaryRole', role); setText('pageProfileSummaryEmail', state.me.email || '--');
    setText('pageProfileSummaryId', state.me.id ? `#${state.me.id}` : '--'); setText('pageProfileSummaryAvatar', profileInitial(state.me.nome)); setText('pageProfileAvatar', profileInitial(state.me.nome));
    setText('pageProfilePermissionsText', permissionsByRole[role] || 'Permissões definidas pelo administrador.'); setText('pageProfileRoleCard', role); setText('pageProfilePermissionsCard', permissionsByRole[role] || 'Permissões definidas pelo administrador.');
    setText('pageProfileLastLogin', formatDateTime(state.me.ultimo_login_em)); setText('pageProfileCreatedAt', formatDateTime(state.me.criado_em));
  };

  const markProfileUnavailable = () => {
    ['commandProfileName'].forEach(id => setText(id, 'Sessao indisponivel'));
    ['commandProfileRole'].forEach(id => setText(id, 'Recarregue ou faca login'));
    ['railProfileAvatar', 'commandProfileAvatar'].forEach(id => setText(id, '!'));
  };

  const renderAccounts = () => {
    const body = document.getElementById('accountsRows');
    const notice = document.getElementById('accountsAccessNotice');
    const form = document.getElementById('accountForm');
    if (!body) return;

    if (!canManageAccounts()) {
      body.innerHTML = '<tr><td colspan="8">Acesso restrito a administradores.</td></tr>';
      if (notice) notice.hidden = false;
      if (form) {
        form.querySelectorAll('input, select, button').forEach(field => {
          field.disabled = true;
        });
      }
      return;
    }

    if (notice) notice.hidden = true;
    if (form) {
      form.querySelectorAll('input, select, button').forEach(field => {
        field.disabled = false;
      });
      const roleSelect = document.getElementById('accountRoleSelect');
      const globalToggle = document.getElementById('accountGlobalAdmin');
      if (globalToggle) {
        globalToggle.disabled = roleSelect?.value !== 'admin';
        if (globalToggle.disabled) globalToggle.checked = false;
      }
    }

    body.replaceChildren();
    if (state.accountsError) {
      body.innerHTML = `<tr><td colspan="8">${escapeHtml(state.accountsError)}</td></tr>`;
      return;
    }
    if (!Array.isArray(state.accounts) || !state.accounts.length) {
      body.innerHTML = '<tr><td colspan="8">Nenhuma conta cadastrada.</td></tr>';
      return;
    }

    const companyFilterWrap = document.getElementById('accountsCompanyFilterWrap');
    const companyFilter = document.getElementById('accountsCompanyFilter');
    const isGeneralAdmin = Boolean(state.accountsMeta.administrador_geral);
    if (companyFilterWrap) companyFilterWrap.hidden = !isGeneralAdmin;
    if (companyFilter && isGeneralAdmin) {
      const selectedCompany = companyFilter.value || 'all';
      const options = ['<option value="all">Todas as empresas</option><option value="global-admin">Administradores gerais</option>'].concat(
        (state.accountsMeta.empresas || []).map(company => `<option value="${Number(company.id)}">${escapeHtml(cleanLabel(company.nome))}</option>`)
      );
      companyFilter.innerHTML = options.join('');
      companyFilter.value = selectedCompany;
    }
    const selectedCompany = companyFilter?.value || 'all';
    const visibleAccounts = selectedCompany === 'all'
      ? state.accounts
      : selectedCompany === 'global-admin'
        ? state.accounts.filter(account => Boolean(account.administrador_geral))
        : state.accounts.filter(account => (account.empresa_ids || []).map(Number).includes(Number(selectedCompany)));

    if (!visibleAccounts.length) {
      body.innerHTML = '<tr><td colspan="8">Nenhuma conta encontrada para esta empresa.</td></tr>';
      return;
    }

    visibleAccounts.forEach(account => {
      const row = document.createElement('tr');
      const operatorView = state.accountsMeta.viewer?.perfil === 'operador';
      const companyAdminView = state.accountsMeta.viewer?.perfil === 'admin' && !state.accountsMeta.administrador_geral;
      const readOnlyTarget = account.perfil === 'leitura';
      const statusClass = account.ativo ? 'success' : 'failed';
      const statusLabel = account.ativo ? 'Ativa' : 'Inativa';
      const accountId = Number(account.id || 0);
      const viewerId = Number(state.accountsMeta.viewer?.id || state.me?.id || 0);
      const canDeleteAccount = accountId !== viewerId && (
        Boolean(state.accountsMeta.administrador_geral)
        || (companyAdminView && ['operador', 'leitura'].includes(account.perfil))
      );
      const roleOptions = Object.entries(state.accountsMeta.perfis || {})
        .filter(([value]) => (!operatorView || value === 'leitura' || value === account.perfil)
          && (!companyAdminView || value !== 'admin' || account.perfil === 'admin'))
        .map(([value, label]) => `<option value="${escapeHtml(value)}"${value === account.perfil ? ' selected' : ''}>${escapeHtml(label)}</option>`)
        .join('');
      const companyNames = Array.isArray(account.empresas) && account.empresas.length
        ? account.empresas.map(item => cleanLabel(item.nome)).join(', ')
        : (account.administrador_geral ? 'Todas (administrador geral)' : 'Nenhuma');
      row.innerHTML = `
        <td><b>${escapeHtml(cleanLabel(account.nome))}</b><small>ID ${fmtInt(account.id)}</small></td>
        <td>${escapeHtml(cleanLabel(account.email))}</td>
        <td>${escapeHtml(companyNames)}</td>
        <td><select class="account-inline-select" data-account-role="${accountId}" aria-label="Perfil"${operatorView && !readOnlyTarget ? ' disabled' : ''}>${roleOptions}</select></td>
        <td><em class="job-state ${statusClass}">${statusLabel}</em></td>
        <td>${formatDateTime(account.ultimo_login_em)}</td>
        <td>${formatDateTime(account.criado_em)}</td>
        <td class="account-actions">
          <button type="button" data-account-save="${accountId}" data-tooltip="Salvar perfil"${operatorView && !readOnlyTarget ? ' disabled' : ''}><i data-lucide="save"></i></button>
          <button type="button" data-account-access="${accountId}" data-tooltip="Gerenciar permissões"${operatorView && !readOnlyTarget ? ' disabled' : ''}><i data-lucide="key-round"></i></button>
          <button type="button" data-account-toggle="${accountId}" data-tooltip="${account.ativo ? 'Inativar conta' : 'Ativar conta'}"${operatorView && !readOnlyTarget ? ' disabled' : ''}><i data-lucide="${account.ativo ? 'user-x' : 'user-check'}"></i></button>
          <button type="button" data-account-password="${accountId}" data-tooltip="Redefinir senha"${operatorView && !readOnlyTarget ? ' disabled' : ''}><i data-lucide="lock-keyhole"></i></button>
          ${canDeleteAccount ? `<button type="button" data-account-delete="${accountId}" data-tooltip="Excluir conta"><i data-lucide="trash-2"></i></button>` : ''}
        </td>
      `;
      body.append(row);
    });
    const companyOptions = document.getElementById('accountCompanyOptions');
    if (companyOptions) {
      const companies = state.accountsMeta.empresas || [];
      const options = ['<option value="">Selecione uma empresa</option>'].concat(
        companies.map(company => `<option value="${Number(company.id)}">${escapeHtml(cleanLabel(company.nome))}</option>`)
      ).join('');
      companyOptions.innerHTML = `<div class="account-company-row"><select name="empresa_ids[]" class="account-company-select"${companies.length ? '' : ' disabled'}>${options}</select><button type="button" data-add-company aria-label="Adicionar outra empresa">+</button></div>`;
    }
    window.lucide?.createIcons();
  };

  const renderIntegrations = () => {
    const list = document.getElementById('integrationRows');
    const notice = document.getElementById('integrationsAccessNotice');
    const form = document.getElementById('integrationForm');
    if (!list) return;

    if (!canManageAdmin()) {
      list.innerHTML = '<div class="accounts-empty">Acesso restrito a administradores.</div>';
      if (notice) notice.hidden = false;
      form?.querySelectorAll('input, select, button').forEach(field => {
        field.disabled = true;
      });
      return;
    }

    if (notice) notice.hidden = true;
    form?.querySelectorAll('input, select, button').forEach(field => {
      field.disabled = false;
    });

    list.replaceChildren();
    if (state.integrationsError) {
      list.innerHTML = `<div class="accounts-empty">${escapeHtml(state.integrationsError)}</div>`;
      return;
    }
    if (!state.integrations.length) {
      list.innerHTML = '<div class="accounts-empty">Nenhuma integracao salva. Ambiente atual ainda usa variaveis do servidor.</div>';
      return;
    }

    state.integrations.forEach(item => {
      const card = document.createElement('article');
      card.className = `integration-card${item.active ? ' is-active' : ''}`;
      card.innerHTML = `
        <div>
          <span>${escapeHtml(item.region || 'API')}</span>
          <h3>${escapeHtml(item.name || 'Acronis')}</h3>
          <p>${escapeHtml(item.base_url || '--')}</p>
          <small>ID: ${escapeHtml(item.client_id || '--')} · Secret: ${item.secret_set ? 'salvo' : 'ausente'}</small>
        </div>
        <div class="integration-actions">
          <em class="job-state ${item.active ? 'success' : 'queued'}">${item.active ? 'Ativo' : 'Inativo'}</em>
          <button type="button" data-integration-edit="${escapeHtml(item.id)}"><i data-lucide="pencil"></i><span>Editar</span></button>
          <button type="button" data-integration-activate="${escapeHtml(item.id)}">${item.active ? '<i data-lucide="power-off"></i><span>Desativar</span>' : '<i data-lucide="power"></i><span>Ativar</span>'}</button>
          <button type="button" data-integration-delete="${escapeHtml(item.id)}"><i data-lucide="trash-2"></i><span>Remover</span></button>
        </div>
      `;
      list.append(card);
    });
    window.lucide?.createIcons();
  };

  const renderAudit = () => {
    const body = document.getElementById('auditRows');
    if (!body) return;

    body.replaceChildren();
    if (state.auditError) {
      body.innerHTML = `<tr><td colspan="6">${escapeHtml(state.auditError)}</td></tr>`;
      return;
    }
    if (!Array.isArray(state.audit) || !state.audit.length) {
      body.innerHTML = '<tr><td colspan="6">Nenhum evento de auditoria encontrado.</td></tr>';
      return;
    }

    state.audit.forEach(event => {
      const details = Object.entries(event.detalhes || {})
        .filter(([, value]) => value !== null && value !== '')
        .map(([key, value]) => `${key}: ${String(value)}`)
        .join(' | ');
      const row = document.createElement('tr');
      row.innerHTML = `
        <td>${formatDateTime(event.criado_em)}</td>
        <td><b>${escapeHtml(cleanLabel(event.acao))}</b></td>
        <td>${escapeHtml(cleanLabel(event.ator?.nome))}<small>${escapeHtml(cleanLabel(event.ator?.email))}</small></td>
        <td>${escapeHtml(cleanLabel(event.alvo?.nome))}<small>${escapeHtml(cleanLabel(event.alvo?.email))}</small></td>
        <td class="mono">${escapeHtml(cleanLabel(event.ip))}</td>
        <td>${escapeHtml(details || '--')}</td>
      `;
      body.append(row);
    });
  };

  const excelCompanyLabels = {
    'aziz': 'AZIZ',
    'caelmomococa': 'CAELMO',
    'caelmo': 'CAELMO',
    'caelmoinsteletrica': 'INSTELETRICA',
    'casamarquesmoc': 'CASA MARQUES',
    'casamarquesmococa': 'CASA MARQUES MOCOCA',
    'fedk': 'FEDK',
    'concrepool': 'CONCREPOOL',
    'dafloramococa': 'DAFLORA',
    'depauli': 'DE PAULI',
    'frigoservice': 'FRIGO SERVICE',
    'guidorizzimococa': 'GUIDORIZZI',
    'guidorizziss': 'GUIDORIZZISS',
    'gplussistemas': 'GPLUS SISTEMAS',
    'jsgas': 'JS GAS',
    'laboratoriosaosebastiao': 'LABORATORIO SAO SEBASTIAO',
    'lojamirtes': 'LOJA MIRTES',
    'madeireiramocoquense': 'MADEIREIRA MOCOQUENSE',
    'marestdistribuidoramg': 'MAREST DISTRIBUIDORA MG',
    'marestmg': 'MAREST MG',
    'marestdistribuidorasp': 'MAREST DISTIBUIDORA SP',
    'marestsp': 'MAREST SP',
    'microcenter': 'MICROCENTER',
    'mlacouros': 'MLA - COUROS',
    'mptintas': 'MP TINTAS - MOCOCA',
    'wtsolucoesemtecnologia': 'WT SOLUCOES EM TECNOLOGIA',
    'hering': 'HERING'
  };

  const excelPlanLabels = {
    'bdsigcomaziz': 'BD-SIGCOM | AZIZ',
    'bdsigcomhponto': 'BD-SIGCOM | HPONTO',
    'bdsigcomcaelmo': 'BD-SIGCOM-CAELMO',
    'bdsigcomcaelmoinsteletrica': 'BD-SIGCOM-CAELMO INSTELETRICA',
    'bksigcomfedk': 'BKP-SIGCOM-FEDK',
    'bdsigcommoc': 'BD-SIGCOM MOC',
    'bdsigcomrib': 'BD-SIGCOM RIB',
    'bdsigcomgxp': 'BD-SIGCOM GXP',
    'bdsigcomf': 'BD-SIGCOM - F',
    'bdsigcomg': 'BD-SIGCOM - G',
    'bdsigcomfs': 'BD-SIGCOM-FS',
    'bdsigcomloja': 'BD-SIGCOM - LOJA',
    'bdsigcomindustria': 'BD-SIGCOM - INDUSTRIA',
    'bdsigcomguidorizziss': 'BD-SIGCOM-GUIDORIZZISS',
    'bdacommerce': 'BD-ACOMMERCE',
    'bdpleres': 'BD-PLERES',
    'bkpcc': 'BKP - C:',
    'autcom': 'AUTCOM',
    'bdoracle': 'BD-ORACLE',
    'bdpostgres': 'BD-POSTGRES',
    'bdhssaturno': 'BD-HSSATURNO'
  };

  const normalizeLookupKey = value => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/\([^)]*\)/g, '')
    .replace(/[^a-z0-9]+/g, '');

  const displayCompanyName = value => {
    const text = cleanLabel(value);
    return excelCompanyLabels[normalizeLookupKey(text)] || text;
  };

  const displayPlanName = value => {
    const text = cleanLabel(value);
    return excelPlanLabels[normalizeLookupKey(text)] || text;
  };

  const executionWindowMeta = status => {
    switch (String(status || '').toLowerCase()) {
      case 'success':
        return { className: 'success', label: 'Meta atingida' };
      case 'warning':
        return { className: 'queued', label: 'Abaixo da meta' };
      case 'failed':
        return { className: 'failed', label: 'Sem execucao' };
      default:
        return { className: 'queued', label: 'Referencia historica' };
    }
  };

  const alertLocation = alert => cleanLabel(alert.origem || alert.maquina || alert.raw?.resourceName || 'Local nao informado');
  const alertCause = alert => cleanLabel(alert.causa || alert.mensagem || 'Causa nao informada');
  const alertResource = alert => cleanLabel(alert.recurso || alert.maquina || 'Recurso nao informado');
  const alertsInLastDays = (days = 30) => {
    const today = new Date();
    const end = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1);
    const start = new Date(today.getFullYear(), today.getMonth(), today.getDate() - (days - 1));
    return state.alerts.filter(alert => {
      const dateText = String(alert.data || '').trim();
      const timeText = String(alert.hora || '00:00:00').trim();
      if (!dateText) return true;
      const eventDate = new Date(dateText.includes('T') ? dateText : `${dateText}T${timeText}`);
      return !Number.isNaN(eventDate.getTime()) && eventDate >= start && eventDate < end;
    });
  };

  const resolveCustomer = device => {
    const raw = device?.raw || {};
    const tenantCandidates = [
      raw.tenant_uuid,
      raw.tenant?.uuid,
      raw.tenant,
      raw.tenant_id,
      raw.tenant?.id,
      device.tenant,
      device.customer_tenant
    ].filter(Boolean).map(String);

    return state.customers.find(item => {
      const values = [
        item.tenant,
        item.id,
        item.uuid,
        item.raw?.id,
        item.raw?.uuid,
        item.raw?.tenant_id
      ].filter(Boolean).map(String);

      return tenantCandidates.some(candidate => values.includes(candidate));
    }) || null;
  };

  const buildRecentRows = () => {
    const tbody = document.querySelector('#recent tbody');
    if (!tbody) {
      return;
    }

    tbody.replaceChildren();
    const query = String(state.executionSearch || state.globalSearch || '').trim().toLocaleLowerCase('pt-BR');
    const items = Array.isArray(state.devices)
      ? state.devices
        .filter(device => normalizeText(device.hostname) !== '--')
        .filter(device => !query || [
          device.cliente,
          resolveCustomer(device)?.nome,
          device.hostname,
          device.ip,
          device.endereco_ip,
          ...extractPlans(device)
        ].map(normalizeText).join(' ').toLocaleLowerCase('pt-BR').includes(query))
        .filter(device => state.executionStatus === 'all' || statusMeta(device.status || device.situacao || '').className === state.executionStatus)
        .sort((a, b) => {
          if (state.executionSort === 'client') {
            return displayCompanyName(a.cliente || resolveCustomer(a)?.nome || '').localeCompare(displayCompanyName(b.cliente || resolveCustomer(b)?.nome || ''), uiLocale());
          }
          const difference = new Date(b.ultimo_backup || 0).getTime() - new Date(a.ultimo_backup || 0).getTime();
          return state.executionSort === 'oldest' ? -difference : difference;
        })
        .slice(0, 12)
      : [];

    if (!items.length) {
      const tr = document.createElement('tr');
      const title = state.devicesLoading ? 'Carregando dispositivos' : state.devicesError ? 'Falha ao carregar' : query ? 'Nenhum resultado' : 'Sem dispositivos';
      const detail = state.devicesLoading ? 'Consultando inventario e tarefas no Acronis' : state.devicesError || (query ? 'Nenhuma maquina corresponde a pesquisa.' : 'Nenhum equipamento retornado pela API');
      const status = state.devicesLoading ? 'Carregando' : state.devicesError ? 'Falha' : 'Sem dados';
      tr.innerHTML = `<td><b>${title}</b><small>${detail}</small></td><td>--</td><td>--</td><td class="mono">--</td><td><em class="job-state ${state.devicesError ? 'failed' : 'queued'}">${status}</em></td><td>--</td><td>--</td><td>--</td>`;
      tbody.append(tr);
      return;
    }

    items.forEach(device => {
      const status = statusMeta(device.status || device.situacao || '');
      const customerName = displayCompanyName(device.cliente || resolveCustomer(device)?.nome || device.customer_name || device.raw?.tenant?.name || 'Cliente');
      const plans = extractPlans(device);
      const planData = planEntries(device);
      const firstPlan = planData[0] || {
        plano: normalizeText(plans[0] || 'Sem plano'),
        ultimo_backup: device.ultimo_backup || device.last_backup || '',
        dias_sem_backup: Number.isInteger(device?.dias_sem_backup) ? device.dias_sem_backup : null,
        tamanho_realizado: backupSizeLabel(device),
        status: device.status || device.situacao || 'unknown'
      };
      const rowGroupId = `plan-group-${normalizeText(device.hostname).replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`;
      const tr = document.createElement('tr');
      const arrow = plans.length > 1
        ? `<button class="plan-toggle" type="button" data-plan-toggle="${rowGroupId}" aria-expanded="false" aria-label="Mostrar mais planos"><span class="plan-toggle-icon">v</span></button>`
        : '<span class="plan-toggle-spacer"></span>';

      tr.className = plans.length > 1 ? 'has-extra-plans' : '';
      tr.innerHTML = [
        `<td><div class="plan-cell-main">${arrow}<div><b>${customerName}</b><small>${normalizeText(device.sistema_operacional || device.tipo || 'Infraestrutura')}</small></div></div></td>`,
        `<td>${normalizeText(device.hostname)}</td>`,
        `<td><span class="plan-pill">${displayPlanName(firstPlan.plano)}</span></td>`,
        `<td class="mono">${toShortTime(firstPlan.ultimo_backup || device.ultimo_backup || device.last_backup)}</td>`,
        `<td><em class="job-state ${statusMeta(firstPlan.status || device.status || device.situacao || '').className}">${statusMeta(firstPlan.status || device.status || device.situacao || '').label}</em></td>`,
        `<td>${backupDaysFromEntry(firstPlan)}</td>`,
        `<td>${normalizeText(firstPlan.tamanho_realizado)}</td>`,
        `<td>${normalizeText(device.ip || device.endereco_ip)}</td>`
      ].join('');
      tbody.append(tr);

      plans.slice(1).forEach((plan, index) => {
        const detail = planData[index + 1] || {
          plano: normalizeText(plan),
          ultimo_backup: device.ultimo_backup || device.last_backup || '',
          dias_sem_backup: Number.isInteger(device?.dias_sem_backup) ? device.dias_sem_backup : null,
          tamanho_realizado: backupSizeLabel(device),
          status: device.status || device.situacao || 'unknown'
        };
        const extraRow = document.createElement('tr');
        extraRow.className = 'plan-child-row';
        extraRow.dataset.planGroup = rowGroupId;
        extraRow.hidden = true;
        extraRow.innerHTML = [
          `<td><div class="plan-child-label"><span class="plan-branch"></span><small>Plano adicional</small></div></td>`,
          `<td>${normalizeText(device.hostname)}</td>`,
          `<td><span class="plan-pill is-secondary">${displayPlanName(detail.plano)}</span></td>`,
          `<td class="mono">${toShortTime(detail.ultimo_backup || device.ultimo_backup || device.last_backup)}</td>`,
          `<td><em class="job-state ${statusMeta(detail.status || device.status || device.situacao || '').className}">${statusMeta(detail.status || device.status || device.situacao || '').label}</em></td>`,
          `<td>${backupDaysFromEntry(detail)}</td>`,
          `<td>${normalizeText(detail.tamanho_realizado)}</td>`,
          `<td>${normalizeText(device.ip || device.endereco_ip)}</td>`
        ].join('');
        tbody.append(extraRow);
      });
    });

    tbody.querySelectorAll('[data-plan-toggle]').forEach(button => {
      button.addEventListener('click', () => {
        const group = button.dataset.planToggle;
        const rows = tbody.querySelectorAll(`[data-plan-group="${group}"]`);
        const expanded = button.getAttribute('aria-expanded') === 'true';
        rows.forEach(row => {
          row.hidden = expanded;
        });
        button.setAttribute('aria-expanded', String(!expanded));
        button.classList.toggle('is-open', !expanded);
      });
    });
  };

  const buildClientDirectory = () => {
    const node = document.getElementById('clientsDirectory');
    if (!node) return;
    const query = String(state.clientsSearch || '').toLocaleLowerCase('pt-BR');
    const customers = Array.isArray(state.customers) ? state.customers : [];
    const devices = Array.isArray(state.devices) ? state.devices : [];
    const rows = customers.map(customer => {
      const customerName = displayCompanyName(customer.nome || 'Cliente');
      const customerDevices = devices.filter(device => {
        const resolved = resolveCustomer(device);
        if (resolved === customer) return true;
        return normalizeText(device.cliente).toLocaleLowerCase('pt-BR') === normalizeText(customer.nome).toLocaleLowerCase('pt-BR');
      });
      const backupEntries = customerDevices.flatMap(device => planEntries(device).map(entry => ({
        ...entry,
        hostname: normalizeText(device.hostname),
        deviceStatus: device.status || device.situacao || 'unknown'
      })));
      backupEntries.sort((left, right) => new Date(right.ultimo_backup || 0).getTime() - new Date(left.ultimo_backup || 0).getTime());
      const latest = backupEntries.find(entry => entry.ultimo_backup) || null;
      const detailedTimestamp = Date.parse(latest?.ultimo_backup || '') || 0;
      const aggregateTimestamp = Date.parse(customer.ultimo_backup || '') || 0;
      const hasDetailedSource = Boolean(latest && detailedTimestamp >= aggregateTimestamp);
      const lastBackup = detailedTimestamp >= aggregateTimestamp ? (latest?.ultimo_backup || '') : (customer.ultimo_backup || '');
      const deviceCount = Math.max(Number(customer.quantidade_dispositivos || 0), customerDevices.length);
      const status = hasDetailedSource
        ? statusMeta(latest.status || latest.deviceStatus)
        : { className: lastBackup ? 'queued' : 'failed', label: lastBackup ? 'Registrado' : 'Sem histórico' };
      return {
        customer,
        customerName,
        deviceCount,
        lastBackup,
        hostname: hasDetailedSource ? (latest?.hostname || '--') : '--',
        plan: hasDetailedSource ? (latest?.plano || '--') : (customer.plano || '--'),
        size: hasDetailedSource ? (latest?.tamanho_realizado || '--') : '--',
        status,
        searchText: [
          customerName,
          customer.tenant,
          ...customerDevices.flatMap(device => [device.hostname, ...extractPlans(device)])
        ].map(normalizeText).join(' ').toLocaleLowerCase('pt-BR')
      };
    });
    const totalDevices = rows.reduce((sum, row) => sum + row.deviceCount, 0);
    const coveredClients = rows.filter(row => row.lastBackup).length;
    setText('clientsTotalCount', fmtInt(rows.length));
    setText('clientsDeviceCount', fmtInt(totalDevices));
    setText('clientsBackupCoverage', rows.length ? `${Math.round((coveredClients / rows.length) * 100)}%` : '0%');
    setText('clientsBackupCoverageHint', `${fmtInt(coveredClients)} de ${fmtInt(rows.length)} clientes`);

    const items = rows
      .filter(item => !query || item.searchText.includes(query))
      .sort((left, right) => {
        if (state.clientsSort === 'recent') return new Date(right.lastBackup || 0).getTime() - new Date(left.lastBackup || 0).getTime();
        if (state.clientsSort === 'devices') return right.deviceCount - left.deviceCount || left.customerName.localeCompare(right.customerName, uiLocale());
        return left.customerName.localeCompare(right.customerName, uiLocale());
      });
    setText('clientsTotalLabel', query ? `${fmtInt(items.length)} encontrados` : `${fmtInt(rows.length)} clientes`);
    node.replaceChildren();
    if (!items.length) {
      node.innerHTML = `<div class="clients-empty"><b>${query ? 'Nenhum cliente encontrado' : 'Nenhum cliente disponível'}</b><small>${query ? 'Ajuste o termo de busca para ver outros clientes.' : 'A API ainda não retornou empresas para este ambiente.'}</small></div>`;
      return;
    }
    const heading = document.createElement('div');
    heading.className = 'client-directory-head';
    heading.innerHTML = '<span>Cliente</span><span>Dispositivos</span><span>Último backup</span><span>Origem</span><span>Status</span><span>Volume</span>';
    node.append(heading);
    items.forEach(item => {
      const row = document.createElement('div');
      row.className = 'client-directory-row';
      const relativeBackup = item.lastBackup ? (backupDaysFromEntry({ ultimo_backup: item.lastBackup }) === '0 dias' ? 'hoje' : `há ${backupDaysFromEntry({ ultimo_backup: item.lastBackup })}`) : 'sem histórico';
      row.innerHTML = [
        `<div class="client-identity" data-label="Cliente"><span class="client-avatar">${escapeHtml(profileInitial(item.customerName))}</span><span><b>${escapeHtml(item.customerName)}</b><small>${escapeHtml(item.customer.tenant || 'Ambiente protegido')}</small></span></div>`,
        `<div class="client-device-total" data-label="Dispositivos"><strong>${fmtInt(item.deviceCount)}</strong><small>protegidos</small></div>`,
        `<div class="client-last-backup" data-label="Último backup"><time>${escapeHtml(toShortTime(item.lastBackup))}</time><small>${escapeHtml(relativeBackup)}</small></div>`,
        `<div class="client-backup-source" data-label="Origem"><b>${escapeHtml(item.hostname)}</b><small>${escapeHtml(displayPlanName(item.plan))}</small></div>`,
        `<div data-label="Status"><em class="job-state ${item.status.className}">${escapeHtml(item.lastBackup ? item.status.label : 'Sem backup')}</em></div>`,
        `<div class="client-backup-size" data-label="Volume"><b>${escapeHtml(item.size)}</b></div>`
      ].join('');
      node.append(row);
    });
  };

  const buildDailyExecutionRows = () => {
    const payload = state.dailyExecutions;
    const groups = [
      ['hoje', 'todayExecutionRows', 'todayExecutionDate', 'todayExecutionCount'],
      ['ontem', 'yesterdayExecutionRows', 'yesterdayExecutionDate', 'yesterdayExecutionCount']
    ];

    groups.forEach(([key, listId, dateId, countId]) => {
      const list = document.getElementById(listId);
      if (!list) return;
      const query = String(state.globalSearch || '').trim().toLocaleLowerCase('pt-BR');
      const sourceRows = Array.isArray(payload?.[key]) ? payload[key] : [];
      const rows = query
        ? sourceRows.filter(item => [item.cliente, item.hostname, item.plano, item.ip].map(normalizeText).join(' ').toLocaleLowerCase('pt-BR').includes(query))
        : sourceRows;
      const date = payload?.datas?.[key] ? new Date(`${payload.datas[key]}T12:00:00`) : null;
      setText(dateId, date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString(uiLocale()) : '--');
      setText(countId, `${fmtInt(rows.length)} ${query ? 'encontrados' : 'registros'}`);
      list.replaceChildren();

      if (state.dailyExecutionsError) {
        setText(dateId, '--');
        setText(countId, '--');
        list.innerHTML = `<div class="daily-device-empty">${state.dailyExecutionsError}</div>`;
        return;
      }

      if (!payload) {
        list.innerHTML = '<div class="daily-device-empty">Carregando execucoes...</div>';
        return;
      }
      if (!rows.length) {
        list.innerHTML = `<div class="daily-device-empty">${query ? 'Nenhum dispositivo corresponde a pesquisa.' : 'Nenhuma execucao encontrada neste dia.'}</div>`;
        return;
      }

      rows.forEach(item => {
        const status = statusMeta(item.status);
        const row = document.createElement('div');
        row.className = 'daily-device-row';
        row.innerHTML = `<div class="daily-device-main"><b>${displayCompanyName(item.cliente)}</b><span>${normalizeText(item.hostname)} - <em>${displayPlanName(item.plano)}</em></span></div><time>${toShortTime(item.ultimo_backup).split(', ')[1] || toShortTime(item.ultimo_backup)}</time><span class="daily-device-size">${normalizeText(item.tamanho)}</span><span class="daily-device-ip">${normalizeText(item.ip)}</span><em class="job-state ${status.className}">${status.label}</em>`;
        list.append(row);
      });
    });
  };

  const buildExecutionWindowRows = () => {
    const summary = document.getElementById('windowsSummary');
    const list = document.getElementById('windowsList');
    if (!summary || !list) {
      return;
    }

    const payload = state.executionWindows;
    const sourceItems = Array.isArray(payload?.items) ? payload.items : [];
    const query = normalizeText(state.windowsSearch).toLowerCase();
    const items = query === '--'
      ? sourceItems
      : sourceItems.filter(item => normalizeText(item.empresa).toLowerCase().includes(query));
    list.replaceChildren();

    if (state.executionWindowsError) {
      summary.textContent = state.executionWindowsError;
      list.innerHTML = '<div class="window-card is-empty"><div><b>Falha ao carregar janelas</b><small>Tente abrir esta secao novamente.</small></div></div>';
      return;
    }

    if (!items.length) {
      summary.textContent = query === '--'
        ? 'Nenhuma janela retornada pela API. Se quiser meta fixa por plano, eu posso preencher as regras locais com os horarios exatos.'
        : `Nenhum cliente encontrado para "${state.windowsSearch}".`;
      const empty = document.createElement('div');
      empty.className = 'window-card is-empty';
      empty.innerHTML = query === '--'
        ? '<div><b>Sem janelas configuradas</b><small>O painel pode trabalhar com regras manuais ou referencia historica real.</small></div>'
        : '<div><b>Sem resultado</b><small>Ajuste o nome do cliente para localizar a janela correta.</small></div>';
      list.append(empty);
      return;
    }

    const parseWindowStart = value => {
      const match = String(value || '').match(/^(\d{2}):(\d{2})/);
      if (!match) return Number.MAX_SAFE_INTEGER;
      return (Number(match[1]) * 60) + Number(match[2]);
    };

    const sortedItems = [...items].sort((left, right) => {
      const companyCompare = displayCompanyName(normalizeText(left.empresa) || '').localeCompare(
        displayCompanyName(normalizeText(right.empresa) || ''),
        'pt-BR'
      );
      if (companyCompare !== 0) return companyCompare;

      const windowCompare = parseWindowStart(left.janela) - parseWindowStart(right.janela);
      if (windowCompare !== 0) return windowCompare;

      return displayPlanName(left.plano || '').localeCompare(displayPlanName(right.plano || ''), uiLocale());
    });

    const companyGroups = sortedItems.reduce((groups, item) => {
      const companyName = displayCompanyName(normalizeText(item.empresa) || 'Cliente sem nome');
      if (!groups.has(companyName)) {
        groups.set(companyName, []);
      }
      groups.get(companyName).push(item);
      return groups;
    }, new Map());

    summary.textContent = payload.mode === 'rules'
      ? `Comparando ${items.length} planos em ${companyGroups.size} empresas para ${payload.date}, usando regras reais e execucoes da Acronis.`
      : payload.mode === 'mixed'
        ? `Mostrando ${items.length} planos em ${companyGroups.size} empresas para ${payload.date}, combinando regras reais dos planos com execucoes reais da Acronis.`
        : `Sem regras fixas ainda. Mostrando ${items.length} referencias historicas reais em ${companyGroups.size} empresas para ${payload.date}.`;

    companyGroups.forEach((companyItems, companyName) => {
      const companyCard = document.createElement('section');
      companyCard.className = 'company-window-group';

      const machineLabels = Array.from(new Set(
        companyItems
          .map(item => normalizeText(item.maquina))
          .filter(Boolean)
      ));
      const completedPlans = companyItems.filter(item => Number(item.faltando || 0) <= 0).length;
      const companyMeta = companyItems.some(item => Number(item.faltando || 0) > 0)
        ? executionWindowMeta('warning')
        : executionWindowMeta('success');

      companyCard.innerHTML = [
        '<div class="company-window-head">',
        `<div><b>${companyName}</b><small>${machineLabels.join(' - ') || 'Maquina nao identificada'}</small></div>`,
        `<div class="company-window-meta"><span>${fmtInt(companyItems.length)} plano(s)</span><em class="job-state ${companyMeta.className}">${fmtInt(completedPlans)}/${fmtInt(companyItems.length)} completos</em></div>`,
        '</div>',
        '<div class="company-window-grid"></div>'
      ].join('');

      const grid = companyCard.querySelector('.company-window-grid');
      companyItems.forEach(item => {
        const meta = executionWindowMeta(item.status);
        const expectedTimes = Array.isArray(item.horarios_esperados) ? item.horarios_esperados : [];
        const missingTimes = Array.isArray(item.horarios_nao_feitos) ? item.horarios_nao_feitos : [];
        const expectedMarkup = expectedTimes.length
          ? `<div class="window-expected"><small>Horarios previstos</small><div class="window-badges">${expectedTimes.map(time => `<span class="window-badge">${time}</span>`).join('')}</div></div>`
          : '';
        const missingMarkup = missingTimes.length
          ? `<div class="window-missing"><small>Horarios nao feitos</small><div class="window-badges">${missingTimes.map(time => `<span class="window-badge is-missing">${time}</span>`).join('')}</div></div>`
          : `<div class="window-missing"><small>Horarios nao feitos</small><div class="window-badges"><span class="window-badge is-ok">Nenhum</span></div></div>`;

        const card = document.createElement('article');
        card.className = 'window-card';
        card.innerHTML = [
          '<div class="window-head">',
          `<div><b>${displayPlanName(item.plano)}</b><small>${normalizeText(item.maquina) || 'Maquina nao identificada'}</small></div>`,
          `<em class="job-state ${meta.className}">${meta.label}</em>`,
          '</div>',
          '<div class="window-grid">',
          `<span><small>Janela</small><b>${normalizeText(item.janela)}</b></span>`,
          `<span><small>Meta</small><b>${fmtInt(item.meta || 0)}</b></span>`,
          `<span><small>Realizado</small><b>${fmtInt(item.realizado || 0)}</b></span>`,
          `<span><small>Faltando</small><b>${fmtInt(item.faltando || 0)}</b></span>`,
          '</div>',
          expectedMarkup,
          missingMarkup,
          `<div class="window-foot"><span>${item.base === 'rule' ? 'Regra manual' : 'Referencia historica real'}</span><time>${toShortTime(item.ultimo_backup)}</time></div>`
        ].join('');
        grid?.append(card);
      });

      list.append(companyCard);
    });
    return;

    items.forEach(item => {
      const meta = executionWindowMeta(item.status);
      const plans = Array.isArray(item.planos) ? item.planos : [];
      const missingTimes = Array.isArray(item.horarios_nao_feitos) ? item.horarios_nao_feitos : [];
      const missingMarkup = missingTimes.length
        ? `<div class="window-missing"><small>Horarios nao feitos</small><div class="window-badges">${missingTimes.map(time => `<span class="window-badge is-missing">${time}</span>`).join('')}</div></div>`
        : `<div class="window-missing"><small>Horarios nao feitos</small><div class="window-badges"><span class="window-badge is-ok">Nenhum</span></div></div>`;
      const plansMarkup = plans.length
        ? `<div class="window-plans">${plans.map(plan => {
          const planMeta = executionWindowMeta(plan.status);
          const planMissingTimes = Array.isArray(plan.horarios_nao_feitos) ? plan.horarios_nao_feitos : [];
          const planMissing = planMissingTimes.length
            ? `<div class="window-plan-missing">${planMissingTimes.map(time => `<span class="window-badge is-missing">${time}</span>`).join('')}</div>`
            : '<div class="window-plan-missing"><span class="window-badge is-ok">Completo</span></div>';
          return `<div class="window-plan-row"><div><div class="window-plan-name">${displayPlanName(plan.nome)}</div>${planMissing}</div><div class="window-plan-stats"><span>${fmtInt(plan.realizado || 0)}/${fmtInt(plan.meta || 0)}</span><em class="job-state ${planMeta.className}">${planMeta.label}</em></div></div>`;
        }).join('')}</div>`
        : `<div class="window-plan">${displayPlanName(item.plano)}</div>`;
      const card = document.createElement('article');
      card.className = 'window-card';
      card.innerHTML = [
        '<div class="window-head">',
        `<div><b>${normalizeText(item.empresa)}</b><small>${normalizeText(item.maquina)}${Number(item.planos_count || 0) > 1 ? ` · ${fmtInt(item.planos_count)} planos` : ''}</small></div>`,
        `<em class="job-state ${meta.className}">${meta.label}</em>`,
        '</div>',
        plansMarkup,
        '<div class="window-grid">',
        `<span><small>Janela</small><b>${normalizeText(item.janela)}</b></span>`,
        `<span><small>Meta</small><b>${fmtInt(item.meta || 0)}</b></span>`,
        `<span><small>Realizado</small><b>${fmtInt(item.realizado || 0)}</b></span>`,
        `<span><small>Faltando</small><b>${fmtInt(item.faltando || 0)}</b></span>`,
        '</div>',
        missingMarkup,
        `<div class="window-foot"><span>${item.base === 'rule' ? 'Regra manual' : 'Referencia historica real'}</span><time>${toShortTime(item.ultimo_backup)}</time></div>`
      ].join('');
      list.append(card);
    });
  };

  const windowRulesShape = payload => ({
    timezone: payload?.timezone || 'America/Sao_Paulo',
    rules: Array.isArray(payload?.rules) ? payload.rules : []
  });

  const renderWindowRulesEditor = () => {
    const editor = document.getElementById('windowRulesEditor');
    const meta = document.getElementById('windowRulesMeta');
    const message = document.getElementById('windowRulesMessage');
    const payload = state.windowRules;
    if (!editor || !meta || !payload) return;

    editor.value = JSON.stringify(windowRulesShape(payload), null, 2);
    meta.textContent = `${payload.rules?.length || 0} regra(s) - fonte ${payload.source || 'json'} - versao ${payload.version || '--'}`;
    if (message) message.textContent = state.windowRulesError || 'Edite com cuidado. Cada salvamento cria uma versao local.';
  };

  const loadWindowRules = async () => {
    state.windowRulesError = '';
    try {
      state.windowRules = await fetchJson('window-rules.php', { timeout: 8000 });
      renderWindowRulesEditor();
    } catch (error) {
      state.windowRulesError = error.message || 'Falha ao carregar regras.';
      setText('windowRulesMessage', state.windowRulesError);
      throw error;
    }
  };

  const saveWindowRules = async () => {
    const editor = document.getElementById('windowRulesEditor');
    const button = document.getElementById('saveWindowRules');
    if (!editor) return;

    let payload;
    try {
      payload = JSON.parse(editor.value);
    } catch (error) {
      setText('windowRulesMessage', 'JSON invalido. Corrija antes de salvar.');
      return;
    }

    button?.setAttribute('disabled', 'disabled');
    setText('windowRulesMessage', 'Salvando regras...');
    try {
      state.windowRules = await fetchJson('window-rules.php', { method: 'PUT', body: payload, timeout: 10000 });
      renderWindowRulesEditor();
      dataRequests.delete('execution-windows');
      dataRequests.delete('execution-windows-stale');
      await loadExecutionWindows();
      notify('Regras de janelas salvas.');
    } catch (error) {
      setText('windowRulesMessage', error.message || 'Falha ao salvar regras.');
      notify(error.message || 'Falha ao salvar regras.');
    } finally {
      button?.removeAttribute('disabled');
    }
  };

  const updateHero = () => {
    const dashboard = state.dashboard;
    if (!dashboard) {
      return;
    }

    setText('metricSuccess', fmtPercent(dashboard.taxa_sucesso || 0));
    setText('metricClients', fmtInt(dashboard.total_clientes || state.customers.length || 0));
    setText('metricDevices', fmtInt(dashboard.total_dispositivos || state.devices.length || 0));
    const operationDate = document.getElementById('operationDate');
    if (operationDate) {
      const label = new Date().toLocaleDateString(uiLocale(), { day: '2-digit', month: 'long', year: 'numeric' });
      operationDate.innerHTML = `<span class="pulse"></span> OPERAÇÃO AO VIVO · ${label.toUpperCase()}`;
    }

    const failures = Number(dashboard.backups_com_falha || 0);
    const recentAlerts = alertsInLastDays();
    const principalAlerta = recentAlerts[0] || null;
    setText('signalTitle', failures > 0 ? 'Operação com pontos de atenção' : 'Operação estável');
    setText(
      'signalText',
      failures > 0
        ? `${fmtInt(failures)} falhas exigem revisão. Principal ponto: ${principalAlerta ? `${alertLocation(principalAlerta)} · ${alertCause(principalAlerta)}` : 'verificar alertas abertos'}.`
        : 'Os backups recentes indicam um ambiente protegido e sem falhas críticas agora.'
    );

    const executionCount = document.querySelector('.rail-item[data-section="executions"] em');
    const alertCount = document.querySelector('.danger-count');
    if (executionCount) executionCount.textContent = Number(dashboard.total_backups || 0).toLocaleString(uiLocale());
    if (alertCount) alertCount.textContent = state.alertsLoaded ? Number(recentAlerts.length).toLocaleString(uiLocale()) : '--';
  };

  const updateKpis = () => {
    const dashboard = state.dashboard;
    if (!dashboard) {
      return;
    }

    const values = [
      Number(dashboard.backups_ok || 0),
      Number(dashboard.total_backups || 0),
      Number(dashboard.backups_com_falha || 0)
    ];

    document.querySelectorAll('.kpi-tile').forEach((tile, index) => {
      delete tile.dataset.display;
      if (index === 3) {
        tile.dataset.display = dashboard.armazenamento_disponivel === false
          ? 'Indisponivel'
          : formatBytes(Number(dashboard.espaco_utilizado || 0));
        delete tile.dataset.count;
      } else {
        tile.dataset.count = String(values[index] || 0);
      }
      const number = tile.querySelector('.kpi-number');
      if (number) {
        number.textContent = '--';
      }
    });

    setText('kpiHintA', `${fmtInt(dashboard.backups_ok || 0)} execucoes concluidas com sucesso.`);
    setText('kpiHintB', `${fmtInt(dashboard.total_backups || 0)} backups contabilizados no periodo.`);
    setText('kpiHintC', `${fmtInt(dashboard.backups_com_falha || 0)} ocorrências registradas no período.`);
    setText(
      'kpiHintD',
      dashboard.armazenamento_disponivel === false
        ? 'A Acronis nao retornou o consumo de armazenamento.'
        : `${formatBytes(dashboard.espaco_utilizado || 0)} consumidos no ambiente protegido.`
    );
  };

  const renderOverviewInsights = () => {
    const dashboard = state.dashboard || {};
    const customers = Array.isArray(state.customers) ? state.customers : [];
    const failures = Number(dashboard.backups_com_falha || 0);
    setText('overviewFailures', fmtInt(failures));
    setText('overviewFailuresHint', failures > 0 ? 'Abra os alertas e priorize as falhas recorrentes.' : 'Nenhuma falha identificada no período atual.');

    const dayMs = 86400000;
    const freshness = customers.reduce((summary, customer) => {
      const timestamp = Date.parse(customer.ultimo_backup || '');
      if (!timestamp) {
        summary.stale += 1;
        summary.withoutHistory += 1;
        return summary;
      }
      summary.covered += 1;
      const age = Math.max(0, (Date.now() - timestamp) / dayMs);
      if (age <= 1) summary.fresh += 1;
      else if (age <= 7) summary.watch += 1;
      else {
        summary.stale += 1;
        summary.olderThanWeek += 1;
      }
      return summary;
    }, { fresh: 0, watch: 0, stale: 0, covered: 0, withoutHistory: 0, olderThanWeek: 0 });

    setText('overviewStaleClients', customers.length ? fmtInt(freshness.withoutHistory) : '--');
    setText(
      'overviewStaleClientsHint',
      customers.length
        ? `${fmtInt(freshness.olderThanWeek)} com último backup há mais de 7 dias.`
        : 'Aguardando a lista de clientes da Acronis.'
    );
    setText('overviewCoveredClients', customers.length ? fmtInt(freshness.covered) : '--');
    setText('overviewCoverageSummary', customers.length ? `de ${fmtInt(customers.length)} clientes monitorados` : 'Aguardando clientes');
    setText('overviewFreshCount', customers.length ? fmtInt(freshness.fresh) : '--');
    setText('overviewWatchCount', customers.length ? fmtInt(freshness.watch) : '--');
    setText('overviewStaleCount', customers.length ? fmtInt(freshness.stale) : '--');

    const setCoverageWidth = (id, value) => {
      const node = document.getElementById(id);
      if (node) node.style.width = `${customers.length ? Math.round((value / customers.length) * 100) : 0}%`;
    };
    setCoverageWidth('overviewFreshBar', freshness.fresh);
    setCoverageWidth('overviewWatchBar', freshness.watch);
    setCoverageWidth('overviewStaleBar', freshness.stale);

    const dailySeries = [...(Array.isArray(dashboard.series_diarias) ? dashboard.series_diarias : [])]
      .sort((left, right) => String(right.date || '').localeCompare(String(left.date || '')));
    const latestActivity = dailySeries.find(item => Number(item.backups || 0) > 0) || null;
    if (!latestActivity?.date) {
      setText('overviewLastActivity', '--');
      setText('overviewLastActivityHint', 'Nenhuma execução diária disponível para consulta.');
      return;
    }
    const activityDate = new Date(`${latestActivity.date}T12:00:00`);
    const today = new Date();
    const yesterday = new Date(Date.now() - dayMs);
    const sameDay = (left, right) => left.toDateString() === right.toDateString();
    const activityLabel = sameDay(activityDate, today)
      ? 'Hoje'
      : sameDay(activityDate, yesterday)
        ? 'Ontem'
        : activityDate.toLocaleDateString(uiLocale(), { day: '2-digit', month: 'short' }).replace('.', '');
    setText('overviewLastActivity', activityLabel);
    setText('overviewLastActivityHint', `${fmtInt(latestActivity.backups || 0)} execuções registradas nesse dia.`);
  };

  const updateLegendsAndSummary = ({ completed, failed, other }) => {
    const dashboard = state.dashboard || {};
    const storageAvailable = dashboard.armazenamento_disponivel !== false;
    const total = Math.max(completed + failed + other, 1);
    setText('legendSuccess', `${Math.round((completed / total) * 100)}%`);
    setText('legendFailed', `${Math.round((failed / total) * 100)}%`);
    setText('legendOther', `${Math.round((other / total) * 100)}%`);
    setText('storageUsed', storageAvailable ? formatBytes(Number(dashboard.espaco_utilizado || 0)) : 'Indisponivel');
    setText('storageFree', storageAvailable ? 'Acronis' : '--');
    const storageList = document.getElementById('storageClientList');
    if (storageList) {
      const clients = Array.isArray(dashboard.armazenamento_clientes) ? dashboard.armazenamento_clientes : [];
      storageList.replaceChildren();
      if (!storageAvailable) {
        storageList.innerHTML = '<small>A Acronis nao retornou armazenamento nesta consulta.</small>';
      } else if (!clients.length) {
        storageList.innerHTML = '<small>Sem armazenamento por cliente retornado pela API.</small>';
      } else {
        clients.forEach(item => {
          const row = document.createElement('div');
          const name = document.createElement('span');
          const size = document.createElement('b');
          name.textContent = normalizeText(item.cliente || 'Cliente');
          size.textContent = formatBytes(Number(item.bytes || 0));
          row.append(name, size);
          storageList.append(row);
        });
      }
    }
    setText('summarySuccess', fmtInt(completed));
    setText('summaryAlerts', state.alertsLoaded ? fmtInt(alertsInLastDays().length) : '--');
    setText('summaryClients', fmtInt(dashboard.total_clientes || state.customers.length || 0));
    setText('summaryDevices', fmtInt(dashboard.total_dispositivos || state.devices.length || 0));
    setText('fleetClients', fmtInt(dashboard.total_clientes || state.customers.length || 0));
    setText('fleetDevices', fmtInt(dashboard.total_dispositivos || state.devices.length || 0));
    setText('fleetBackups', fmtInt(state.dashboard?.total_backups || 0));
    setText('fleetFailures', fmtInt(state.dashboard?.backups_com_falha || 0));
    setText('fleetSuccess', fmtPercent(state.dashboard?.taxa_sucesso || 0));
    setText('fleetStorage', dashboard.armazenamento_disponivel === false ? 'Indisponivel' : formatBytes(Number(dashboard.espaco_utilizado || 0)));
  };

  const renderAnalyticsInsights = () => {
    const series = [...(Array.isArray(state.dashboard?.series_diarias) ? state.dashboard.series_diarias : [])]
      .sort((left, right) => String(left.date || '').localeCompare(String(right.date || '')));
    const current = series.slice(-7);
    const previous = series.slice(-14, -7);
    const summarize = items => items.reduce((summary, item) => ({
      executions: summary.executions + Number(item.backups || 0),
      success: summary.success + Number(item.success || 0),
      failures: summary.failures + Number(item.failed || 0)
    }), { executions: 0, success: 0, failures: 0 });
    const currentSummary = summarize(current);
    const previousSummary = summarize(previous);
    const rate = summary => summary.executions ? (summary.success / summary.executions) * 100 : 0;
    const delta = (currentValue, previousValue, suffix = '%') => {
      if (!previousValue) return currentValue ? 'Novo período' : 'Sem variação';
      const change = ((currentValue - previousValue) / previousValue) * 100;
      return `${change >= 0 ? '+' : ''}${change.toFixed(1).replace('.', ',')}${suffix} vs. período anterior`;
    };
    const setAnalyticsDelta = (id, value, tone = 'neutral') => {
      const node = document.getElementById(id);
      if (!node) return;
      node.textContent = value;
      node.dataset.tone = tone;
    };
    setText('analyticsExecutions', fmtInt(currentSummary.executions));
    setAnalyticsDelta('analyticsExecutionsDelta', delta(currentSummary.executions, previousSummary.executions), currentSummary.executions >= previousSummary.executions ? 'positive' : 'negative');
    setText('analyticsSuccessRate', `${rate(currentSummary).toFixed(1)}%`);
    const successDelta = rate(currentSummary) - rate(previousSummary);
    setAnalyticsDelta('analyticsSuccessDelta', `${successDelta >= 0 ? '+' : ''}${successDelta.toFixed(1).replace('.', ',')} p.p. vs. período anterior`, successDelta >= 0 ? 'positive' : 'negative');
    setText('analyticsFailures', fmtInt(currentSummary.failures));
    setAnalyticsDelta('analyticsFailuresDelta', delta(currentSummary.failures, previousSummary.failures), currentSummary.failures <= previousSummary.failures ? 'positive' : 'negative');

    const causeList = document.getElementById('analyticsCauseList');
    if (causeList) {
      const causes = new Map();
      state.alerts.forEach(alert => {
        const cause = normalizeText(alert.codigo || alert.tipo || alert.mensagem || 'Sem classificação');
        causes.set(cause, (causes.get(cause) || 0) + 1);
      });
      causeList.replaceChildren();
      [...causes.entries()].sort((left, right) => right[1] - left[1]).slice(0, 5).forEach(([cause, count]) => {
        const item = document.createElement('div');
        const label = document.createElement('span');
        const value = document.createElement('b');
        label.textContent = cause;
        value.textContent = `${fmtInt(count)} evento${count === 1 ? '' : 's'}`;
        item.append(label, value);
        causeList.append(item);
      });
      if (!causeList.children.length) causeList.innerHTML = '<span>Nenhum alerta classificado no período.</span>';
    }

    const recommendationList = document.getElementById('analyticsRecommendationList');
    if (recommendationList) {
      const alertText = state.alerts.map(alert => normalizeText(`${alert.codigo || ''} ${alert.tipo || ''} ${alert.mensagem || ''}`).toLowerCase()).join(' ');
      const recommendations = [];
      if (alertText.includes('offline')) recommendations.push('Priorize os dispositivos offline e valide a última comunicação do agente.');
      if (alertText.includes('expected') || alertText.includes('nao execut') || alertText.includes('didnotstart')) recommendations.push('Revise as janelas dos planos que não executaram no período esperado.');
      if (alertText.includes('zero') || alertText.includes('no_files') || alertText.includes('sem arquivos')) recommendations.push('Verifique origem, permissões e caminhos dos planos sem arquivos processados.');
      if (currentSummary.failures > 0) recommendations.push('Compare as falhas recentes com o histórico antes de encerrar a tratativa.');
      if (!recommendations.length) recommendations.push('Nenhum padrão crítico detectado. Mantenha o acompanhamento da próxima janela de execução.');
      recommendationList.replaceChildren();
      recommendations.slice(0, 4).forEach(text => {
        const item = document.createElement('div');
        item.innerHTML = '<i data-lucide="arrow-up-right"></i>';
        item.append(document.createTextNode(text));
        recommendationList.append(item);
      });
      window.lucide?.createIcons();
    }
  };

  const buildCharts = () => {
    if (!state.dashboard) {
      return;
    }

    const c = colors();
    const dashboard = state.dashboard;
    const completed = Number(dashboard.backups_ok || 0);
    const failed = Number(dashboard.backups_com_falha || 0);
    const other = Math.max(Number(dashboard.total_backups || 0) - completed - failed, 0);

    const dailySeries = Array.isArray(dashboard.series_diarias) ? dashboard.series_diarias : [];
    const chartDailySeries = dailySeries.slice(-21);
    const historyLabels = chartDailySeries.map(item => item.label || '--');
    const jobsHistory = chartDailySeries.map(item => Number(item.backups || 0));
    const usedPct = dashboard.armazenamento_disponivel === false ? 0 : 100;
    const statusHeight = chartHeight(260, 245, 230);
    const trendHeight = chartHeight(292, 270, 245);
    const storageHeight = chartHeight(260, 240, 225);
    const historyHeight = chartHeight(318, 295, 260);
    renderDailyHistory(dailySeries);
    renderAnalyticsInsights();

    updateLegendsAndSummary({ completed, failed, other });

    if (!window.ApexCharts) {
      mountFallbackRing('statusDonut', [completed, failed, other], [c.green, c.red, '#708096'], fmtInt(dashboard.total_backups || 0), 'Total de backups');
      mountFallbackBars('executionLine', jobsHistory.slice(-14), historyLabels.slice(-14), c.violet2);
      mountFallbackRing(
        'storageRadial',
        [usedPct, Math.max(100 - usedPct, 0)],
        [c.violet2, '#253247'],
        dashboard.armazenamento_disponivel === false ? 'Indisponivel' : formatBytes(Number(dashboard.espaco_utilizado || 0)),
        'Volume protegido'
      );
      mountFallbackBars('historyArea', dailySeries.slice(-14).map(item => Number(item.backups || 0)), historyLabels.slice(-14), c.green);
      return;
    }

    mount('statusDonut', {
      ...chartBase(statusHeight),
      chart: {
        ...chartBase(statusHeight).chart,
        type: 'donut',
        toolbar: { show: false },
        events: {
          mounted: () => renderStatusDonutCenter(dashboard.total_backups || 0),
          updated: () => renderStatusDonutCenter(dashboard.total_backups || 0)
        }
      },
      series: [completed, failed, other],
      labels: ['Sucesso', 'Falha', 'Outros'],
      colors: ['#19c37d', '#f05d6b', '#7d8ea8'],
      stroke: { width: 3, colors: [root.dataset.mode === 'light' ? '#ffffff' : '#0b1726'] },
      states: { hover: { filter: { type: 'darken', value: .08 } } },
      legend: { ...chartBase(statusHeight).legend, show: false },
      plotOptions: {
        pie: {
          expandOnClick: false,
          donut: {
            size: '72%',
            background: 'transparent',
            labels: {
              show: false,
              name: { show: true, color: c.text, fontSize: '11px', fontWeight: 700, offsetY: 18 },
              value: { show: false, color: root.dataset.mode === 'light' ? '#101827' : '#f5f7fb', fontSize: '30px', fontWeight: 800, offsetY: -8, formatter: value => fmtInt(value) },
              total: { show: false, label: 'TOTAL', color: c.text, fontSize: '11px', fontWeight: 700, offsetY: 14, formatter: () => fmtInt(dashboard.total_backups || 0) }
            }
          }
        }
      },
      tooltip: { ...chartBase(statusHeight).tooltip, enabled: true, y: { formatter: value => `${fmtInt(value)} execucoes` } }
    });

    mount('executionLine', {
      ...chartBase(trendHeight),
      chart: { ...chartBase(trendHeight).chart, type: 'area', toolbar: { show: false } },
      series: [{ name: 'Execucoes', data: jobsHistory }],
      colors: [c.violet2],
      stroke: { curve: 'smooth', width: 3.5, lineCap: 'round' },
      fill: {
        type: 'gradient',
        gradient: { shadeIntensity: .2, opacityFrom: .52, opacityTo: 0, stops: [0, 70, 100] }
      },
      markers: { size: 0, strokeWidth: 2, strokeColors: root.dataset.mode === 'light' ? '#ffffff' : '#0b1726', hover: { size: 6, sizeOffset: 2 } },
      grid: { ...chartBase(trendHeight).grid, xaxis: { lines: { show: false } }, yaxis: { lines: { show: true } } },
      xaxis: { ...chartBase(trendHeight).xaxis, categories: historyLabels, tickAmount: Math.min(7, Math.max(historyLabels.length - 1, 1)) },
      yaxis: { ...chartBase(trendHeight).yaxis, min: 0, forceNiceScale: true, tickAmount: 4, labels: { ...chartBase(trendHeight).yaxis.labels, formatter: value => fmtInt(value) } },
      tooltip: { ...chartBase(trendHeight).tooltip, y: { formatter: value => `${fmtInt(value)} execucoes` } }
    });

    mount('storageRadial', {
      ...chartBase(storageHeight),
      chart: { ...chartBase(storageHeight).chart, type: 'radialBar' },
      series: [usedPct],
      labels: ['Volume protegido'],
      colors: [c.violet2],
      plotOptions: {
        radialBar: {
          startAngle: -110,
          endAngle: 110,
          hollow: { size: '62%', background: 'rgba(255,255,255,0.02)' },
          track: { background: root.dataset.mode === 'light' ? '#edf1f5' : '#253247', strokeWidth: '88%', margin: 4 },
          dataLabels: {
            name: { color: c.text, fontSize: '10px', fontWeight: 700, offsetY: 30 },
            value: { color: root.dataset.mode === 'light' ? '#0f0f0f' : '#ffffff', fontSize: '24px', fontWeight: 800, offsetY: -6, formatter: () => formatBytes(Number(dashboard.espaco_utilizado || 0)) }
          }
        }
      },
      stroke: { lineCap: 'round' },
      legend: { show: false },
      tooltip: { ...chartBase(storageHeight).tooltip, y: { formatter: value => `${fmtInt(value)}%` } }
    });

    mount('historyArea', {
      ...chartBase(historyHeight),
      chart: { ...chartBase(historyHeight).chart, type: 'bar', stacked: true, toolbar: { show: false } },
      series: [
        { name: 'Sucesso', data: chartDailySeries.map(item => Number(item.success || 0)) },
        { name: 'Falha', data: chartDailySeries.map(item => Number(item.failed || 0)) }
      ],
      colors: ['#19c37d', '#f05d6b'],
      plotOptions: { bar: { borderRadius: 6, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last', columnWidth: window.innerWidth <= 700 ? '72%' : '54%' } },
      stroke: { width: 0 },
      grid: { ...chartBase(historyHeight).grid, xaxis: { lines: { show: false } }, yaxis: { lines: { show: true } } },
      xaxis: { ...chartBase(historyHeight).xaxis, categories: historyLabels.length ? historyLabels : ['Sem dados'], tickAmount: Math.min(7, Math.max(historyLabels.length - 1, 1)) },
      yaxis: { ...chartBase(historyHeight).yaxis, min: 0, forceNiceScale: true, tickAmount: 4, labels: { ...chartBase(historyHeight).yaxis.labels, formatter: value => fmtInt(value) } },
      legend: { ...chartBase(historyHeight).legend, position: 'top', horizontalAlign: 'right', offsetY: -4 },
      tooltip: { ...chartBase(historyHeight).tooltip, y: { formatter: value => `${fmtInt(value)} execucoes` } }
    });
  };

  const buildMicroCharts = () => {
    if (!window.ApexCharts || !state.dashboard) {
      return;
    }

    const recentDays = (Array.isArray(state.dashboard.series_diarias) ? state.dashboard.series_diarias : []).slice(-7);
    const baseSets = [
      recentDays.map(item => Number(item.success || 0)),
      recentDays.map(item => Number(item.backups || 0)),
      recentDays.map(item => Number(item.failed || 0)),
      recentDays.map(item => Number(item.bytes || 0) / (1024 ** 4))
    ];

    ['microA', 'microB', 'microC', 'microD'].forEach((id, index) => {
      const node = document.getElementById(id);
      if (!node) {
        return;
      }

      node.innerHTML = '';
      const chart = new ApexCharts(node, {
        chart: { type: 'area', height: 28, width: 78, sparkline: { enabled: true }, animations: { enabled: true, speed: 800 } },
        series: [{ data: baseSets[index].length ? baseSets[index] : [0] }],
        colors: [index === 2 ? '#c07c0b' : index === 3 ? '#189b9b' : '#a880da'],
        stroke: { curve: 'smooth', width: 2 },
        fill: { type: 'gradient', gradient: { opacityFrom: 0.32, opacityTo: 0 } },
        tooltip: { enabled: false }
      });
      chart.render();
    });
  };

  const refreshChartTheme = () => {
    const c = colors();
    chartStore.forEach(chart => {
      chart.updateOptions({
        grid: { borderColor: c.grid },
        xaxis: { labels: { style: { colors: c.text } } },
        yaxis: { labels: { style: { colors: c.text } } },
        legend: { labels: { colors: c.text } },
        tooltip: { theme: root.dataset.mode }
      }, false, true);
    });
  };

  const animateKpis = () => {
    document.querySelectorAll('.kpi-tile').forEach((tile, index) => {
      tile.classList.add('is-loading');
      setTimeout(() => {
        tile.classList.remove('is-loading');
        const number = tile.querySelector('.kpi-number');
        if (tile.dataset.display) {
          if (number) number.textContent = tile.dataset.display;
          return;
        }
        countUp(number, Number(tile.dataset.count || 0));
      }, 280 + index * 80);
    });
  };

  const markDashboardUnavailable = () => {
    document.querySelectorAll('.kpi-tile').forEach(tile => {
      const number = tile.querySelector('.kpi-number');
      const hint = tile.querySelector('small');
      if (number) number.textContent = '--';
      if (hint) hint.textContent = 'Dados reais indisponiveis no momento.';
    });
  };

  const safeRun = fn => {
    try {
      fn();
    } catch (error) {
      console.error(error);
    }
  };

  const renderData = () => {
    safeRun(updateHero);
    safeRun(updateKpis);
    safeRun(renderOverviewInsights);
    safeRun(buildCharts);
    safeRun(buildMicroCharts);
    safeRun(buildRecentRows);
    safeRun(buildClientDirectory);
    safeRun(buildExecutionWindowRows);
    safeRun(buildDailyExecutionRows);
    safeRun(animateKpis);
  };

  const renderPartialData = () => {
    safeRun(renderProfile);
    safeRun(renderAccounts);
    safeRun(renderAudit);
    safeRun(updateHero);
    safeRun(updateKpis);
    safeRun(renderOverviewInsights);
    safeRun(buildRecentRows);
    safeRun(buildClientDirectory);
    safeRun(buildExecutionWindowRows);
    safeRun(buildDailyExecutionRows);
  };

  const requestOnce = (key, request) => {
    if (!dataRequests.has(key)) {
      const pending = request().catch(error => {
        dataRequests.delete(key);
        throw error;
      });
      dataRequests.set(key, pending);
    }

    return dataRequests.get(key);
  };

  const loadDashboard = ({ full = false } = {}) => requestOnce(full ? 'dashboard-full' : 'dashboard-fast', async () => {
    try {
      state.dashboard = await fetchJson(full ? 'dashboard.php' : 'dashboard.php?fast=1', { timeout: full ? 18000 : 10000 }) || {};
      renderData();
    } catch (error) {
      if (!state.dashboard) markDashboardUnavailable();
      notify(error.message || 'Falha ao carregar dashboard.');
      throw error;
    }
  });

  const loadMe = () => requestOnce('me', async () => {
    try {
      const payload = await fetchJson('me.php', { timeout: 8000 });
      state.me = payload;
      if (payload?.idioma === 'pt-BR' || payload?.idioma === 'en-US') {
        localStorage.setItem('nyxcloud_language', payload.idioma);
      }
      applyLanguage();
      startLanguageObserver();
      renderPartialData();
    } catch (error) {
      markProfileUnavailable();
      throw error;
    }
  });

  const loadDevices = () => requestOnce('devices', async () => {
    state.devicesLoading = true;
    state.devicesError = '';
    try {
      const devices = await fetchJson('devices.php');
      state.devices = Array.isArray(devices) ? devices : [];
      state.devicesLoading = false;
      renderPartialData();
    } catch (error) {
      state.devicesLoading = false;
      state.devicesError = error.message || 'Falha ao carregar dispositivos.';
      notify(state.devicesError);
      safeRun(buildRecentRows);
      throw error;
    }
  });

  const loadAlerts = ({ foreground = true } = {}) => requestOnce('alerts', async () => {
    try {
      const alerts = await fetchJson('alertas.php', { timeout: foreground ? 12000 : 9000 });
      state.alerts = Array.isArray(alerts) ? alerts : [];
      state.alertsLoaded = true;
      renderPartialData();
      if (state.dashboard) safeRun(buildCharts);
    } catch (error) {
      state.alertsLoaded = false;
      notify(error.message || 'Falha ao carregar alertas.');
      throw error;
    }
  });

  const loadCustomers = ({ foreground = true } = {}) => requestOnce('customers', async () => {
    try {
      const customers = await fetchJson('clientes.php', { timeout: foreground ? 12000 : 9000 });
      state.customers = Array.isArray(customers) ? customers : [];
      renderPartialData();
      safeRun(buildClientDirectory);
      if (state.dashboard) safeRun(buildCharts);
    } catch (error) {
      notify(error.message || 'Falha ao carregar clientes.');
      throw error;
    }
  });

  const loadExecutionWindows = ({ stale = false } = {}) => requestOnce(stale ? 'execution-windows-stale' : 'execution-windows', async () => {
    state.executionWindowsError = '';
    try {
      state.executionWindows = await fetchJson(stale ? 'execution-windows.php?stale=1' : 'execution-windows.php', { timeout: stale ? 2500 : 18000 }) || { items: [] };
      safeRun(buildExecutionWindowRows);
    } catch (error) {
      state.executionWindowsError = stale ? '' : (error.message || 'Falha ao carregar janelas.');
      if (state.executionWindowsError) notify(state.executionWindowsError);
      safeRun(buildExecutionWindowRows);
      throw error;
    }
  });

  const loadDailyExecutions = () => requestOnce('daily-executions', async () => {
    state.dailyExecutionsError = '';
    try {
      let lastError;
      for (let attempt = 0; attempt < 2; attempt += 1) {
        try {
          state.dailyExecutions = await fetchJson('execucoes-diarias.php', { timeout: 20000 }) || { hoje: [], ontem: [], datas: {} };
          lastError = null;
          break;
        } catch (error) {
          lastError = error;
          if (attempt === 0) {
            await new Promise(resolve => window.setTimeout(resolve, 350));
          }
        }
      }
      if (lastError) throw lastError;
      safeRun(buildDailyExecutionRows);
    } catch (error) {
      state.dailyExecutionsError = error.message || 'Falha ao carregar execucoes diarias.';
      notify(state.dailyExecutionsError);
      safeRun(buildDailyExecutionRows);
      throw error;
    }
  });

  const loadAccounts = () => requestOnce('accounts', async () => {
    state.accountsError = '';
    try {
      const payload = await fetchJson('contas.php');
      state.accounts = Array.isArray(payload?.items) ? payload.items : [];
      state.companies = Array.isArray(payload?.empresas) ? payload.empresas : [];
      state.accountsMeta = {
        perfis: payload?.perfis || {},
        empresas: Array.isArray(payload?.empresas) ? payload.empresas : [],
        administrador_geral: Boolean(payload?.administrador_geral),
        viewer: payload?.viewer || null
      };
      safeRun(renderAccounts);
    } catch (error) {
      state.accounts = [];
      state.accountsError = error.message || 'Falha ao carregar contas.';
      safeRun(renderAccounts);
      throw error;
    }
  });

  const loadIntegrations = () => requestOnce('integrations', async () => {
    if (!state.me) {
      await loadMe().catch(() => {});
    }
    state.integrationsError = '';
    try {
      const payload = await fetchJson('acronis-credentials.php');
      state.integrations = Array.isArray(payload?.items) ? payload.items : [];
      safeRun(renderIntegrations);
    } catch (error) {
      state.integrations = [];
      state.integrationsError = error.message || 'Falha ao carregar integracoes.';
      safeRun(renderIntegrations);
      throw error;
    }
  });

  const loadAudit = () => requestOnce('audit', async () => {
    state.auditError = '';
    try {
      const payload = await fetchJson('auditoria.php');
      state.audit = Array.isArray(payload?.items) ? payload.items : [];
      safeRun(renderAudit);
    } catch (error) {
      state.audit = [];
      state.auditError = error.message || 'Falha ao carregar auditoria.';
      safeRun(renderAudit);
      throw error;
    }
  });

  const sectionLoaders = {
    overview: [loadMe, loadDashboard, loadAlerts],
    executions: [loadMe, loadDevices],
    storage: [() => loadDashboard({ full: true })],
    summary: [loadMe, loadDashboard],
    windows: [loadMe, () => loadExecutionWindows({ stale: true })],
    clients: [loadMe, loadDashboard, loadCustomers, loadDevices],
    accounts: [loadMe, loadAccounts],
    integrations: [loadMe, loadIntegrations],
    infrastructure: [loadMe, loadDailyExecutions],
    analytics: [loadMe, () => loadDashboard({ full: true }), loadCustomers, loadAlerts],
    alerts: [loadMe],
    audit: [loadMe, loadAudit],
    profile: [loadMe]
  };

  const sectionBackgroundLoaders = {
    overview: [
      () => loadAlerts({ foreground: false }),
      () => loadCustomers({ foreground: false }),
      () => loadDashboard({ full: true })
    ],
    summary: [() => loadAlerts({ foreground: false })],
    clients: [() => loadCustomers({ foreground: false })],
    windows: [loadExecutionWindows]
  };

  const sectionLabels = {
    overview: 'Visão geral',
    executions: 'Atividade recente',
    storage: 'Armazenamento',
    summary: 'Resumo',
    windows: 'Janelas de execução',
    clients: 'Clientes',
    accounts: 'Contas',
    integrations: 'Integrações',
    infrastructure: 'Execuções por dispositivo',
    analytics: 'Análises',
    alerts: 'Alertas',
    audit: 'Auditoria administrativa',
    profile: 'Meu perfil'
  };

  const sectionDescriptions = {
    executions: 'Atividade recente por dispositivo, plano, horario, status e volume processado.',
    storage: 'Volume protegido consolidado e distribuicao do consumo entre clientes.',
    summary: 'Indicadores essenciais para leitura executiva rapida da operacao.',
    windows: 'Comparativo entre horarios esperados e execucoes realizadas por empresa e plano.',
    clients: 'Distribuicao dos dispositivos protegidos e concentracao da carga por cliente.',
    accounts: 'Gerencie usuarios internos do painel e crie acessos com menos privilegios.',
    integrations: 'Gerencie credenciais da Acronis BR, US ou outro ambiente.',
    infrastructure: 'Execucoes de hoje e ontem organizadas por dispositivo para verificacao operacional.',
    analytics: 'Investigue tendências, compare períodos, identifique causas recorrentes e priorize ações.',
    alerts: 'Central de alertas operacionais.',
    audit: 'Eventos administrativos de criacao, edicao e seguranca de contas.',
    profile: 'Informacoes gerais, permissoes e seguranca da sua conta.'
  };

  const setSyncStatus = (message, stateName = 'ready') => {
    const node = document.getElementById('syncStatus');
    if (!node) return;
    node.dataset.state = stateName;
    const label = node.querySelector('span');
    if (label) label.textContent = message;
  };

  const syncTimeLabel = () => `${uiLocale() === 'en-US' ? 'Updated' : 'Atualizado'} ${new Date().toLocaleTimeString(uiLocale(), { hour: '2-digit', minute: '2-digit' })}`;

  const updateSectionIntro = section => {
    const intro = document.getElementById('sectionIntro');
    if (!intro) return;
    const overview = section === 'overview';
    intro.hidden = overview || section === 'alerts';
    setText('sectionIntroTitle', sectionLabels[section] || 'Visao geral');
    setText('sectionIntroText', sectionDescriptions[section] || 'Informacoes operacionais do ambiente protegido.');
    setText('sectionUpdated', 'Dados sincronizados com a Acronis');
  };

  const sectionAliases = {
    recent: 'executions',
    schedules: 'summary',
    'daily-devices': 'infrastructure',
    reports: 'analytics'
  };

  const sectionFromLocation = () => {
    const hash = window.location.hash.slice(1).toLowerCase();
    const section = sectionAliases[hash] || hash;
    return sectionLabels[section] ? section : 'overview';
  };

  const loadSectionData = section => Promise.allSettled(
    (sectionLoaders[section] || sectionLoaders.overview).map(loader => loader())
  );

  const loadBackgroundData = (section, activationId) => {
    const loaders = sectionBackgroundLoaders[section] || [];
    if (loaders.length === 0) {
      return;
    }

    Promise.allSettled(loaders.map(loader => loader())).then(results => {
      if (activationId !== sectionActivationId || root.dataset.activeSection !== section) {
        return;
      }

      const hasFailure = results.some(result => result.status === 'rejected');
      setSyncStatus(hasFailure ? 'Essencial carregado' : syncTimeLabel(), hasFailure ? 'warning' : 'ready');
      if (state.dashboard) safeRun(buildCharts);
    });
  };

  const releaseSectionLoading = (section, activationId, results, timedOut = false) => {
    if (activationId !== sectionActivationId || root.dataset.activeSection !== section) {
      return false;
    }

    const hasFailure = results.some(result => result.status === 'rejected');
    setSyncStatus(
      timedOut ? 'Carregando em segundo plano' : (hasFailure ? 'Dados parciais' : syncTimeLabel()),
      timedOut || hasFailure ? 'warning' : 'ready'
    );
    root.classList.remove('is-section-loading');
    requestAnimationFrame(() => {
      if (state.dashboard) safeRun(buildCharts);
      window.dispatchEvent(new Event('resize'));
    });
    return true;
  };

  const panelMatchesSection = (panel, section) => {
    if (section === 'overview') {
      return (panel.dataset.panelSection || '').split(/\s+/).filter(Boolean).includes(section);
    }
    const sections = [panel.dataset.panelSection || '', panel.dataset.panelExtraSections || ''].join(' ');
    return sections.split(/\s+/).filter(Boolean).includes(section);
  };

  const activateSection = (section, updateHistory = true) => {
    const selected = sectionLabels[section] && canAccessSection(section) ? section : 'overview';
    const activationId = ++sectionActivationId;
    root.dataset.activeSection = selected;
    root.classList.toggle('alerts-immersive', selected === 'alerts');
    root.classList.add('is-section-loading');
    setSyncStatus('Sincronizando', 'loading');
    document.querySelectorAll('[data-panel-section]').forEach(panel => {
      panel.hidden = !panelMatchesSection(panel, selected);
    });
    document.querySelectorAll('[data-section-container]').forEach(container => {
      const visibleChildren = [...container.children].filter(child => child.dataset?.panelSection && !child.hidden);
      container.hidden = visibleChildren.length === 0;
      container.classList.toggle('is-single-section', visibleChildren.length === 1);
    });
    document.querySelectorAll('.rail-item[data-section]').forEach(item => {
      const current = item.dataset.section === selected;
      item.classList.toggle('is-current', current);
      if (current) item.setAttribute('aria-current', 'page');
      else item.removeAttribute('aria-current');
    });
    setText('sectionTitle', sectionLabels[selected]);
    updateSectionIntro(selected);

    if (updateHistory && window.location.hash !== `#${selected}`) {
      window.history.pushState({ section: selected }, '', `#${selected}`);
    }

    const loading = loadSectionData(selected);
    let released = false;
    let backgroundStarted = false;
    window.setTimeout(() => {
      if (!released) {
        released = releaseSectionLoading(selected, activationId, [], true);
      }
    }, 2800);

    loading.then(results => {
      released = releaseSectionLoading(selected, activationId, results);
      if (!backgroundStarted) {
        backgroundStarted = true;
        loadBackgroundData(selected, activationId);
      }
    });
  };

  const desktopRail = window.matchMedia('(min-width: 1061px)');
  const railOpenButton = document.getElementById('railOpen');
  const railScrim = document.getElementById('railScrim');

  const setRailOpen = open => {
    if (desktopRail.matches) {
      root.classList.toggle('rail-collapsed', !open);
      rail?.classList.remove('is-open');
      railScrim?.classList.remove('is-visible');
    } else {
      root.classList.remove('rail-collapsed');
      rail?.classList.toggle('is-open', open);
      railScrim?.classList.toggle('is-visible', open);
    }
    railOpenButton?.setAttribute('aria-expanded', String(open));
  };

  const openRail = () => {
    if (desktopRail.matches) localStorage.setItem('nyxcloud-rail-collapsed', '0');
    setRailOpen(true);
  };

  const closeRail = () => {
    if (desktopRail.matches) localStorage.setItem('nyxcloud-rail-collapsed', '1');
    setRailOpen(false);
  };

  const refreshAccountsAndAudit = async () => {
    dataRequests.delete('accounts');
    dataRequests.delete('audit');
    await Promise.allSettled([loadAccounts(), loadAudit()]);
  };

  const updateAccount = async (id, payload, message = 'Conta atualizada.') => {
    const account = state.accounts.find(item => Number(item.id) === Number(id));
    if (!account) {
      notify('Conta nao encontrada.');
      return;
    }

    await fetchJson('contas.php', {
      method: 'PATCH',
      body: {
        id: Number(id),
        nome: account.nome,
        perfil: account.perfil,
        ativo: Boolean(account.ativo),
        ...payload
      }
    });
    await refreshAccountsAndAudit();
    notify(message);
  };

  theme.apply(theme.get());
  window.lucide?.createIcons();

  document.getElementById('modeSwitch')?.addEventListener('click', () => theme.toggle());
  railOpenButton?.addEventListener('click', openRail);
  document.getElementById('railClose')?.addEventListener('click', closeRail);
  railScrim?.addEventListener('click', closeRail);
  document.querySelectorAll('.rail-item[data-section], [data-section].command-icon').forEach(item => item.addEventListener('click', event => {
    event.preventDefault();
    activateSection(item.dataset.section || 'overview');
    if (!desktopRail.matches) closeRail();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }));
  document.querySelectorAll('[data-account-tab]').forEach(tab => tab.addEventListener('click', () => {
    const target = tab.dataset.accountTab;
    document.querySelectorAll('[data-account-tab]').forEach(item => {
      const active = item === tab;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', String(active));
    });
    document.querySelectorAll('[data-account-panel]').forEach(panel => {
      panel.hidden = panel.dataset.accountPanel !== target;
    });
  }));
  document.querySelectorAll('.rail-item:not([data-section])').forEach(item => item.addEventListener('click', () => {
    if (!desktopRail.matches) closeRail();
  }));
  document.querySelectorAll('[data-overview-nav]').forEach(item => item.addEventListener('click', () => {
    activateSection(item.dataset.overviewNav || 'overview');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }));
  document.querySelectorAll('[data-toast]').forEach(button => button.addEventListener('click', () => notify(button.dataset.toast)));
  document.getElementById('refreshDashboard')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const selected = root.dataset.activeSection || 'overview';
    const activationId = ++sectionActivationId;
    button.disabled = true;
    button.classList.add('is-refreshing');
    dataRequests.clear();
    root.classList.add('is-section-loading');
    setSyncStatus('Atualizando dados', 'loading');
    const results = await loadSectionData(selected);
    const hasFailure = results.some(result => result.status === 'rejected');
    setSyncStatus(hasFailure ? 'Dados parciais' : syncTimeLabel(), hasFailure ? 'warning' : 'ready');
    root.classList.remove('is-section-loading');
    button.disabled = false;
    button.classList.remove('is-refreshing');
    loadBackgroundData(selected, activationId);
    notify(hasFailure ? 'Atualizacao concluida com dados parciais.' : 'Dados atualizados.');
  });
  const permissionsByRole = { Administrador: 'Acesso total ao painel, contas, integrações, auditoria e configurações.', Operador: 'Consulta dados operacionais e executa rotinas permitidas.', 'Somente leitura': 'Consulta indicadores, clientes, alertas e relatórios.' };
  const openProfile = () => {
    activateSection('profile');
    document.getElementById('pageProfileNameInput').value = state.me?.nome || '';
    document.getElementById('pageProfileEmailInput').value = state.me?.email || '';
    document.getElementById('pageProfileCurrentPassword').value = '';
    document.getElementById('pageProfileNewPassword').value = '';
    document.getElementById('pageProfileNewPasswordConfirm').value = '';
    const role = state.me?.perfil_nome || state.me?.perfil || 'Perfil nao informado';
    const securityPanel = document.querySelector('[data-page-profile-panel="security"]');
    if (securityPanel && !securityPanel.querySelector('.profile-access-cards')) {
      const cards = document.createElement('div');
      cards.className = 'profile-access-cards';
      cards.innerHTML = '<div><span>Meu cargo</span><strong id="pageProfileRoleCard">--</strong></div><div><span>Minhas permissões</span><strong id="pageProfilePermissionsCard">--</strong></div>';
      securityPanel.prepend(cards);
    }
    if (securityPanel && !securityPanel.querySelector('.profile-security-info')) {
      const info = document.createElement('div');
      info.className = 'profile-security-info';
      info.innerHTML = '<div><span>Último acesso</span><strong id="pageProfileLastLogin">--</strong></div><div><span>Conta criada em</span><strong id="pageProfileCreatedAt">--</strong></div><div><span>Proteção</span><strong>Senha protegida</strong></div><div><span>Sessão</span><strong>Ativa neste navegador</strong></div>';
      securityPanel.append(info);
    }
    document.getElementById('pageProfileRoleCard').textContent = role;
    document.getElementById('pageProfilePermissionsCard').textContent = permissionsByRole[role] || 'Permissões definidas pelo administrador.';
    document.getElementById('pageProfileLastLogin').textContent = formatDateTime(state.me?.ultimo_login_em);
    document.getElementById('pageProfileCreatedAt').textContent = formatDateTime(state.me?.criado_em);
    document.getElementById('pageProfileSummaryName').textContent = state.me?.nome || '--';
    document.getElementById('pageProfileSummaryRole').textContent = role;
    document.getElementById('pageProfileSummaryEmail').textContent = state.me?.email || '--';
    document.getElementById('pageProfileSummaryId').textContent = state.me?.id ? `#${state.me.id}` : '--';
    document.getElementById('pageProfileSummaryAvatar').textContent = profileInitial(state.me?.nome);
    document.getElementById('pageProfileAvatar').textContent = profileInitial(state.me?.nome);
    document.getElementById('pageProfilePermissionsText').textContent = permissionsByRole[role] || 'Permissões definidas pelo administrador.';
    document.getElementById('pageProfileLanguage').value = localStorage.getItem('nyxcloud_language') || 'pt-BR';
    document.getElementById('pageProfileMessage').textContent = '';
  };
  document.getElementById('commandUserButton')?.addEventListener('click', openProfile);
  document.querySelector('.rail-item[data-section="profile"]')?.addEventListener('click', event => { event.preventDefault(); openProfile(); });
  document.querySelectorAll('[data-page-profile-tab]').forEach(tab => tab.addEventListener('click', () => {
    const target = tab.dataset.pageProfileTab;
    document.querySelectorAll('[data-page-profile-tab]').forEach(item => item.classList.toggle('is-active', item === tab));
    document.querySelectorAll('[data-page-profile-panel]').forEach(panel => panel.classList.toggle('is-active', panel.dataset.pageProfilePanel === target));
  }));
  document.getElementById('pageProfileLanguage')?.addEventListener('change', event => {
    localStorage.setItem('nyxcloud_language', event.target.value);
    fetchJson('update-language.php', { method: 'PATCH', body: { idioma: event.target.value } })
      .then(() => window.location.reload())
      .catch(error => notify(error.message || 'Nao foi possivel salvar o idioma.'));
  });
  document.getElementById('pageSaveProfileButton')?.addEventListener('click', async () => {
    const nova = document.getElementById('pageProfileNewPassword').value;
    if (nova !== document.getElementById('pageProfileNewPasswordConfirm').value) { document.getElementById('pageProfileMessage').textContent = 'Novas senhas nao conferem.'; return; }
    const payload = { nome: document.getElementById('pageProfileNameInput').value.trim(), email: document.getElementById('pageProfileEmailInput').value.trim(), senha_atual: document.getElementById('pageProfileCurrentPassword').value, nova_senha: nova };
    try { await fetchJson('update-profile.php', { method: 'POST', body: payload }); state.me = { ...state.me, nome: payload.nome, email: payload.email }; renderPartialData(); notify('Perfil atualizado com sucesso.'); }
    catch (error) { document.getElementById('pageProfileMessage').textContent = error.message; }
  });
  document.getElementById('windowsSearch')?.addEventListener('input', event => {
    state.windowsSearch = String(event.currentTarget.value || '').trim();
    safeRun(buildExecutionWindowRows);
  });
  document.getElementById('executionSearch')?.addEventListener('input', event => {
    state.executionSearch = String(event.currentTarget.value || '').trim();
    safeRun(buildRecentRows);
  });
  document.getElementById('executionStatus')?.addEventListener('change', event => {
    state.executionStatus = event.currentTarget.value || 'all';
    safeRun(buildRecentRows);
  });
  document.getElementById('executionSort')?.addEventListener('change', event => {
    state.executionSort = event.currentTarget.value || 'recent';
    safeRun(buildRecentRows);
  });
  document.getElementById('clientsSearch')?.addEventListener('input', event => {
    state.clientsSearch = String(event.currentTarget.value || '').trim();
    safeRun(buildClientDirectory);
  });
  document.getElementById('clientsSort')?.addEventListener('change', event => {
    state.clientsSort = event.currentTarget.value || 'name';
    safeRun(buildClientDirectory);
  });
  document.getElementById('manageWindowRules')?.addEventListener('click', async () => {
    const dialog = document.getElementById('windowRulesDialog');
    if (!canManageAdmin()) {
      notify('Somente administradores podem editar janelas.');
      return;
    }
    dialog?.showModal();
    window.lucide?.createIcons();
    await loadWindowRules().catch(() => {});
  });
  document.getElementById('reloadWindowRules')?.addEventListener('click', () => {
    loadWindowRules().catch(error => notify(error.message || 'Falha ao recarregar regras.'));
  });
  document.getElementById('saveWindowRules')?.addEventListener('click', () => {
    saveWindowRules().catch(error => notify(error.message || 'Falha ao salvar regras.'));
  });
  document.getElementById('globalSearch')?.addEventListener('input', event => {
    state.globalSearch = String(event.currentTarget.value || '').trim();
    safeRun(buildRecentRows);
    safeRun(buildDailyExecutionRows);
  });
  document.getElementById('globalSearch')?.addEventListener('keydown', event => {
    if (event.key !== 'Enter' || !String(event.currentTarget.value || '').trim()) return;
    activateSection('executions');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    notify('Busca aplicada nas execucoes.');
  });
  document.getElementById('accountRoleSelect')?.addEventListener('change', event => {
    const isAdmin = event.currentTarget.value === 'admin';
    const globalToggle = document.getElementById('accountGlobalAdmin');
    if (globalToggle) {
      globalToggle.disabled = !isAdmin;
      if (!isAdmin) globalToggle.checked = false;
    }
    setText('accountPermissionHint', accountRoleHint(event.currentTarget.value));
  });
  document.getElementById('accountGlobalAdmin')?.addEventListener('change', event => {
    const companyPicker = document.querySelector('#accountCompanyOptions')?.closest('.account-company-picker');
    if (companyPicker) companyPicker.hidden = event.currentTarget.checked;
  });
  document.getElementById('accountsCompanyFilter')?.addEventListener('change', () => safeRun(renderAccounts));
  document.getElementById('accountCompanyOptions')?.addEventListener('click', event => {
    const addButton = event.target.closest('[data-add-company]');
    const removeButton = event.target.closest('[data-remove-company]');
    if (removeButton) {
      const row = removeButton.closest('.account-company-row');
      const previous = row?.previousElementSibling;
      row?.remove();
      previous?.querySelector('[data-add-company]')?.removeAttribute('hidden');
      return;
    }
    if (!addButton) return;
    const companies = state.accountsMeta.empresas || [];
    if (!companies.length) return;
    addButton.hidden = true;
    const row = document.createElement('div');
    row.className = 'account-company-row';
    row.innerHTML = `<select name="empresa_ids[]" class="account-company-select"><option value="">Selecione uma empresa</option>${companies.map(company => `<option value="${Number(company.id)}">${escapeHtml(cleanLabel(company.nome))}</option>`).join('')}</select><button type="button" data-remove-company aria-label="Remover esta empresa">−</button><button type="button" data-add-company aria-label="Adicionar outra empresa">+</button>`;
    document.getElementById('accountCompanyOptions').append(row);
  });
  document.getElementById('accountsRows')?.addEventListener('click', async event => {
    const button = event.target.closest('button');
    if (!button) return;

    const id = button.dataset.accountSave || button.dataset.accountAccess || button.dataset.accountToggle || button.dataset.accountPassword || button.dataset.accountDelete;
    if (!id) return;

    button.disabled = true;
    try {
      if (button.dataset.accountAccess) {
        const account = state.accounts.find(item => Number(item.id) === Number(id));
        const dialog = document.getElementById('accountPermissionsDialog');
        const options = document.getElementById('accountPermissionsOptions');
        if (!account || !dialog || !options) return;
        document.getElementById('accountPermissionsTitle').textContent = `Permissões: ${account.nome}`;
        const selected = new Set((account.empresa_ids || []).map(Number));
        const companies = state.accountsMeta.empresas || [];
        const selectedIds = Array.from(selected);
        const rows = selectedIds.length ? selectedIds : [0];
        const companyOptions = ['<option value="">Selecione uma empresa</option>'].concat(companies.map(company => `<option value="${Number(company.id)}">${escapeHtml(cleanLabel(company.nome))}</option>`)).join('');
        const globalAdminOption = state.accountsMeta.administrador_geral && account.perfil === 'admin'
          ? `<label class="account-checkbox account-global-edit"><input type="checkbox" name="administrador_geral" ${account.administrador_geral ? 'checked' : ''}><span>Administrador geral (acesso a todas as empresas)</span></label>`
          : '';
        const companyRows = companies.length
          ? rows.map((selectedId, index) => `<div class="account-company-row"><select name="permission_empresa_ids[]">${companyOptions.replace(`value="${selectedId}"`, `value="${selectedId}" selected`)}</select>${index > 0 ? '<button type="button" data-remove-permission-company aria-label="Remover empresa">−</button>' : ''}${index === rows.length - 1 ? '<button type="button" data-add-permission-company aria-label="Adicionar empresa">+</button>' : ''}</div>`).join('')
          : '<small>Nenhuma empresa Acronis sincronizada.</small>';
        options.innerHTML = globalAdminOption + `<div data-company-permissions="true">${companyRows}</div>`;
        const globalAdminInput = options.querySelector('input[name="administrador_geral"]');
        const companyPermissions = options.querySelector('[data-company-permissions]');
        if (globalAdminInput && companyPermissions) {
          companyPermissions.hidden = globalAdminInput.checked;
          companyPermissions.querySelectorAll('select, button').forEach(field => { field.disabled = globalAdminInput.checked; });
        }
        dialog.dataset.accountId = id;
        dialog.showModal();
      } else if (button.dataset.accountSave) {
        const role = document.querySelector(`[data-account-role="${id}"]`)?.value || 'leitura';
        await updateAccount(id, { perfil: role }, 'Perfil atualizado.');
      } else if (button.dataset.accountToggle) {
        const account = state.accounts.find(item => Number(item.id) === Number(id));
        await updateAccount(id, { ativo: !Boolean(account?.ativo) }, account?.ativo ? 'Conta inativada.' : 'Conta ativada.');
      } else if (button.dataset.accountPassword) {
        const password = window.prompt('Nova senha temporaria (minimo 8 caracteres):');
        if (password === null) return;
        await updateAccount(id, { senha: password }, 'Senha redefinida.');
      } else if (button.dataset.accountDelete) {
        const account = state.accounts.find(item => Number(item.id) === Number(id));
        if (!account || !window.confirm(`Excluir definitivamente a conta de ${account.nome}?`)) return;
        const senha = window.prompt('Digite sua senha para confirmar a exclusao:');
        if (!senha) return;
        await fetchJson('contas.php', { method: 'DELETE', body: { id: Number(id), senha_confirmacao: senha } });
        await refreshAccountsAndAudit();
        notify('Conta excluida com sucesso.');
      }
    } catch (error) {
      notify(error.message || 'Falha ao atualizar conta.');
    } finally {
      button.disabled = false;
    }
  });
  document.getElementById('cancelAccountPermissions')?.addEventListener('click', () => document.getElementById('accountPermissionsDialog')?.close());
  document.getElementById('accountPermissionsOptions')?.addEventListener('click', event => {
    const container = event.currentTarget;
    const remove = event.target.closest('[data-remove-permission-company]');
    if (remove) {
      const row = remove.closest('.account-company-row');
      const previous = row?.previousElementSibling;
      row?.remove();
      previous?.querySelector('[data-add-permission-company]')?.removeAttribute('hidden');
      return;
    }
    if (!event.target.closest('[data-add-permission-company]')) return;
    const companies = state.accountsMeta.empresas || [];
    const row = document.createElement('div');
    row.className = 'account-company-row';
    row.innerHTML = `<select name="permission_empresa_ids[]"><option value="">Selecione uma empresa</option>${companies.map(company => `<option value="${Number(company.id)}">${escapeHtml(cleanLabel(company.nome))}</option>`).join('')}</select><button type="button" data-remove-permission-company aria-label="Remover empresa">−</button><button type="button" data-add-permission-company aria-label="Adicionar empresa">+</button>`;
    container.querySelectorAll('[data-add-permission-company]').forEach(button => { button.hidden = true; });
    container.append(row);
  });
  document.getElementById('accountPermissionsOptions')?.addEventListener('change', event => {
    const globalAdmin = event.target.closest('input[name="administrador_geral"]');
    if (!globalAdmin) return;
    const companyPermissions = document.querySelector('#accountPermissionsOptions [data-company-permissions]');
    if (!companyPermissions) return;
    companyPermissions.hidden = globalAdmin.checked;
    companyPermissions.querySelectorAll('select, button').forEach(field => { field.disabled = globalAdmin.checked; });
  });
  document.getElementById('accountPermissionsForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const dialog = document.getElementById('accountPermissionsDialog');
    const id = Number(dialog?.dataset.accountId || 0);
    if (!id) return;
    const empresaIds = Array.from(document.querySelectorAll('#accountPermissionsOptions select[name="permission_empresa_ids[]"]')).map(select => Number(select.value)).filter(Boolean);
    const globalAdmin = document.querySelector('#accountPermissionsOptions input[name="administrador_geral"]');
    let senhaConfirmacao = '';
    const account = state.accounts.find(item => Number(item.id) === id);
    if (globalAdmin && account && globalAdmin.checked !== Boolean(account.administrador_geral)) {
      senhaConfirmacao = window.prompt('Digite sua senha para confirmar a alteração de administrador geral:') || '';
      if (!senhaConfirmacao) return;
    }
    try {
      await updateAccount(id, {
        empresa_ids: empresaIds,
        ...(globalAdmin ? { administrador_geral: globalAdmin.checked, senha_confirmacao: senhaConfirmacao } : {})
      }, 'Permissões atualizadas.');
      dialog.close();
    } catch (error) {
      notify(error.message || 'Falha ao atualizar permissões.');
    }
  });
  document.getElementById('accountForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    const form = event.currentTarget;
    if (!canManageAccounts()) {
      notify('Voce nao tem permissao para criar contas.');
      return;
    }
    const submitButton = document.getElementById('accountSubmitButton');
    const message = document.getElementById('accountFormMessage');
    const data = new FormData(form);
    const payload = {
      nome: String(data.get('nome') || '').trim(),
      email: String(data.get('email') || '').trim(),
      perfil: String(data.get('perfil') || 'leitura').trim(),
      administrador_geral: data.get('administrador_geral') !== null,
      senha: String(data.get('senha') || ''),
      ativo: data.get('ativo') !== null,
      empresa_ids: Array.from(document.querySelectorAll('#accountCompanyOptions select[name="empresa_ids[]"]'))
        .map(option => Number(option.value))
        .filter(Boolean)
    };

    submitButton.disabled = true;
    if (message) message.textContent = 'Criando conta...';
    try {
      await fetchJson('contas.php', { method: 'POST', body: payload });
      dataRequests.delete('accounts');
      await loadAccounts();
      dataRequests.delete('audit');
      loadAudit().catch(() => {});
      form.reset();
      const roleSelect = document.getElementById('accountRoleSelect');
      if (roleSelect) roleSelect.value = 'leitura';
      const globalToggle = document.getElementById('accountGlobalAdmin');
      if (globalToggle) { globalToggle.checked = false; globalToggle.disabled = true; }
      const companyPicker = document.querySelector('#accountCompanyOptions')?.closest('.account-company-picker');
      if (companyPicker) companyPicker.hidden = false;
      setText('accountPermissionHint', accountRoleHint('leitura'));
      if (message) message.textContent = 'Conta criada com sucesso.';
      notify('Nova conta criada no painel.');
    } catch (error) {
      if (message) message.textContent = error.message || 'Falha ao criar conta.';
      notify(error.message || 'Falha ao criar conta.');
    } finally {
      submitButton.disabled = false;
    }
  });
  document.getElementById('integrationRows')?.addEventListener('click', async event => {
    const button = event.target.closest('button');
    if (!button) return;

    const editId = button.dataset.integrationEdit;
    const activateId = button.dataset.integrationActivate;
    const deleteId = button.dataset.integrationDelete;
    const id = editId || activateId || deleteId;
    const item = state.integrations.find(integration => integration.id === id);
    if (!id || !item) return;

    if (editId) {
      const form = document.getElementById('integrationForm');
      if (!form) return;
      form.elements.namedItem('id').value = item.id || '';
      form.elements.namedItem('name').value = item.name || '';
      form.elements.namedItem('region').value = item.region || 'BR';
      form.elements.namedItem('base_url').value = item.base_url || '';
      form.elements.namedItem('client_id').value = item.client_id || '';
      form.elements.namedItem('client_secret').value = '';
      form.elements.namedItem('active').checked = Boolean(item.active);
      setText('integrationFormMessage', 'Editando integracao. Secret vazio mantém valor atual.');
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    button.disabled = true;
    try {
      const endpoint = 'acronis-credentials.php';
      const method = activateId ? 'PATCH' : 'DELETE';
      const payload = await fetchJson(endpoint, {
        method,
        body: activateId ? { id, active: !Boolean(item.active) } : { id }
      });
      state.integrations = Array.isArray(payload?.items) ? payload.items : [];
      renderIntegrations();
      notify(activateId ? (item.active ? 'Integracao desativada.' : 'Integracao ativada.') : 'Integracao removida.');
    } catch (error) {
      notify(error.message || 'Falha ao atualizar integracao.');
    } finally {
      button.disabled = false;
    }
  });
  document.getElementById('integrationForm')?.addEventListener('submit', async event => {
    event.preventDefault();
    if (!canManageAdmin()) {
      notify('Somente administradores podem gerenciar integracoes.');
      return;
    }

    const form = event.currentTarget;
    const submitButton = document.getElementById('integrationSubmitButton');
    const message = document.getElementById('integrationFormMessage');
    const data = new FormData(form);
    const payload = {
      id: String(data.get('id') || '').trim(),
      name: String(data.get('name') || '').trim(),
      region: String(data.get('region') || 'BR').trim(),
      base_url: String(data.get('base_url') || '').trim(),
      client_id: String(data.get('client_id') || '').trim(),
      client_secret: String(data.get('client_secret') || ''),
      active: data.get('active') !== null
    };

    submitButton.disabled = true;
    if (message) message.textContent = 'Salvando integracao...';
    try {
      const saved = await fetchJson('acronis-credentials.php', { method: 'POST', body: payload });
      state.integrations = Array.isArray(saved?.items) ? saved.items : [];
      renderIntegrations();
      form.reset();
      form.elements.namedItem('id').value = '';
      if (message) message.textContent = 'Integracao salva.';
      notify('Integracao Acronis salva. Cache limpo.');
    } catch (error) {
      if (message) message.textContent = error.message || 'Falha ao salvar integracao.';
      notify(error.message || 'Falha ao salvar integracao.');
    } finally {
      submitButton.disabled = false;
    }
  });

  const setupVisualInteractions = () => {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const glowTargets = document.querySelectorAll('.surface, .kpi-tile, .signal-band');
    glowTargets.forEach(target => {
      target.addEventListener('pointermove', event => {
        const rect = target.getBoundingClientRect();
        target.style.setProperty('--mx', `${event.clientX - rect.left}px`);
        target.style.setProperty('--my', `${event.clientY - rect.top}px`);
      });
      target.addEventListener('pointerleave', () => {
        target.style.removeProperty('--mx');
        target.style.removeProperty('--my');
      });
    });

    const revealTargets = document.querySelectorAll('.hero-row, .signal-band, .kpi-tile, .surface');
    revealTargets.forEach((target, index) => {
      target.classList.add('visual-reveal');
      target.style.transitionDelay = reducedMotion ? '0ms' : `${Math.min(index * 35, 260)}ms`;
    });

    if (!('IntersectionObserver' in window) || reducedMotion) {
      revealTargets.forEach(target => target.classList.add('is-visible'));
      return;
    }

    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: .08 });

    revealTargets.forEach(target => observer.observe(target));
  };

  const restoreSection = () => activateSection(sectionFromLocation(), false);
  window.addEventListener('popstate', restoreSection);
  window.addEventListener('hashchange', restoreSection);
  let chartResizeTimer;
  window.addEventListener('resize', () => {
    if (!state.dashboard) return;
    window.clearTimeout(chartResizeTimer);
    chartResizeTimer = window.setTimeout(() => safeRun(buildCharts), 180);
  });
  window.addEventListener('keydown', event => {
    if (event.key === 'Escape' && (!desktopRail.matches || !root.classList.contains('rail-collapsed'))) {
      closeRail();
    }
  });
  desktopRail.addEventListener('change', event => {
    setRailOpen(event.matches ? localStorage.getItem('nyxcloud-rail-collapsed') !== '1' : false);
  });

  const initialSection = sectionFromLocation();
  if (window.location.hash !== `#${initialSection}`) {
    window.history.replaceState({ section: initialSection }, '', `#${initialSection}`);
  }
  setText('accountPermissionHint', accountRoleHint('leitura'));
  setupVisualInteractions();
  setRailOpen(desktopRail.matches ? localStorage.getItem('nyxcloud-rail-collapsed') !== '1' : false);
  activateSection(initialSection, false);
})();
