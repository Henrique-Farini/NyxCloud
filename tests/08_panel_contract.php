<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$html = (string) file_get_contents($root . '/painel-demo/index.php');
$script = (string) file_get_contents($root . '/painel-demo/app.js');
$styles = (string) file_get_contents($root . '/painel-demo/styles.css');
$alertsHtml = (string) file_get_contents($root . '/painel-demo/alertas.php');
$customerService = (string) file_get_contents($root . '/app/Services/CustomerService.php');
$expected = ['overview', 'executions', 'alerts', 'storage', 'summary', 'windows', 'clients', 'accounts', 'integrations', 'infrastructure', 'analytics', 'audit'];

preg_match_all('/data-section="([a-z-]+)"/', $html, $navMatches);
preg_match_all('/data-panel-section="([a-z-]+)"/', $html, $panelMatches);
preg_match_all('/\sid="([^"]+)"/', $html, $idMatches);
$navigation = array_values(array_unique($navMatches[1] ?? []));
$panels = array_values(array_unique($panelMatches[1] ?? []));
sort($expected);
sort($navigation);
sort($panels);

$checks = [
    'navigation' => $navigation === $expected,
    'panels' => $panels === $expected,
    'unique_ids' => count($idMatches[1] ?? []) === count(array_unique($idMatches[1] ?? [])),
    'route_guard' => str_contains($html, "require_once __DIR__ . '/_auth_guard.php'"),
    'lazy_loading' => str_contains($script, 'const sectionLoaders = {') && !str_contains($script, 'const loadData ='),
    'sidebar_toggle' => str_contains($script, 'const setRailOpen =') && str_contains($styles, 'body.rail-collapsed .rail'),
    'offline_chart_fallback' => str_contains($script, "if (!window.ApexCharts)") && str_contains($styles, '.fallback-bars'),
    'no_old_placeholder' => !str_contains($html, 'Aguardando dados reais da Acronis.'),
    'overview_is_not_reused' => !str_contains($html, 'data-panel-extra-sections="overview"'),
    'execution_controls' => str_contains($html, 'id="executionSearch"') && str_contains($html, 'id="executionStatus"') && str_contains($html, 'id="executionSort"') && str_contains($script, 'state.executionStatus'),
    'client_directory' => str_contains($html, 'id="clientsDirectory"') && str_contains($html, 'id="clientsSearch"') && str_contains($html, 'id="clientsSort"') && str_contains($script, 'buildClientDirectory') && str_contains($script, 'clients: [loadMe, loadDashboard, loadCustomers, loadDevices]') && !str_contains($script, 'items.slice(0, 12).forEach'),
    'analytics_hierarchy' => substr_count($html, 'class="analytics-metric-card"') === 3 && str_contains($html, 'últimos 7 dias') && str_contains($script, 'setAnalyticsDelta'),
    'shared_navigation_labels' => str_contains($html, 'Atividade recente') && str_contains($html, 'Janelas de execução') && str_contains($html, 'Execuções por dispositivo') && str_contains($html, 'Auditoria administrativa') && str_contains($alertsHtml, 'index.php#executions') && str_contains($alertsHtml, 'index.php#infrastructure') && str_contains($alertsHtml, 'index.php#analytics') && str_contains($alertsHtml, 'index.php#audit'),
    'all_clients_requested' => str_contains($customerService, "array_merge(['limit' => 1000], \$filters)") && str_contains($customerService, "['paging']['cursors']['after']") && str_contains($customerService, 'private function tenantItems'),
    'overview_dense_layout' => str_contains($html, 'overview-priority-surface') && str_contains($html, 'overview-coverage-surface') && str_contains($script, 'renderOverviewInsights') && str_contains($styles, '.overview-priority-list') && str_contains($styles, '.overview-coverage-list'),
    'history_belongs_to_analytics' => str_contains($html, 'class="surface history-surface" data-panel-section="analytics"'),
];
$success = !in_array(false, $checks, true);

echo json_encode([
    'success' => $success,
    'checks' => $checks,
    'sections' => $expected,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($success ? 0 : 1);
