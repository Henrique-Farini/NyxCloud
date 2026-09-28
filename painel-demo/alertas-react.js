(() => {
  'use strict';

  const { createElement: h, Fragment, useEffect, useState } = React;
  const root = document.getElementById('alertDetailsReactRoot');
  const dialog = document.getElementById('alertDetailsDialog');
  if (!root || !dialog || !window.ReactDOM) return;

  const statusLabels = {
    failed: 'Falha',
    success: 'Resolvido',
    running: 'Em andamento',
    known: 'Conhecido',
    resolved: 'Resolvido'
  };
  const priorityLabels = { critical: 'Crítica', high: 'Alta', medium: 'Média', low: 'Baixa' };

  const DetailRow = ({ label, value }) => h('div', null, h('dt', null, label), h('dd', null, value || '--'));

  const AlertDetails = () => {
    const [row, setRow] = useState(null);
    const [profileRole, setProfileRole] = useState(document.body.dataset.profile || 'unknown');

    useEffect(() => {
      const openDetails = event => {
        setRow(event.detail);
        if (!dialog.open) dialog.showModal();
      };
      window.addEventListener('nyxcloud-alert-details', openDetails);
      return () => window.removeEventListener('nyxcloud-alert-details', openDetails);
    }, []);

    useEffect(() => {
      const ready = event => setProfileRole(event.detail?.role || 'leitura');
      window.addEventListener('nyxcloud-profile-ready', ready);
      return () => window.removeEventListener('nyxcloud-profile-ready', ready);
    }, []);

    useEffect(() => {
      window.lucide?.createIcons();
    }, [row]);

    if (!row) return null;
    const resolved = row.status === 'resolved';
    const known = row.status === 'known';
    const emit = name => window.dispatchEvent(new CustomEvent(`nyxcloud-alert-${name}`, { detail: row }));
    const close = () => dialog.close();
    const details = [
      ['Status', statusLabels[row.status] || row.status],
      ['Prioridade', priorityLabels[row.priority] || row.priority],
      ['Cliente', row.client],
      ['Dispositivo', row.server],
      ['IP / origem', row.location],
      ['Tipo', row.type],
      ['Plano', row.plan],
      ['Tamanho', row.size],
      ['Horário', row.time],
      ['Código', row.code],
      ['Motivo', row.error],
      ['Tratativa', resolved ? 'Confirmado como resolvido neste painel.' : (known ? 'Marcado como conhecido neste painel.' : 'Sem tratativa manual.')]
    ];

    const readOnly = profileRole === 'leitura' || profileRole === 'unknown';
    return h(Fragment, null,
      h('div', { className: 'alert-details-head' },
        h('div', null, h('span', null, 'DETALHES DO EVENTO'), h('h2', { id: 'alertDetailsTitle' }, `${row.client} - ${row.server}`), h('b', { className: `alert-detail-status status-${row.status}` }, statusLabels[row.status] || row.status)),
        h('button', { type: 'button', id: 'closeAlertDetails', 'aria-label': 'Fechar detalhes', onClick: close }, h('i', { 'data-lucide': 'x' }))
      ),
      h('dl', { id: 'alertDetailsContent' }, details.map(([label, value]) => h(DetailRow, { key: label, label, value }))),
      !readOnly && h('div', { className: 'alert-details-actions' },
        h('button', { type: 'button', id: 'resolveAlert', onClick: () => { emit('resolve'); close(); } }, h('i', { 'data-lucide': 'circle-check' }), h('span', null, resolved ? 'Remover resolvido' : 'Confirmar resolvido')),
        h('button', { type: 'button', id: 'markKnownAlert', onClick: () => emit('known') }, h('i', { 'data-lucide': 'shield-check' }), h('span', null, known ? 'Remover conhecido' : 'Marcar conhecido')),
        h('button', { type: 'button', id: 'hideAlertFromPanel', 'data-tone': 'danger', onClick: () => { emit('hide'); close(); } }, h('i', { 'data-lucide': 'eye-off' }), h('span', null, 'Ocultar do painel'))
      )
    );
  };

  ReactDOM.createRoot(root).render(h(AlertDetails));
  window.lucide?.createIcons();
})();
