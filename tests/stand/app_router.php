<?php
// Router for `php -S` serving a throwaway WHMCS root with the module files
// (run.py builds it): /stand/admin?id=N logs an admin in, /stand/logout logs
// out; everything else is served from the root, so app.php runs as on WHMCS
// and requires the stand's init.php three levels up.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/stand/admin' || $path === '/stand/logout') {
    session_start();
    if ($path === '/stand/admin') {
        $_SESSION['adminid'] = (int) ($_GET['id'] ?? 0);
    } else {
        unset($_SESSION['adminid']);
    }
    header('Content-Type: text/plain');
    echo 'ok';
    return true;
}

return false;
