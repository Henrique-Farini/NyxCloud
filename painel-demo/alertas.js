(() => {
  'use strict';

  const body = document.body;
  const pageSize = 10;
  const state = { rows: [] };
  let page = 1;
  let sortKey = 'time';
  let sortDir = 'desc';
  let toastTimer;

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

  const statusMeta = {
    failed: ['Falha', 'circle-x'],
    success: ['Resolvido', 'circle-check'],
    running: ['Em andamento', 'clock-3']
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
    BackupStatusUnknown: 'Status do backup desconhecido'
  };

  const cleanText = value => {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();
    return text || '--';
  };

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

  const readableSize = value => {
    const size = cleanText(value);
    const labels = {
      'Nao informado': 'Não informado',
      'Nao informado pela Acronis': 'Não informado pela Acronis',
      'Nao gerado': 'Não gerado'
    };
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
    const code = cleanText(alert.codigo || '');

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
      .filter(row =>
        (filters.status === 'all' || row.status === filters.status) &&
        (filters.priority === 'all' || row.priority === filters.priority) &&
        (filters.client === 'all' || row.client === filters.client) &&
        (filters.server === 'all' || row.server === filters.server) &&
        (!filters.search || [row.client, row.server, row.task, row.code, row.type, row.error, row.location, row.plan, row.size]
          .join(' ').toLocaleLowerCase('pt-BR').includes(filters.search))
      )
      .sort((left, right) => {
        const leftValue = sortKey === 'time' ? left.sortTime : left[sortKey];
        const rightValue = sortKey === 'time' ? right.sortTime : right[sortKey];
        return String(leftValue || '').localeCompare(String(rightValue || ''), 'pt-BR', { numeric: true }) * factor;
      });
  };

  const showDetails = row => {
    if (!detailsDialog) return;
    document.getElementById('alertDetailsTitle').textContent = `${row.client} - ${row.server}`;
    const content = document.getElementById('alertDetailsContent');
    content.replaceChildren();
    [
      ['Status', statusMeta[row.status]?.[0] || row.status],
      ['Prioridade', priorityLabel[row.priority] || row.priority],
      ['Cliente', row.client],
      ['Dispositivo', row.server],
      ['IP / origem', row.location],
      ['Tipo', row.type],
      ['Plano', row.plan],
      ['Tamanho', row.size],
      ['Horário', row.time],
      ['Código', row.code],
      ['Motivo', row.error]
    ].forEach(([label, value]) => {
      const group = document.createElement('div');
      group.append(cell('dt', label), cell('dd', value));
      content.append(group);
    });
    detailsDialog.showModal();
    window.lucide?.createIcons();
  };

  const renderPagination = totalPages => {
    const container = document.getElementById('pagination');
    container.replaceChildren();

    const addButton = (label, target, active = false, disabled = false, ariaLabel = '') => {
      const button = cell('button', label, active ? 'active' : '');
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

  const renderRows = () => {
    const tbody = document.getElementById('alertRows');
    tbody.replaceChildren();
    const data = filteredRows();
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
      const [statusLabel, statusIconName] = statusMeta[row.status] || statusMeta.failed;

      const statusTd = document.createElement('td');
      const status = cell('span', statusLabel, `alert-status status-${row.status}`);
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
      task.append(cell('b', row.server), cell('small', row.code === '--' ? row.type : `${row.type} - ${row.code}`));

      const location = document.createElement('td');
      location.className = 'task-cell';
      location.append(cell('b', row.location), cell('small', row.type));

      const actions = document.createElement('td');
      actions.className = 'row-actions';
      const details = document.createElement('button');
      details.dataset.tooltip = 'Ver detalhes';
      details.setAttribute('aria-label', `Ver detalhes de ${row.client}`);
      const detailsIcon = document.createElement('i');
      detailsIcon.dataset.lucide = 'eye';
      details.append(detailsIcon);
      details.addEventListener('click', () => showDetails(row));
      actions.append(details);

      [
        statusTd,
        client,
        task,
        location,
        cell('td', row.error),
        cell('td', row.time, 'alert-mono'),
        cell('td', row.plan),
        cell('td', row.size),
        actions
      ].forEach(item => tr.append(item));
      tr.className = 'is-filtered';
      tbody.append(tr);
    });

    const failures = data.filter(row => row.status === 'failed').length;
    document.getElementById('failureBadge').textContent = `${failures} ${failures === 1 ? 'falha' : 'falhas'}`;
    document.getElementById('resultSummary').textContent = data.length
      ? `Mostrando ${(page - 1) * pageSize + 1} a ${Math.min(page * pageSize, data.length)} de ${data.length} resultados`
      : 'Nenhum alerta encontrado';
    renderPagination(totalPages);
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
    const values = [
      rows.length,
      rows.filter(row => row.status === 'success').length,
      rows.filter(row => row.status === 'failed').length,
      rows.filter(row => row.status === 'running').length
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
    const values = rows.map(row => [statusMeta[row.status]?.[0] || row.status, priorityLabel[row.priority] || row.priority, row.client, row.server, row.code, row.location, row.type, row.error, row.time, row.plan, row.size]);

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

  const loadRows = async () => {
    const alerts = await fetchJson('alertas.php');
    state.rows = deduplicateRows((Array.isArray(alerts) ? alerts : []).map(mapAlertRow));
    populateSelects();
    updateKpis();
    renderRows();
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
  document.querySelectorAll('th[data-sort]').forEach(header => header.addEventListener('click', () => {
    const key = header.dataset.sort;
    sortDir = sortKey === key && sortDir === 'asc' ? 'desc' : 'asc';
    sortKey = key;
    page = 1;
    renderRows();
  }));
  document.getElementById('exportCsv')?.addEventListener('click', () => exportData('csv'));
  document.getElementById('exportExcel')?.addEventListener('click', () => exportData('excel'));
  document.getElementById('exportPdf')?.addEventListener('click', () => exportData('pdf'));
  document.getElementById('profileButton')?.addEventListener('click', () => notify('Perfil indisponível neste painel.'));
  document.getElementById('closeAlertDetails')?.addEventListener('click', () => detailsDialog?.close());
  detailsDialog?.addEventListener('click', event => {
    const rect = detailsDialog.getBoundingClientRect();
    const inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
    if (!inside) detailsDialog.close();
  });

  setRailOpen(desktopRail.matches ? localStorage.getItem('nyxcloud-rail-collapsed') !== '1' : false);

  loadRows().catch(error => notify(error.message || 'Falha ao carregar alertas.'));
  setInterval(() => loadRows().catch(error => notify(error.message || 'Falha ao atualizar alertas.')), 30000);
})();
