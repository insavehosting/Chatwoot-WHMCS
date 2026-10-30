<?php
// CLI: php tests/stand/render.php <hooks.php> <scenario> [request-uri]
// Prints what ClientAreaFooterOutput returns for that scenario.

[, $hooksFile, $scenario] = $argv + [null, null, 'owner'];
$_SERVER['REQUEST_URI'] = $argv[3] ?? '/clientarea.php';
$_SERVER['HTTP_HOST'] = 'billing.example.test';
$_SERVER['HTTPS'] = 'on';
$_SESSION = [];

require __DIR__ . '/bootstrap.php';
cw_stand_boot($scenario, 'https://chat.example.test/');
require $hooksFile;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $get);
foreach (CwStand::$hooks['ClientAreaFooterOutput'] ?? [] as $hook) {
    echo (string) $hook(cw_stand_vars($path, $get, []));
}
