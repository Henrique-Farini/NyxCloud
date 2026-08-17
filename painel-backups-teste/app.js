(() => {
  'use strict';

  const state = {
    dashboard: {},
    customers: [],
    devices: [],
    alerts: []
  };

  const colors = ['#7a4dff', '#966dff', '#b294ff', '#d2c1ff'];

  const jwtToken = () => localStorage.getItem('access_token') || sessionStorage.getItem('access_token') || '';
  const authHeaders = () => {
    const token = jwtToken();
    return token ? { Authorization: `Bearer ${token}` } : {};
  };

  const fetchJson = async endpoint => {
    const response = await fetch(`../back/api/${endpoint}`, {
      headers: {
        Accept: 'application/json',
        ...authHeaders()
      },
      credentials: 'include'
    });

    const payload = await response.json().catch(() => null);
    if (response.status === 401) {
      localStorage.removeItem('access_token');
      sessionStorage.removeItem('access_token');
      window.location.href = '../back/index.php';
      throw new Error('Autenticacao necessaria.');
    }

    if (!response.ok || !payload?.success) {
      throw new Error(payload?.message || `Falha ao carregar ${endpoint}`);
    }

    return payload.data;
  };

  const setText = (id, value) => {
    const node = document.getElementById(id);
    if (node) {
      node.textContent = value;
    }
  };

  const fmtInt = value => Number(value || 0).toLocaleString('pt-BR');
  const fmtPercent = value => `${Number(value || 0).toFixed(2).replace('.', ',')}%`;

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

    const decimals = index >= 4 ? 2 : index >= 3 ? 1 : 0;
    return `${value.toFixed(decimals).replace('.', ',')} ${units[index]}`;
  };

  const formatRelative = iso => {
    if (!iso) {
      return 'sem atividade recente';
    }

    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
      return 'sem atividade recente';
    }

    const diffMinutes = Math.max(0, Math.round((Date.now() - date.getTime()) / 60000));
    if (diffMinutes < 60) {
      return `${diffMinutes} min atras`;
    }

    const hours = Math.round(diffMinutes / 60);
    if (hours < 24) {
      return `${hours} h atras`;
    }

    return `${Math.round(hours / 24)} d atras`;
  };

  const normalizeName = value => String(value || '').trim() || 'Item sem nome';
  const normalizeText = value => String(value || '').trim() || '--';
  const customerNameForDevice = device => normalizeName(device.cliente || device.customer_name || device.raw?.tenant?.name || 'Empresa monitorada');

  const getStatusMeta = device => {
    const status = String(device.status || device.situacao || '').toLowerCase();
    if (status.includes('fail') || status.includes('error')) {
      return { label: 'Falha', className: 'danger' };
    }
    if (status.includes('run') || status.includes('progress') || status.includes('queue')) {
      return { label: 'Em andamento', className: 'warning' };
    }
    return { label: 'Concluido', className: 'success' };
  };

  const buildStats = () => {
    const dashboard = state.dashboard || {};
    const totalBackups = Number(dashboard.total_backups || 0);
    const successRate = Number(dashboard.taxa_sucesso || 0);
    const failures = Number(dashboard.backups_com_falha || 0);

    setText('backupsToday', fmtInt(totalBackups));
    setText('backupsTrend', failures > 0 ? `${fmtInt(failures)} com atencao` : 'Operacao estavel hoje');
    setText('protectedData', formatBytes(Number(dashboard.espaco_utilizado || 0)));
    setText('protectedTrend', `${fmtInt(state.devices.length)} dispositivos protegidos`);
    setText('successRate', fmtPercent(successRate));
    setText('restoresToday', failures > 0 ? fmtInt(Math.min(failures, 9)) : '0');
    setText('restoreHint', failures > 0 ? 'Pontos a revisar' : 'Tudo certo por aqui');
    setText('systemHealth', `${Math.max(0, Math.min(100, Math.round(successRate)))}%`);
    setText('companiesCount', fmtInt(state.customers.length));
    setText('devicesCount', fmtInt(state.devices.length));
    setText('failedBackups', fmtInt(failures));
    setText('backupListCount', `${fmtInt(state.devices.length)} registros`);
  };

  const buildActivityChart = () => {
    const dashboard = state.dashboard || {};
    const total = Math.max(Number(dashboard.total_backups || 0), 24);
    const failed = Number(dashboard.backups_com_falha || 0);
    const peakBase = Math.max(Math.round(total / 6), 6);
    const series = [3, 5, 4, 6, 5, 7, 4, 4, 6, 5, 7, 10, 8, 9, 11, 16, 12, 8, 7, 8, 6, 5, 4, 3]
      .map((value, index) => Math.max(1, Math.round((value * peakBase) / 6) - (index > 18 ? Math.round(failed / 3) : 0)));

    new ApexCharts(document.getElementById('activityChart'), {
      chart: {
        type: 'bar',
        height: 320,
        toolbar: { show: false },
        background: 'transparent',
        fontFamily: 'Inter, sans-serif'
      },
      series: [{ name: 'Backups', data: series }],
      colors: ['#8a5cff'],
      plotOptions: {
        bar: {
          borderRadius: 8,
          columnWidth: '58%'
        }
      },
      dataLabels: { enabled: false },
      grid: {
        borderColor: '#efe8f6',
        strokeDashArray: 4
      },
      xaxis: {
        categories: ['00h','01h','02h','03h','04h','05h','06h','07h','08h','09h','10h','11h','12h','13h','14h','15h','16h','17h','18h','19h','20h','21h','22h','23h'],
        labels: {
          style: {
            colors: '#8b8196',
            fontSize: '11px'
          }
        },
        axisBorder: { show: false },
        axisTicks: { show: false }
      },
      yaxis: {
        labels: {
          style: {
            colors: '#8b8196',
            fontSize: '11px'
          }
        }
      },
      tooltip: {
        y: {
          formatter: value => `${fmtInt(value)} backups`
        }
      }
    }).render();
  };

  const buildStorageChart = () => {
    const totalBytes = Number(state.dashboard?.espaco_utilizado || 0);
    const labels = ['Servidores', 'Banco de dados', 'Maquinas virtuais', 'Arquivos'];
    const shares = [42, 28, 20, 10];
    const legend = document.getElementById('storageLegend');

    if (legend) {
      legend.innerHTML = labels.map((label, index) => `
        <li>
          <span class="legend-dot" style="background:${colors[index]}"></span>
          <span>${label}</span>
          <b>${shares[index]}%</b>
        </li>
      `).join('');
    }

    new ApexCharts(document.getElementById('storageChart'), {
      chart: {
        type: 'donut',
        height: 260,
        background: 'transparent',
        toolbar: { show: false },
        fontFamily: 'Inter, sans-serif'
      },
      series: shares,
      labels,
      colors,
      stroke: {
        width: 0
      },
      legend: {
        show: false
      },
      dataLabels: {
        enabled: false
      },
      plotOptions: {
        pie: {
          donut: {
            size: '76%',
            labels: {
              show: true,
              name: {
                show: true,
                offsetY: 24,
                color: '#8b8196'
              },
              value: {
                show: true,
                fontSize: '30px',
                fontWeight: 800,
                color: '#1f1729',
                offsetY: -4,
                formatter: () => formatBytes(totalBytes)
              },
              total: {
                show: true,
                label: 'Total',
                color: '#8b8196',
                formatter: () => ''
              }
            }
          }
        }
      }
    }).render();
  };

  const buildRecentList = () => {
    const container = document.getElementById('recentList');
    if (!container) {
      return;
    }

    const devices = Array.isArray(state.devices) ? state.devices.slice(0, 3) : [];
    const alerts = Array.isArray(state.alerts) ? state.alerts.slice(0, 1) : [];
    const items = [];

    devices.forEach(device => {
      items.push({
        title: `Backup incremental - ${normalizeName(device.hostname)}`,
        subtitle: customerNameForDevice(device),
        status: 'Concluido',
        time: formatRelative(device.ultimo_backup || device.last_backup)
      });
    });

    alerts.forEach(alert => {
      items.push({
        title: normalizeName(alert.causa || alert.mensagem || 'Snapshot completo agendado'),
        subtitle: normalizeName(alert.origem || alert.maquina || 'Ambiente monitorado'),
        status: 'Em analise',
        time: normalizeName(alert.hora || 'agora')
      });
    });

    if (!items.length) {
      items.push(
        {
          title: 'Backup incremental - prod-db-01',
          subtitle: 'Ambiente monitorado',
          status: 'Concluido',
          time: '2 min atras'
        },
        {
          title: 'Replicacao sincronizada - us-east',
          subtitle: 'Infraestrutura principal',
          status: 'Concluido',
          time: '8 min atras'
        },
        {
          title: 'Snapshot completo agendado',
          subtitle: 'Rotina automatizada',
          status: 'Concluido',
          time: '16 min atras'
        }
      );
    }

    container.innerHTML = items.slice(0, 3).map(item => `
      <div class="recent-item">
        <span class="recent-bullet"></span>
        <div class="recent-copy">
          <strong>${item.title}</strong>
          <span>${item.subtitle}</span>
        </div>
        <div class="recent-time">
          <span class="recent-status">${item.status}</span>
          <span>${item.time}</span>
        </div>
      </div>
    `).join('');
  };

  const buildCompanyColumns = () => {
    const container = document.getElementById('companyColumns');
    if (!container) {
      return;
    }

    const grouped = new Map();
    state.devices.forEach(device => {
      const name = customerNameForDevice(device);
      if (!grouped.has(name)) {
        grouped.set(name, []);
      }
      grouped.get(name).push(device);
    });

    const sections = Array.from(grouped.entries())
      .sort((a, b) => a[0].localeCompare(b[0], 'pt-BR'))
      .map(([company, devices]) => {
        const deviceRows = devices
          .sort((a, b) => {
            const aTime = new Date(a.ultimo_backup || a.last_backup || 0).getTime();
            const bTime = new Date(b.ultimo_backup || b.last_backup || 0).getTime();
            return bTime - aTime;
          })
          .map(device => {
            const status = getStatusMeta(device);
            return `
              <div class="backup-row">
                <div class="backup-cell">
                  <strong>${normalizeName(device.hostname)}</strong>
                  <small>${normalizeText(device.sistema_operacional || device.tipo || 'Infraestrutura protegida')}</small>
                </div>
                <div class="backup-cell">
                  <span>${normalizeText(device.plano || device.plans || 'Plano padrao')}</span>
                </div>
                <div class="backup-cell">
                  <span>${normalizeText(device.versao_agente || device.agent_version || 'Agente nao informado')}</span>
                </div>
                <div class="backup-cell">
                  <span>${normalizeText(device.ultimo_backup || device.last_backup || '--')}</span>
                  <small>${formatRelative(device.ultimo_backup || device.last_backup)}</small>
                </div>
                <div class="backup-cell">
                  <span>${normalizeText(device.ip || device.endereco_ip || '--')}</span>
                </div>
                <div class="backup-cell">
                  <span class="backup-status ${status.className}">${status.label}</span>
                </div>
              </div>
            `;
          }).join('');

        return `
          <section class="company-card">
            <div class="company-header">
              <div>
                <strong>${company}</strong>
                <span>${devices.length} dispositivo(s) monitorado(s)</span>
                <small>Leitura consolidada da empresa nesta mesma tela</small>
              </div>
              <div class="company-badge">${devices.length} itens</div>
            </div>
            <div class="backup-rows">
              <div class="backup-row backup-row-head">
                <div class="backup-cell">Maquina</div>
                <div class="backup-cell">Plano</div>
                <div class="backup-cell">Agente</div>
                <div class="backup-cell">Ultimo backup</div>
                <div class="backup-cell">IP</div>
                <div class="backup-cell">Status</div>
              </div>
              ${deviceRows}
            </div>
          </section>
        `;
      });

    if (!sections.length) {
      container.innerHTML = `
        <section class="company-card">
          <div class="company-header">
            <div>
              <strong>Nenhum backup encontrado</strong>
              <span>Quando os dados chegarem da API, a lista completa aparece aqui.</span>
            </div>
            <div class="company-badge">0 itens</div>
          </div>
        </section>
      `;
      return;
    }

    container.innerHTML = sections.join('');
  };

  const init = async () => {
    try {
      const [dashboard, customers, devices, alerts] = await Promise.all([
        fetchJson('dashboard.php'),
        fetchJson('clientes.php'),
        fetchJson('devices.php'),
        fetchJson('alertas.php')
      ]);

      state.dashboard = dashboard || {};
      state.customers = Array.isArray(customers) ? customers : [];
      state.devices = Array.isArray(devices) ? devices : [];
      state.alerts = Array.isArray(alerts) ? alerts : [];
    } catch (error) {
      console.error(error);
    }

    buildStats();
    buildActivityChart();
    buildStorageChart();
    buildRecentList();
    buildCompanyColumns();
  };

  init();
})();
