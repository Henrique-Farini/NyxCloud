<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$html = (string) file_get_contents($root . '/painel-demo/index.php');
$script = (string) file_get_contents($root . '/painel-demo/app.js');
$styles = (string) file_get_contents($root . '/painel-demo/styles.css');
$expected = ['overview', 'executions', 'alerts', 'storage', 'summary', 'windows', 'clients', 'accounts', 'infrastructure', 'analytics', 'audit'];

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
];
$success = !in_array(false, $checks, true);

echo json_encode([
    'success' => $success,
    'checks' => $checks,
    'sections' => $expected,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($success ? 0 : 1);
