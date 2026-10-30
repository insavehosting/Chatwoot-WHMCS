<?php
// current_page cases: php tests/stand/current_page_test.php
require __DIR__ . '/bootstrap.php';
cw_stand_boot('guest', 'x');
require dirname(__DIR__, 2) . '/chatwoot/hooks.php';
$cases = [
 '/index.php?rp=/password/reset/redeem/9f8e7d6c5b4a39281726354453627180aabbccdd' => 'https://billing.example.test/password/reset/redeem',
 '/password/reset/redeem/abc123def456ghi789jkl012' => 'https://billing.example.test/password/reset/redeem',
 '/password/reset/redeem/short' => 'https://billing.example.test/password/reset/redeem',
 '/password/reset' => 'https://billing.example.test/password/reset',
 '/index.php?rp=/user/verify/AbCdEf0123456789XyZ0123' => 'https://billing.example.test/user/verify',
 '/index.php?rp=/invite/xyz' => 'https://billing.example.test/invite',
 '/clientarea.php?action=productdetails&id=500' => 'https://billing.example.test/clientarea.php',
 '/viewticket.php?tid=ABC123&c=Secret99' => 'https://billing.example.test/viewticket.php',
 '/store/web-hosting/starter' => 'https://billing.example.test/store/web-hosting/starter',
 '/knowledgebase/12/how-to-install-wordpress.html' => 'https://billing.example.test/knowledgebase/12/how-to-install-wordpress.html',
 '/index.php/store/vps' => 'https://billing.example.test/store/vps',
 '/download/dl/a1b2c3d4e5f6a7b8c9d0e1f2' => 'https://billing.example.test/download/dl',
 '/' => 'https://billing.example.test/',
 '/index.php' => 'https://billing.example.test/index.php',
];
$fail = 0;
foreach ($cases as $uri => $want) {
  $_SERVER['REQUEST_URI'] = $uri;
  $got = cw_current_page();
  $ok = $got === $want; $fail += !$ok;
  printf("%s %s -> %s%s\n", $ok ? 'PASS' : 'FAIL', $uri, $got, $ok ? '' : " (want $want)");
}
CwStand::$db['tblconfiguration'][0]['value'] = 'https://example.com/billing/';
$_SERVER['REQUEST_URI'] = '/billing/clientarea.php?x=1';
$got = cw_current_page(); $ok = $got === 'https://example.com/billing/clientarea.php'; $fail += !$ok;
printf("%s subdir -> %s\n", $ok ? 'PASS' : 'FAIL', $got);
exit($fail ? 1 : 0);
