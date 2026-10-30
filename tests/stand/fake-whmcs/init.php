<?php
// Stands in for WHMCS's init.php when tools/export-identifier-map.php runs on the stand.
require __DIR__ . '/../bootstrap.php';
cw_stand_boot('guest', 'https://chat.example.test/');
