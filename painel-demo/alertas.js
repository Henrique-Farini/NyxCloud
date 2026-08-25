(() => {
  'use strict';

  const body = document.body;
  const basePageSize = 10;
  const state = { rows: [] };
  let page = 1;
  let sortKey = 'time';
  let sortDir = 'desc';
  let toastTimer;
  let resizeTimer;
  let loadingRows = false;
  let currentDetailRow = null;

  const toast = document.getElementById('alertsToast');
  const detailsDialog = document.getElementById('alertDetailsDialog');
  const rail = document.getElementById('rail');
  const railOpenButton = document.getElementById('railOpen');
  const railScrim = document.getElementById('railScrim');
  const desktopRail = window.matchMedia('(min-width: 1061px)');
  const jwtToken = () => localStorage.getItem('access_token') || sessionStorage.getItem('access_token') || '';
  const authHeaders = () => jwtToken() ? { Authorization: `Bearer ${jwtToken()}` } : {};
  const redirectToLogin = () => {
    const login = new URL('../back/index.php', window.location.href);
    login.searchParams.set('next', window.location.pathname + window.location.search + window.location.hash);
    window.location.replace(login.href);
  };

  const fetchJson = async endpoint => {
    const response = await fetch(`../back/api/${endpoint}`, {
      headers: { Accept: 'application/json', ...authHeaders() },
      credentials: 'include'
    });
    const payload = await response.json().catch(() => null);

    if (!response.ok || !payload?.success) {
      if (response.status === 401) {
        localStorage.removeItem('access_token');
        sessionStorage.removeItem('access_token');
        redirectToLogin();
      }
      throw new Error(payload?.message || `Falha ao carregar ${endpoint}`);
    }

    return payload.data;
  };

  const notify = message => {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 2600);
  };

  const renderProfile = user => {
    const name = String(user?.nome || 'Usuario').trim();
    const role = String(user?.perfil_nome || 'Perfil').trim();
    const initial = (name.charAt(0) || 'U').toUpperCase();
    ['alertsRailProfileName', 'alertsCommandProfileName'].forEach(id => {
      const node = document.getElementById(id);
      if (node) node.textContent = name;
    });
    ['alertsRailProfileRole', 'alertsCommandProfileRole'].forEach(id => {
      const node = document.getElementById(id);
      if (node) node.textContent = role;
    });
    ['alertsRailProfileAvatar', 'alertsCommandProfileAvatar'].forEach(id => {
      const node = document.getElementById(id);
      if (node) node.textContent = initial;
    });
  };

  fetchJson('me.php').then(renderProfile).catch(() => {});

  const setSyncStatus = (message, stateName = 'ready') => {
    const node = document.getElementById('alertsSyncStatus');
    if (!node) return;
    node.dataset.state = stateName;
    const label = node.querySelector('span');
    if (label) label.textContent = message;
  };

  const syncTimeLabel = () => `Atualizado ${new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`;
  const knownStorageKey = 'nyxcloud-known-alerts';
  const hiddenStorageKey = 'nyxcloud-hidden-alerts';

  const statusMeta = {
    failed: ['Falha', 'circle-x'],
    success: ['Resolvido', 'circle-check'],
    running: ['Em andamento', 'clock-3'],
    known: ['Conhecido', 'shield-check']
  };

  const priorityLabel = {
    critical: 'Crítica',
    high: 'Alta',
    medium: 'Média',
    low: 'Baixa'
  };

  const alertTypeLabels = {
    AgentAutoUpdateFailed: 'Falha na atualização do agente',
    PlanDeploymentFailed: 'Falha ao aplicar o plano',
    BackupDidNotStart: 'Backup não iniciado',
    BackupNotResponding: 'Backup sem resposta',
    BackupStatusUnknown: 'Status do backup desconhecido',
    MachineOffline30: 'Maquina offline ha mais de 30 minutos',
    M365ApplicationConsentRequired: 'Microsoft 365 sem consentimento do aplicativo',
    NETWORK_ERROR: 'Falha de comunicacao com a nuvem Acronis',
    BACKUP_FAILED: 'Falha na execucao do backup',
    BACKUP_ZERO_SIZE: 'Backup concluido com tamanho zerado',
    BACKUP_BELOW_BASELINE: 'Backup com tamanho abaixo do padrao historico'
  };

  const cleanText = value => {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();
    return text || '--';
  };
  const sameText = (left, right) => cleanText(left).toLocaleLowerCase('pt-BR') === cleanText(right).toLocaleLowerCase('pt-BR');
  const looksLikeIp = value => /^(?:\d{1,3}\.){3}\d{1,3}$/.test(cleanText(value));
  const readStoredSet = key => {
    try {
      return new Set(JSON.parse(localStorage.getItem(key) || '[]').filter(Boolean));
    } catch {
      return new Set();
    }
  };
  const writeStoredSet = (key, values) => localStorage.setItem(key, JSON.stringify([...values]));
  const knownAlertKeys = () => readStoredSet(knownStorageKey);
  const hiddenAlertKeys = () => readStoredSet(hiddenStorageKey);
  const rowStorageKey = row => [row.sourceId || '', row.client, row.server, row.code, row.sortTime].join('|').toLocaleLowerCase('pt-BR');
  const isKnownAlert = row => knownAlertKeys().has(rowStorageKey(row));
  const isHiddenAlert = row => hiddenAlertKeys().has(rowStorageKey(row));
  const effectiveStatus = row => isKnownAlert(row) ? 'known' : row.status;
  const toggleKnownAlert = row => {
    const items = knownAlertKeys();
    const key = rowStorageKey(row);
    const nextKnown = !items.has(key);
    if (nextKnown) items.add(key);
    else items.delete(key);
    writeStoredSet(knownStorageKey, items);
    return nextKnown;
  };
  const hideAlert = row => {
    const items = hiddenAlertKeys();
    items.add(rowStorageKey(row));
    writeStoredSet(hiddenStorageKey, items);
  };
  const clearHiddenAlerts = () => writeStoredSet(hiddenStorageKey, new Set());

  const alertGuidance = row => {
    if (row.code === 'M365ApplicationConsentRequired') {
      return {
        reason: 'A Acronis detectou que o tenant Microsoft 365 deste cliente nao concedeu, perdeu ou revogou o consentimento do aplicativo usado no backup.',
        action: 'Reautorizar a integracao Microsoft 365 no tenant do cliente com uma conta administradora global e confirmar que o aplicativo da Acronis esta com consentimento ativo.'
      };
    }

    return null;
  };

  const isDataPlan = value => {
    const text = String(value ?? '').trim();
    return /\bdados\b/i.test(text);
  };

  const displayPlan = row => isDataPlan(row.plan) && row.status !== 'failed' ? '--' : row.plan;

  const cell = (tag, text, className = '') => {
    const element = document.createElement(tag);
    element.textContent = text;
    if (className) element.className = className;
    return element;
  };

  const readableType = value => {
    const type = cleanText(value);
    return alertTypeLabels[type] || type;
  };

  const readableCode = value => {
    const code = cleanText(value);
    return alertTypeLabels[code] || code;
  };

  const readableSize = value => {
    const size = cleanText(value);
    const labels = {
      'Nao informado': 'Não informado',
      'Nao informado pela Acronis': 'Não informado pela Acronis',
      'Nao gerado': 'Não gerado'
    };
    labels['Nao se aplica'] = 'Não se aplica';
    return labels[size] || size;
  };

  const escapeHtml = value => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const isoFromAlert = alert => {
    const date = String(alert.data || '').trim();
    const time = String(alert.hora || '00:00:00').trim();
    if (!date) return '';
    return date.includes('T') ? date : `${date}T${time}`;
  };

  const formatAlertTime = iso => {
    const date = new Date(iso);
    if (!iso || Number.isNaN(date.getTime())) return '--';
    return date.toLocaleString('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
    });
  };

  const statusFromAlert = alert => {
    const status = String(alert.status || '').toLowerCase();
    if (['dismissed', 'resolved', 'closed'].includes(status)) return 'success';
    if (['running', 'in_progress', 'processing'].includes(status)) return 'running';
    return 'failed';
  };

  const severityToPriority = severity => {
    const value = String(severity || '').toLowerCase();
    if (value === 'critical') return 'critical';
    if (['high', 'error'].includes(value)) return 'high';
    if (['warning', 'medium'].includes(value)) return 'medium';
    return 'low';
  };

  const mapAlertRow = (alert, index) => {
    const sortTime = isoFromAlert(alert);
    const resource = cleanText(alert.recurso || alert.maquina || alert.raw?.resourceName || 'Recurso não informado');
    const server = cleanText(alert.maquina || resource);
    const sourceId = cleanText(alert.raw?.id || alert.raw?.uuid || alert.raw?.alertId || '');
    const type = readableType(alert.tipo || alert.raw?.type || 'Acronis');
    const code = readableCode(alert.codigo || '');
    return {
      status: statusFromAlert(alert),
      priority: severityToPriority(alert.severidade),
      client: cleanText(alert.cliente || 'Cliente não identificado'),
      server,
      task: cleanText(alert.mensagem || type),
      code,
      type,
      error: cleanText(alert.causa || alert.mensagem || 'Causa não informada'),
      location: cleanText(alert.ip || alert.origem || server),
      plan: cleanText(alert.plano || resource),
      size: readableSize(alert.tamanho || 'Não informado'),
      time: formatAlertTime(sortTime),
      sortTime,
      sourceId: sourceId === '--' ? '' : sourceId,
      id: index + 1
    };
  };

  const deduplicateRows = rows => {
    const seen = new Set();
    return rows.filter(row => {
      const fallback = [row.client, row.server, row.code, row.sortTime, row.plan, row.error].join('|');
      const key = (row.sourceId || fallback).toLocaleLowerCase('pt-BR');
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  };

  const selectedPeriod = () => document.getElementById('periodFilter')?.value || '30';
  const startOfDay = date => new Date(date.getFullYear(), date.getMonth(), date.getDate());

  const isInsidePeriod = row => {
    const period = selectedPeriod();
    const eventDate = new Date(row.sortTime);
    if (!row.sortTime || Number.isNaN(eventDate.getTime())) return period === '30';

    const today = startOfDay(new Date());
    const eventDay = startOfDay(eventDate);
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);

    if (period === 'today') return eventDay.getTime() === today.getTime();
    if (period === 'yesterday') {
      const yesterday = new Date(today);
      yesterday.setDate(yesterday.getDate() - 1);
      return eventDay.getTime() === yesterday.getTime();
    }

    const days = Math.max(1, Number(period) || 30);
    const start = new Date(today);
    start.setDate(start.getDate() - (days - 1));
    return eventDate >= start && eventDate < tomorrow;
  };

  const periodRows = () => state.rows.filter(isInsidePeriod);

  const getFilters = () => ({
    status: document.getElementById('statusFilter').value,
    priority: document.getElementById('priorityFilter').value,
    client: document.getElementById('clientFilter').value,
    server: document.getElementById('serverFilter').value,
    search: document.getElementById('searchFilter').value.trim().toLocaleLowerCase('pt-BR')
  });

  const filteredRows = () => {
    const filters = getFilters();
    const factor = sortDir === 'asc' ? 1 : -1;

    return periodRows()
      .filter(row => !isHiddenAlert(row))
      .filter(row =>
        (filters.status === 'all' || effectiveStatus(row) === filters.status) &&
        (filters.priority === 'all' || row.priority === filters.priority) &&
        (filters.client === 'all' || row.client === filters.client) &&
        (filters.server === 'all' || row.server === filters.server) &&
        (!filters.search || [row.client, row.server, row.task, row.code, row.type, row.error, row.location, row.plan, row.size]
          .join(' ').toLocaleLowerCase('pt-BR').includes(filters.search))
      )
      .sort((left, right) => {
        const leftValue = sortKey === 'time' ? left.sortTime : sortKey === 'status' ? effectiveStatus(left) : left[sortKey];
        const rightValue = sortKey === 'time' ? right.sortTime : sortKey === 'status' ? effectiveStatus(right) : right[sortKey];
        return String(leftValue || '').localeCompare(String(rightValue || ''), 'pt-BR', { numeric: true }) * factor;
      });
  };

  const updateRestoreButton = () => {
    const button = document.getElementById('restoreHiddenAlerts');
    if (!button) return;
    const total = hiddenAlertKeys().size;
    button.hidden = total === 0;
    const label = button.querySelector('span');
    if (label) label.textContent = total > 0 ? `Restaurar ocultos (${total})` : 'Restaurar ocultos';
  };

  const showDetails = row => {
    if (!detailsDialog) return;
    currentDetailRow = row;
    document.getElementById('alertDetailsTitle').textContent = `${row.client} - ${row.server}`;
    const content = document.getElementById('alertDetailsContent');
    content.replaceChildren();
    const known = effectiveStatus(row) === 'known';
    [
      ['Status', statusMeta[known ? 'known' : row.status]?.[0] || row.status],
      ['Prioridade', priorityLabel[row.priority] || row.priority],
      ['Cliente', row.client],
      ['Dispositivo', row.server],
      ['IP / origem', row.location],
      ['Tipo', row.type],
      ['Plano', displayPlan(row)],
      ['Tamanho', row.size],
      ['Horário', row.time],
      ['Código', row.code],
      ['Motivo', row.error],
      ['Tratativa', known ? 'Marcado como conhecido neste painel.' : 'Sem tratativa manual.']
    ].forEach(([label, value]) => {
      const group = document.createElement('div');
      group.append(cell('dt', label), cell('dd', value));
      content.append(group);
    });
    const guidance = alertGuidance(row);
    if (guidance) {
      [
        ['Motivo real', guidance.reason],
        ['Acao sugerida', guidance.action]
      ].forEach(([label, value]) => {
        const group = document.createElement('div');
        group.append(cell('dt', label), cell('dd', value));
        content.append(group);
      });
    }
    const markKnownButton = document.getElementById('markKnownAlert');
    const markKnownLabel = markKnownButton?.querySelector('span');
    if (markKnownLabel) markKnownLabel.textContent = known ? 'Remover conhecido' : 'Marcar conhecido';
    detailsDialog.showModal();
    window.lucide?.createIcons();
  };

  const renderPagination = totalPages => {
    const container = document.getElementById('pagination');
    container.replaceChildren();

    const addButton = (label, target, active = false, disabled = false, ariaLabel = '') => {
      const button = cell('button', label, active ? 'active' : '');
      button.type = 'button';
      button.disabled = disabled;
      if (ariaLabel) button.setAttribute('aria-label', ariaLabel);
      button.addEventListener('click', () => {
        page = target;
        renderRows();
      });
      container.append(button);
    };

    addButton('<', Math.max(1, page - 1), false, page === 1, 'Página anterior');
    const pages = [...new Set([1, totalPages, page - 2, page - 1, page, page + 1, page + 2])]
      .filter(value => value >= 1 && value <= totalPages)
      .sort((a, b) => a - b);
    pages.forEach((value, index) => {
      if (index && value - pages[index - 1] > 1) container.append(cell('span', '...'));
      addButton(String(value), value, value === page, false, `Página ${value}`);
    });
    addButton('>', Math.min(totalPages, page + 1), false, page === totalPages, 'Próxima página');
  };

  const visiblePageSize = () => {
    if (!body.classList.contains('alerts-maximized')) return basePageSize;

    const table = document.querySelector('.responsive-table');
    const availableHeight = table?.clientHeight || Math.max(360, window.innerHeight - 150);
    const headerHeight = 46;
    const estimatedRowHeight = 58;
    return Math.max(basePageSize, Math.floor((availableHeight - headerHeight) / estimatedRowHeight));
  };

  const renderRows = () => {
    const tbody = document.getElementById('alertRows');
    tbody.replaceChildren();
    const data = filteredRows();
    const pageSize = visiblePageSize();
    const totalPages = Math.max(1, Math.ceil(data.length / pageSize));
    page = Math.min(page, totalPages);
    const visible = data.slice((page - 1) * pageSize, page * pageSize);

    if (!visible.length) {
      const tr = document.createElement('tr');
      const empty = cell('td', 'Nenhum alerta encontrado com estes filtros.', 'table-empty');
      empty.colSpan = 9;
      tr.append(empty);
      tbody.append(tr);
    }

    visible.forEach(row => {
      const tr = document.createElement('tr');
      const visualStatus = effectiveStatus(row);
      const [statusLabel, statusIconName] = statusMeta[visualStatus] || statusMeta.failed;

      const statusTd = document.createElement('td');
      const status = cell('span', statusLabel, `alert-status status-${visualStatus}`);
      const statusIcon = document.createElement('i');
      statusIcon.dataset.lucide = statusIconName;
      status.append(statusIcon);
      statusTd.append(status);

      const client = cell('td', row.client);
      const priority = document.createElement('span');
      priority.className = `priority-dot priority-${row.priority}`;
      priority.title = priorityLabel[row.priority] || row.priority;
      client.prepend(priority);

      const task = document.createElement('td');
      task.className = 'task-cell';
      task.append(cell('b', row.server));

      const location = document.createElement('td');
      location.className = 'task-cell';
      location.append(cell('b', row.location));

      const actions = document.createElement('td');
      actions.className = 'row-actions';
      const details = document.createElement('button');
      details.type = 'button';
      details.dataset.tooltip = 'Ver detalhes';
      details.setAttribute('aria-label', `Ver detalhes de ${row.client}`);
      const detailsIcon = document.createElement('i');
      detailsIcon.dataset.lucide = 'eye';
      details.append(detailsIcon);
      details.addEventListener('click', () => showDetails(row));
      actions.append(details);

      const rowCells = [
        statusTd,
        client,
        task,
        location,
        cell('td', row.error),
        cell('td', row.time, 'alert-mono'),
        cell('td', displayPlan(row)),
        cell('td', row.size),
        actions
      ];
      ['Status', 'Cliente', 'Dispositivo', 'IP', 'Motivo', 'Horario', 'Plano', 'Tamanho', 'Acoes']
        .forEach((label, index) => { rowCells[index].dataset.label = label; });
      rowCells.forEach(item => tr.append(item));
      tr.className = 'is-filtered';
      tbody.append(tr);
    });

    const failures = data.filter(row => effectiveStatus(row) === 'failed').length;
    document.getElementById('failureBadge').textContent = `${failures} ${failures === 1 ? 'falha' : 'falhas'}`;
    document.getElementById('resultSummary').textContent = data.length
      ? `Mostrando ${(page - 1) * pageSize + 1} a ${Math.min(page * pageSize, data.length)} de ${data.length} resultados`
      : 'Nenhum alerta encontrado';
    const filters = getFilters();
    document.querySelectorAll('[data-status-filter]').forEach(card => {
      const selected = card.dataset.statusFilter === filters.status;
      card.classList.toggle('is-active', selected);
      card.setAttribute('aria-pressed', String(selected));
    });
    document.querySelectorAll('th[data-sort]').forEach(header => {
      if (header.dataset.sort === sortKey) header.setAttribute('aria-sort', sortDir === 'asc' ? 'ascending' : 'descending');
      else header.removeAttribute('aria-sort');
    });
    renderPagination(totalPages);
    updateRestoreButton();
    window.lucide?.createIcons();
  };

  const populateSelects = () => {
    const fill = (id, values) => {
      const select = document.getElementById(id);
      select.querySelectorAll('option:not([value="all"])').forEach(option => option.remove());
      [...new Set(values.filter(value => value && value !== '--'))]
        .sort((a, b) => a.localeCompare(b, 'pt-BR'))
        .forEach(value => {
          const option = cell('option', value);
          option.value = value;
          select.append(option);
        });
    };
    fill('clientFilter', state.rows.map(row => row.client));
    fill('serverFilter', state.rows.map(row => row.server));
  };

  const updateKpis = () => {
    const rows = periodRows();
    const visibleRows = rows.filter(row => !isHiddenAlert(row));
    const values = [
      visibleRows.length,
      visibleRows.filter(row => effectiveStatus(row) === 'success').length,
      visibleRows.filter(row => effectiveStatus(row) === 'failed').length,
      visibleRows.filter(row => effectiveStatus(row) === 'running').length
    ];
    document.querySelectorAll('[data-alert-count]').forEach((element, index) => {
      element.dataset.alertCount = String(values[index] || 0);
      element.textContent = Number(values[index] || 0).toLocaleString('pt-BR');
    });
    const dangerCount = document.querySelector('.danger-count');
    if (dangerCount) dangerCount.textContent = String(values[2]);
  };

  const downloadBlob = (blob, filename) => {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 0);
  };

  const exportData = kind => {
    const rows = filteredRows();
    if (!rows.length) {
      notify('Nenhum alerta disponível para exportação.');
      return;
    }

    const headers = ['Status', 'Prioridade', 'Cliente', 'Dispositivo', 'Alerta', 'IP / origem', 'Tipo', 'Motivo', 'Horário', 'Plano', 'Tamanho'];
    const values = rows.map(row => [statusMeta[effectiveStatus(row)]?.[0] || effectiveStatus(row), priorityLabel[row.priority] || row.priority, row.client, row.server, row.code, row.location, row.type, row.error, row.time, displayPlan(row), row.size]);

    if (kind === 'pdf') {
      const popup = window.open('', '_blank');
      if (!popup) {
        notify('Permita pop-ups para exportar PDF.');
        return;
      }
      popup.document.write(`<title>Alertas de Backups</title><style>body{font:12px Arial;padding:24px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:7px;text-align:left;vertical-align:top}th{background:#eee}</style><h1>Alertas de Backups</h1><table><thead><tr>${headers.map(header => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${values.map(row => `<tr>${row.map(value => `<td>${escapeHtml(value)}</td>`).join('')}</tr>`).join('')}</tbody></table>`);
      popup.document.close();
      popup.print();
      return;
    }

    if (kind === 'excel') {
      const html = `<!doctype html><meta charset="UTF-8"><table><thead><tr>${headers.map(header => `<th>${escapeHtml(header)}</th>`).join('')}</tr></thead><tbody>${values.map(row => `<tr>${row.map(value => `<td>${escapeHtml(value)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
      downloadBlob(new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' }), 'nyxcloud-alertas.xls');
    } else {
      const csv = [headers, ...values].map(row => row.map(value => `"${String(value).replaceAll('"', '""')}"`).join(';')).join('\r\n');
      downloadBlob(new Blob([`\ufeff${csv}`], { type: 'text/csv;charset=utf-8' }), 'nyxcloud-alertas.csv');
    }
    notify(`${kind === 'excel' ? 'Excel' : 'CSV'} exportado com ${values.length} registros.`);
  };

  const loadRows = async ({ announce = false } = {}) => {
    if (loadingRows) return;
    loadingRows = true;
    const table = document.getElementById('alertsTable');
    table?.setAttribute('aria-busy', 'true');
    setSyncStatus('Atualizando alertas', 'loading');

    try {
      const alerts = await fetchJson('alertas.php');
      state.rows = deduplicateRows((Array.isArray(alerts) ? alerts : []).map(mapAlertRow))
        .map(row => ({
          ...row,
          error: [
            !sameText(row.type, row.error) ? row.type : '',
            !looksLikeIp(row.location) && row.location !== '--' && !sameText(row.location, row.server) ? row.location : '',
            row.error
          ].filter(Boolean).join(' - '),
          location: looksLikeIp(row.location) ? row.location : '--'
        }));
      populateSelects();
      updateKpis();
      renderRows();
      setSyncStatus(syncTimeLabel(), 'ready');
      if (announce) notify('Alertas atualizados.');
    } catch (error) {
      setSyncStatus('Falha na sincronizacao', 'warning');
      throw error;
    } finally {
      loadingRows = false;
      table?.removeAttribute('aria-busy');
    }
  };

  const resetFilters = () => {
    ['statusFilter', 'priorityFilter', 'clientFilter', 'serverFilter'].forEach(id => {
      document.getElementById(id).value = 'all';
    });
    document.getElementById('searchFilter').value = '';
    document.getElementById('globalAlertSearch').value = '';
    page = 1;
    renderRows();
  };

  const setRailOpen = open => {
    if (desktopRail.matches) {
      body.classList.toggle('rail-collapsed', !open);
      rail?.classList.remove('is-open');
      railScrim?.classList.remove('is-visible');
    } else {
      body.classList.remove('rail-collapsed');
      rail?.classList.toggle('is-open', open);
      railScrim?.classList.toggle('is-visible', open);
    }
    railOpenButton?.setAttribute('aria-expanded', String(open));
  };

  const setAlertsMaximized = maximized => {
    body.classList.toggle('alerts-maximized', maximized);
    const button = document.getElementById('toggleAlertsMaximize');
    const label = button?.querySelector('span');
    const icon = button?.querySelector('i');
    button?.setAttribute('aria-pressed', String(maximized));
    button?.setAttribute('aria-label', maximized ? 'Restaurar lista de alertas' : 'Maximizar lista de alertas');
    if (label) label.textContent = maximized ? 'Restaurar' : 'Maximizar';
    if (icon) icon.dataset.lucide = maximized ? 'minimize-2' : 'maximize-2';
    localStorage.setItem('nyxcloud-alerts-maximized', maximized ? '1' : '0');
    window.lucide?.createIcons();
    requestAnimationFrame(renderRows);
  };

  const openRail = () => {
    if (desktopRail.matches) localStorage.setItem('nyxcloud-rail-collapsed', '0');
    setRailOpen(true);
  };

  const closeRail = () => {
    if (desktopRail.matches) localStorage.setItem('nyxcloud-rail-collapsed', '1');
    setRailOpen(false);
  };

  body.dataset.mode = localStorage.getItem('nyxcloud-console-mode') || 'dark';
  window.lucide?.createIcons();

  document.getElementById('modeSwitch')?.addEventListener('click', () => {
    body.dataset.mode = body.dataset.mode === 'dark' ? 'light' : 'dark';
    localStorage.setItem('nyxcloud-console-mode', body.dataset.mode);
  });
  railOpenButton?.addEventListener('click', openRail);
  document.getElementById('railClose')?.addEventListener('click', closeRail);
  railScrim?.addEventListener('click', closeRail);
  document.querySelectorAll('.rail-item').forEach(item => item.addEventListener('click', () => {
    if (!desktopRail.matches) closeRail();
  }));
  desktopRail.addEventListener('change', event => {
    setRailOpen(event.matches ? localStorage.getItem('nyxcloud-rail-collapsed') !== '1' : false);
  });
  window.addEventListener('keydown', event => {
    if (event.key === 'Escape' && (!desktopRail.matches || !body.classList.contains('rail-collapsed'))) closeRail();
  });
  window.addEventListener('resize', () => {
    if (!body.classList.contains('alerts-maximized')) return;
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(renderRows, 120);
  });
  document.getElementById('applyFilters')?.addEventListener('click', () => {
    page = 1;
    renderRows();
  });
  document.getElementById('clearFilters')?.addEventListener('click', resetFilters);
  document.getElementById('searchFilter')?.addEventListener('input', event => {
    document.getElementById('globalAlertSearch').value = event.currentTarget.value;
    page = 1;
    renderRows();
  });
  document.getElementById('globalAlertSearch')?.addEventListener('input', event => {
    document.getElementById('searchFilter').value = event.currentTarget.value;
    page = 1;
    renderRows();
  });
  document.getElementById('periodFilter')?.addEventListener('change', () => {
    page = 1;
    updateKpis();
    renderRows();
  });
  ['statusFilter', 'priorityFilter', 'clientFilter', 'serverFilter'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', () => {
      page = 1;
      renderRows();
    });
  });
  const filterByStatusCard = card => {
    document.getElementById('statusFilter').value = card.dataset.statusFilter || 'all';
    page = 1;
    renderRows();
    document.getElementById('alert-events')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  document.querySelectorAll('[data-status-filter]').forEach(card => {
    card.addEventListener('click', () => filterByStatusCard(card));
    card.addEventListener('keydown', event => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      filterByStatusCard(card);
    });
  });
  const sortByHeader = header => {
    const key = header.dataset.sort;
    sortDir = sortKey === key && sortDir === 'asc' ? 'desc' : 'asc';
    sortKey = key;
    page = 1;
    renderRows();
  };
  document.querySelectorAll('th[data-sort]').forEach(header => {
    header.addEventListener('click', () => sortByHeader(header));
    header.addEventListener('keydown', event => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      sortByHeader(header);
    });
  });
  document.getElementById('markKnownAlert')?.addEventListener('click', () => {
    if (!currentDetailRow) return;
    const nextKnown = toggleKnownAlert(currentDetailRow);
    updateKpis();
    renderRows();
    showDetails(currentDetailRow);
    notify(nextKnown ? 'Alerta marcado como conhecido.' : 'Marcacao removida.');
  });
  document.getElementById('hideAlertFromPanel')?.addEventListener('click', () => {
    if (!currentDetailRow) return;
    hideAlert(currentDetailRow);
    detailsDialog?.close();
    currentDetailRow = null;
    page = 1;
    updateKpis();
    renderRows();
    updateRestoreButton();
    notify('Alerta ocultado do painel.');
  });
  document.getElementById('restoreHiddenAlerts')?.addEventListener('click', () => {
    clearHiddenAlerts();
    updateKpis();
    renderRows();
    notify('Alertas ocultos restaurados.');
  });
  document.getElementById('exportCsv')?.addEventListener('click', () => exportData('csv'));
  document.getElementById('exportExcel')?.addEventListener('click', () => exportData('excel'));
  document.getElementById('exportPdf')?.addEventListener('click', () => exportData('pdf'));
  document.getElementById('toggleAlertsMaximize')?.addEventListener('click', () => {
    setAlertsMaximized(!body.classList.contains('alerts-maximized'));
  });
  document.getElementById('profileButton')?.addEventListener('click', () => notify('Perfil indisponível neste painel.'));
  document.getElementById('commandProfileButton')?.addEventListener('click', () => notify('Perfil indisponível neste painel.'));
  document.getElementById('closeAlertDetails')?.addEventListener('click', () => {
    currentDetailRow = null;
    detailsDialog?.close();
  });
  detailsDialog?.addEventListener('click', event => {
    const rect = detailsDialog.getBoundingClientRect();
    const inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
    if (!inside) {
      currentDetailRow = null;
      detailsDialog.close();
    }
  });
  detailsDialog?.addEventListener('close', () => {
    currentDetailRow = null;
  });

  if (window.parent !== window) {
    document.querySelectorAll('a[href*="index.php"]').forEach(link => {
      link.addEventListener('click', event => {
        event.preventDefault();
        window.top.location.href = new URL(link.getAttribute('href'), window.location.href).href;
      });
    });
  }

  setRailOpen(desktopRail.matches ? localStorage.getItem('nyxcloud-rail-collapsed') !== '1' : false);
  setAlertsMaximized(localStorage.getItem('nyxcloud-alerts-maximized') === '1');

  loadRows()
    .catch(error => notify(error.message || 'Falha ao carregar alertas.'));
  setInterval(() => loadRows().catch(error => notify(error.message || 'Falha ao atualizar alertas.')), 30000);
})();
