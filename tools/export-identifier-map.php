<?php
/**
 * Prints the CSV that moves Chatwoot contacts from the 2.0.4 identifier
 * (HMAC-SHA256 of the WHMCS client id) to the user identifier of
 * 2.0.4-insave.2 (whmcs:u:<tblusers.id>): one row per client and its owner.
 *
 * Read-only: two SELECTs, nothing is written. Run it on the WHMCS server
 * with the PHP that runs the WHMCS cron, from a directory outside the web
 * root:
 *
 *   php export-identifier-map.php /path/to/whmcs > identifier-map.csv
 *
 * Then tools/migrate-identifiers.rb on the Chatwoot VM (see
 * docs/migrate-identifiers.md).
 */

use WHMCS\Database\Capsule;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = rtrim((string) ($argv[1] ?? ''), '/');
if ($root === '' || !is_file($root . '/init.php')) {
    fwrite(STDERR, "usage: php export-identifier-map.php <WHMCS root, the directory with init.php>\n");
    exit(2);
}
require $root . '/init.php';

$secret = (string) Capsule::table('tbladdonmodules')
    ->where('module', 'chatwoot')
    ->where('setting', 'chatwoot_verhash')
    ->value('value');
if ($secret === '') {
    fwrite(STDERR, "chatwoot_verhash is empty: 2.0.4 identifiers cannot be recomputed\n");
    exit(1);
}

$owners = Capsule::table('tblusers_clients')
    ->where('owner', 1)
    ->orderBy('client_id')
    ->get(['auth_user_id', 'client_id']);

echo "old_identifier,new_identifier\n";
$rows = 0;
foreach ($owners as $owner) {
    # 2.0.4: hash_hmac("sha256", $ClientID, $verification_hash)
    $old = hash_hmac('sha256', (string) $owner->client_id, $secret);
    echo $old, ',whmcs:u:', (int) $owner->auth_user_id, "\n";
    $rows++;
}

fwrite(STDERR, "{$rows} rows\n");
# Users without an account all got the HMAC of an empty client id in 2.0.4:
# one contact shared by several people. It has no owner and is not mapped.
fwrite(STDERR, 'not mapped (contact shared by users without an account): ' . hash_hmac('sha256', '', $secret) . "\n");
