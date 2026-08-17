(() => {
  'use strict';

  const root = document.body;
  const rail = document.getElementById('rail');
  const toast = document.getElementById('toast');
  const chartStore = new Map();
  let toastTimer;

  const state = {
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
    windowsSearch: '',
    globalSearch: ''
  };

  const topCustomersLimit = 5;
  const dataRequests = new Map();

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

  const chartBase = height => ({
    chart: {
      height,
      background: 'transparent',
      fontFamily: 'Inter, sans-serif',
      toolbar: { show: false },
      animations: { enabled: true, easing: 'easeinout', speed: 700 }
    },
    dataLabels: { enabled: false },
    grid: { borderColor: colors().grid, strokeDashArray: 4 },
    xaxis: {
      labels: { style: { colors: colors().text, fontSize: '10px' } },
      axisBorder: { show: false },
      axisTicks: { show: false }
    },
    yaxis: {
      labels: { style: { colors: colors().text, fontSize: '10px' } }
    },
    legend: {
      position: 'bottom',
      fontSize: '10px',
      labels: { colors: colors().text }
    },
    tooltip: {
      theme: root.dataset.mode,
      shared: true,
      intersect: false
    }
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

  const setText = (id, value) => {
    const node = document.getElementById(id);
    if (node) {
      node.textContent = value;
    }
  };

  const jwtToken = () => localStorage.getItem('access_token') || sessionStorage.getItem('access_token') || '';
  const authHeaders = () => {
    const token = jwtToken();
    return token ? { Authorization: `Bearer ${token}` } : {};
  };
  const redirectToLogin = () => {
    const login = new URL('../back/index.php', window.location.href);
    login.searchParams.set('next', window.location.pathname + window.location.search + window.location.hash);
    window.location.replace(login.href);
  };

  const fetchJson = async endpoint => {
    const response = await fetch(`../back/api/${endpoint}`, {
      headers: {
        Accept: 'application/json',
        ...authHeaders()
      },
      credentials: 'include'
    });

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
      throw new Error(payload?.message || `Falha ao carregar ${endpoint}`);
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

  const fmtInt = value => Number(value || 0).toLocaleString('pt-BR');
  const fmtPercent = value => `${Number(value || 0).toFixed(2)}%`;

  const countUp = (element, target, suffix = '') => {
    if (!element) {
      return;
    }

    const start = performance.now();
    const tick = now => {
      const progress = Math.min((now - start) / 1100, 1);
      element.textContent = `${Math.round(target * (1 - Math.pow(1 - progress, 3))).toLocaleString('pt-BR')}${suffix}`;
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
        ? parsedDate.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
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
          ? parsedDate.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
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
    return Number.isNaN(date.getTime()) ? '--' : date.toLocaleString('pt-BR', {
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
    const query = String(state.globalSearch || '').trim().toLocaleLowerCase('pt-BR');
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
        .sort((a, b) => new Date(b.ultimo_backup || 0).getTime() - new Date(a.ultimo_backup || 0).getTime())
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
      setText(dateId, date && !Number.isNaN(date.getTime()) ? date.toLocaleDateString('pt-BR') : '--');
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

    const companyGroups = items.reduce((groups, item) => {
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
        const missingTimes = Array.isArray(item.horarios_nao_feitos) ? item.horarios_nao_feitos : [];
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
      const label = new Date().toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' });
      operationDate.innerHTML = `<span class="pulse"></span> OPERACAO AO VIVO - ${label.toUpperCase()}`;
    }

    const failures = Number(dashboard.backups_com_falha || 0);
    const principalAlerta = state.alerts[0] || null;
    setText('signalTitle', failures > 0 ? 'Operacao com pontos de atencao' : 'Operacao estavel');
    setText(
      'signalText',
      failures > 0
        ? `${fmtInt(failures)} falhas exigem revisao. Principal ponto: ${principalAlerta ? `${alertLocation(principalAlerta)} - ${alertCause(principalAlerta)}` : 'verificar alertas abertos'}.`
        : 'Os backups recentes indicam um ambiente protegido e sem falhas criticas agora.'
    );

    const executionCount = document.querySelector('.rail-item[data-section="executions"] em');
    const alertCount = document.querySelector('.danger-count');
    if (executionCount) executionCount.textContent = String(dashboard.total_backups || 0);
    if (alertCount) alertCount.textContent = state.alertsLoaded ? String(state.alerts.length) : '--';
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
    setText('kpiHintC', `${fmtInt(dashboard.backups_com_falha || 0)} falhas identificadas para tratamento.`);
    setText(
      'kpiHintD',
      dashboard.armazenamento_disponivel === false
        ? 'A Acronis nao retornou o consumo de armazenamento.'
        : `${formatBytes(dashboard.espaco_utilizado || 0)} consumidos no ambiente protegido.`
    );
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
    setText('summaryAlerts', state.alertsLoaded ? fmtInt(state.alerts.length) : '--');
    setText('summaryClients', fmtInt(dashboard.total_clientes || state.customers.length || 0));
    setText('summaryDevices', fmtInt(dashboard.total_dispositivos || state.devices.length || 0));
    setText('fleetClients', fmtInt(dashboard.total_clientes || state.customers.length || 0));
    setText('fleetDevices', fmtInt(dashboard.total_dispositivos || state.devices.length || 0));
    setText('fleetBackups', fmtInt(state.dashboard?.total_backups || 0));
    setText('fleetFailures', fmtInt(state.dashboard?.backups_com_falha || 0));
    setText('fleetSuccess', fmtPercent(state.dashboard?.taxa_sucesso || 0));
    setText('fleetStorage', dashboard.armazenamento_disponivel === false ? 'Indisponivel' : formatBytes(Number(dashboard.espaco_utilizado || 0)));
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

    const topCustomers = [...state.customers]
      .sort((a, b) => Number(b.quantidade_dispositivos || 0) - Number(a.quantidade_dispositivos || 0))
      .slice(0, topCustomersLimit);

    const clientLabels = topCustomers.map(item => item.nome || 'Cliente');
    const clientSeries = topCustomers.map(item => Number(item.quantidade_dispositivos || 0));
    const dailySeries = Array.isArray(dashboard.series_diarias) ? dashboard.series_diarias : [];
    const historyLabels = dailySeries.map(item => item.label || '--');
    const jobsHistory = dailySeries.map(item => Number(item.backups || 0));
    const usedPct = dashboard.armazenamento_disponivel === false ? 0 : 100;
    renderDailyHistory(dailySeries);

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
      mountFallbackBars('clientBar', clientSeries, clientLabels, c.violet);
      mountFallbackBars('historyArea', dailySeries.slice(-14).map(item => Number(item.backups || 0)), historyLabels.slice(-14), c.green);
      return;
    }

    mount('statusDonut', {
      ...chartBase(235),
      chart: { ...chartBase(235).chart, type: 'donut' },
      series: [completed, failed, other],
      labels: ['Sucesso', 'Falha', 'Outros'],
      colors: [c.green, c.red, '#708096'],
      stroke: { width: 0 },
      legend: { ...chartBase(235).legend, show: false },
      plotOptions: {
        pie: {
          donut: {
            size: '72%',
            labels: {
              show: true,
              name: { show: true, color: c.text, fontSize: '10px' },
              value: { show: true, color: '#fff', fontSize: '25px', fontWeight: 700, offsetY: 4 },
              total: { show: true, label: 'Total de backups', color: c.text, formatter: () => String(dashboard.total_backups || 0) }
            }
          }
        }
      }
    });

    mount('executionLine', {
      ...chartBase(255),
      chart: { ...chartBase(255).chart, type: 'bar' },
      series: [{ name: 'Execucoes', data: jobsHistory }],
      colors: [c.violet2],
      plotOptions: { bar: { borderRadius: 4, columnWidth: '58%' } },
      dataLabels: { enabled: false },
      xaxis: { ...chartBase(255).xaxis, categories: historyLabels },
      yaxis: { ...chartBase(255).yaxis, min: 0, forceNiceScale: true },
      tooltip: { ...chartBase(255).tooltip, y: { formatter: value => `${fmtInt(value)} execucoes` } }
    });

    mount('storageRadial', {
      ...chartBase(230),
      chart: { ...chartBase(230).chart, type: 'radialBar' },
      series: [usedPct],
      labels: ['Volume protegido'],
      colors: [c.violet2],
      plotOptions: {
        radialBar: {
          startAngle: -135,
          endAngle: 135,
          hollow: { size: '68%' },
          track: { background: root.dataset.mode === 'light' ? '#e8e8e8' : '#253247' },
          dataLabels: {
            name: { color: c.text, fontSize: '10px', offsetY: 31 },
            value: { color: root.dataset.mode === 'light' ? '#0f0f0f' : '#fff', fontSize: '25px', offsetY: -5, formatter: () => formatBytes(Number(dashboard.espaco_utilizado || 0)) }
          }
        }
      },
      legend: { show: false },
      subtitle: { text: 'Uso real retornado pela Acronis', align: 'center', offsetY: 14, style: { color: c.text, fontSize: '10px' } }
    });

    mount('clientBar', {
      ...chartBase(245),
      chart: { ...chartBase(245).chart, type: 'bar' },
      series: [{ name: 'Dispositivos protegidos', data: clientSeries.length ? clientSeries : [0] }],
      colors: [c.violet, c.cyan, c.green, c.amber, c.violet2],
      plotOptions: { bar: { borderRadius: 6, columnWidth: '50%', distributed: true } },
      xaxis: { ...chartBase(245).xaxis, categories: clientLabels.length ? clientLabels : ['Sem dados'] },
      yaxis: { ...chartBase(245).yaxis, tickAmount: 4 },
      legend: { ...chartBase(245).legend, show: true }
    });

    mount('historyArea', {
      ...chartBase(275),
      chart: { ...chartBase(275).chart, type: 'bar', stacked: true },
      series: [
        { name: 'Sucesso', data: dailySeries.map(item => Number(item.success || 0)) },
        { name: 'Falha', data: dailySeries.map(item => Number(item.failed || 0)) }
      ],
      colors: [c.green, c.red],
      plotOptions: { bar: { borderRadius: 3, columnWidth: '62%' } },
      stroke: { width: 0 },
      xaxis: { ...chartBase(275).xaxis, categories: historyLabels.length ? historyLabels : ['Sem dados'] },
      yaxis: { ...chartBase(275).yaxis, min: 0, forceNiceScale: true, title: { text: 'Execucoes', style: { color: c.text } } },
      tooltip: { ...chartBase(275).tooltip, y: { formatter: value => `${fmtInt(value)} execucoes` } }
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
    safeRun(buildCharts);
    safeRun(buildMicroCharts);
    safeRun(buildRecentRows);
    safeRun(buildExecutionWindowRows);
    safeRun(buildDailyExecutionRows);
    safeRun(animateKpis);
  };

  const renderPartialData = () => {
    safeRun(updateHero);
    safeRun(updateKpis);
    safeRun(buildRecentRows);
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

  const loadDashboard = () => requestOnce('dashboard', async () => {
    try {
      state.dashboard = await fetchJson('dashboard.php') || {};
      renderData();
    } catch (error) {
      markDashboardUnavailable();
      notify(error.message || 'Falha ao carregar dashboard.');
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

  const loadAlerts = () => requestOnce('alerts', async () => {
    try {
      const alerts = await fetchJson('alertas.php');
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

  const loadCustomers = () => requestOnce('customers', async () => {
    try {
      const customers = await fetchJson('clientes.php');
      state.customers = Array.isArray(customers) ? customers : [];
      renderPartialData();
      if (state.dashboard) safeRun(buildCharts);
    } catch (error) {
      notify(error.message || 'Falha ao carregar clientes.');
      throw error;
    }
  });

  const loadExecutionWindows = () => requestOnce('execution-windows', async () => {
    state.executionWindowsError = '';
    try {
      state.executionWindows = await fetchJson('execution-windows.php') || { items: [] };
      safeRun(buildExecutionWindowRows);
    } catch (error) {
      state.executionWindowsError = error.message || 'Falha ao carregar janelas.';
      notify(state.executionWindowsError);
      safeRun(buildExecutionWindowRows);
      throw error;
    }
  });

  const loadDailyExecutions = () => requestOnce('daily-executions', async () => {
    state.dailyExecutionsError = '';
    try {
      state.dailyExecutions = await fetchJson('execucoes-diarias.php') || { hoje: [], ontem: [], datas: {} };
      safeRun(buildDailyExecutionRows);
    } catch (error) {
      state.dailyExecutionsError = error.message || 'Falha ao carregar execucoes diarias.';
      notify(state.dailyExecutionsError);
      safeRun(buildDailyExecutionRows);
      throw error;
    }
  });

  const sectionLoaders = {
    overview: [loadDashboard],
    executions: [loadDevices],
    storage: [loadDashboard],
    summary: [loadDashboard, loadAlerts],
    windows: [loadExecutionWindows],
    clients: [loadDashboard, loadCustomers],
    infrastructure: [loadDailyExecutions],
    analytics: [loadDashboard, loadCustomers]
  };

  const sectionLabels = {
    overview: 'Visao geral',
    executions: 'Execucoes',
    storage: 'Armazenamento',
    summary: 'Resumo',
    windows: 'Janelas',
    clients: 'Clientes',
    infrastructure: 'Infraestrutura',
    analytics: 'Analises'
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

  const activateSection = (section, updateHistory = true) => {
    const selected = sectionLabels[section] ? section : 'overview';
    root.dataset.activeSection = selected;
    document.querySelectorAll('[data-panel-section]').forEach(panel => {
      panel.hidden = panel.dataset.panelSection !== selected;
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

    if (updateHistory && window.location.hash !== `#${selected}`) {
      window.history.pushState({ section: selected }, '', `#${selected}`);
    }

    loadSectionData(selected).then(() => {
      requestAnimationFrame(() => {
        if (state.dashboard) safeRun(buildCharts);
        window.dispatchEvent(new Event('resize'));
      });
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

  theme.apply(theme.get());
  window.lucide?.createIcons();

  document.getElementById('modeSwitch')?.addEventListener('click', () => theme.toggle());
  railOpenButton?.addEventListener('click', openRail);
  document.getElementById('railClose')?.addEventListener('click', closeRail);
  railScrim?.addEventListener('click', closeRail);
  document.querySelectorAll('.rail-item[data-section]').forEach(item => item.addEventListener('click', event => {
    event.preventDefault();
    activateSection(item.dataset.section || 'overview');
    if (!desktopRail.matches) closeRail();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }));
  document.querySelectorAll('.rail-item:not([data-section])').forEach(item => item.addEventListener('click', () => {
    if (!desktopRail.matches) closeRail();
  }));
  document.querySelectorAll('[data-toast]').forEach(button => button.addEventListener('click', () => notify(button.dataset.toast)));
  document.getElementById('runBackup')?.addEventListener('click', event => {
    event.preventDefault();
    notify('Execucao manual ainda nao integrada a API da Acronis.');
  });
  document.getElementById('profileButton')?.addEventListener('click', () => notify('Perfil indisponivel neste painel.'));
  document.getElementById('windowsSearch')?.addEventListener('input', event => {
    state.windowsSearch = String(event.currentTarget.value || '').trim();
    safeRun(buildExecutionWindowRows);
  });
  document.getElementById('globalSearch')?.addEventListener('input', event => {
    state.globalSearch = String(event.currentTarget.value || '').trim();
    safeRun(buildRecentRows);
    safeRun(buildDailyExecutionRows);
  });

  const restoreSection = () => activateSection(sectionFromLocation(), false);
  window.addEventListener('popstate', restoreSection);
  window.addEventListener('hashchange', restoreSection);
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
  setRailOpen(desktopRail.matches ? localStorage.getItem('nyxcloud-rail-collapsed') !== '1' : false);
  activateSection(initialSection, false);
})();


