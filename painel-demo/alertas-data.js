(function () {
  'use strict';

  const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim() || '--';
  const scopeKey = (...values) => values.map(value => clean(value).toLocaleLowerCase('pt-BR')).join('||');
  const readSet = key => {
    try {
      const values = JSON.parse(localStorage.getItem(key) || '[]');
      return new Set(Array.isArray(values) ? values.filter(Boolean) : []);
    } catch {
      return new Set();
    }
  };

  const fields = alert => ({
    client: alert?.client || alert?.cliente || '',
    server: alert?.server || alert?.maquina || alert?.origem || '',
    plan: alert?.plan || alert?.plano || alert?.recurso || '',
    code: alert?.code || alert?.codigo || alert?.rawCode || alert?.raw?.code || alert?.tipo || alert?.raw?.type || '',
    date: alert?.data || alert?.sortTime || '',
    time: alert?.hora || '',
    error: alert?.error || alert?.causa || alert?.mensagem || ''
  });

  const deduplicate = alerts => {
    const seen = new Set();
    return (Array.isArray(alerts) ? alerts : []).filter(alert => {
      const raw = alert?.raw || {};
      const value = fields(alert);
      const sourceId = raw.id || raw.uuid || raw.alertId || alert?.sourceId || '';
      const fallback = [value.client, value.server, value.code, value.date, value.time, value.plan, value.error].join('|');
      const key = [sourceId || fallback, value.code, value.date, value.time, value.plan].join('|').toLocaleLowerCase('pt-BR');
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  };

  const isHiddenByVisibility = alert => {
    const value = fields(alert);
    return readSet('nyxcloud-hidden-alert-clients-v2').has(scopeKey(value.client))
      || readSet('nyxcloud-hidden-alert-devices-v2').has(scopeKey(value.client, value.server))
      || readSet('nyxcloud-hidden-alert-plans-v2').has(scopeKey(value.client, value.server, value.plan));
  };

  window.NyxCloudAlertData = { deduplicate, isHiddenByVisibility, scopeKey };
}());
