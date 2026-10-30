<?php
// Router for `php -S`: serves the fake SDK and renders a client area page
// with the module's ClientAreaFooterOutput hook. Scenario, language and
// "no template ids" come from cookies the test runner sets.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/packs/js/sdk.js') {
    header('Content-Type: application/javascript');
    readfile(__DIR__ . '/fake-sdk.js');
    return true;
}
if ($path === '/favicon.ico') {
    http_response_code(404);
    return true;
}

session_start();
require __DIR__ . '/bootstrap.php';
CwStand::$counter = sys_get_temp_dir() . '/cw-stand-api-' . (getenv('CW_STAND_ID') ?: 'default');

if ($path === '/stand/state') {
    header('Content-Type: application/json');
    echo json_encode(['apiCalls' => is_file(CwStand::$counter) ? (int) file_get_contents(CwStand::$counter) : 0]);
    return true;
}

cw_stand_boot($_COOKIE['stand_scenario'] ?? 'guest', 'http://' . $_SERVER['HTTP_HOST'] . '/');
// An admin logged in to WHMCS in the same browser, and the module setting for it.
if (!empty($_COOKIE['stand_admin'])) {
    $_SESSION['adminid'] = 1;
} else {
    unset($_SESSION['adminid']);
}
if (!empty($_COOKIE['stand_enableonadmin'])) {
    foreach (CwStand::$db['tbladdonmodules'] as &$row) {
        if ($row['module'] === 'chatwoot' && $row['setting'] === 'chatwoot_enableonadmin') {
            $row['value'] = 'on';
        }
    }
    unset($row);
}
require getenv('CW_HOOKS') ?: dirname(__DIR__, 2) . '/modules/addons/chatwoot/hooks.php';

if ($path === '/logout') {
    foreach (CwStand::$hooks['UserLogout'] ?? [] as $hook) {
        $hook([]);
    }
    header('Location: /clientarea.php');
    return true;
}

$output = '';
foreach (CwStand::$hooks['ClientAreaFooterOutput'] ?? [] as $hook) {
    $output .= (string) $hook(cw_stand_vars($path, $_GET, $_COOKIE));
}
header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>stand</title></head><body><p>stand page</p>\n{$output}\n</body></html>";
return true;
