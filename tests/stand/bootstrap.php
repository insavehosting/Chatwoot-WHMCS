<?php
// Test data and request plumbing shared by the router (browser runs) and
// render.php (CLI runs). Nothing here is used by the module itself.

require_once __DIR__ . '/stubs.php';

const CW_STAND_SECRET = 'stand-identity-secret';
const CW_STAND_TOKEN = 'standWebsiteToken';

function cw_stand_boot(string $scenario, string $chatwootUrl): void
{
    $settings = [
        'access'                    => '',
        'chatwoot_bubble'           => 'Expanded Bubble',
        'chatwoot_dark'             => 'on',
        'chatwoot_enable'           => 'on',
        'chatwoot_enableonadmin'    => '',
        'chatwoot_jscode'           => '<script>/* 2.0.0 leftover */</script>',
        'chatwoot_lang'             => 'on',
        'chatwoot_launcherTitle'    => '',
        'chatwoot_position'         => 'right',
        'chatwoot_setlabel'         => 'visitor',
        'chatwoot_setlabelloggedin' => 'client',
        'chatwoot_token'            => CW_STAND_TOKEN,
        'chatwoot_url'              => $chatwootUrl,
        'chatwoot_verhash'          => CW_STAND_SECRET,
        'version'                   => '2.0.4',
    ];
    CwStand::$db['tbladdonmodules'] = [];
    foreach ($settings as $setting => $value) {
        CwStand::$db['tbladdonmodules'][] = ['module' => 'chatwoot', 'setting' => $setting, 'value' => $value];
    }
    // Another module's row with the same setting name must survive the upgrade.
    CwStand::$db['tbladdonmodules'][] = ['module' => 'other_module', 'setting' => 'chatwoot_jscode', 'value' => 'keep'];

    CwStand::$db['tblconfiguration'] = [
        ['setting' => 'SystemURL', 'value' => 'https://billing.example.test/'],
        ['setting' => 'Version', 'value' => '8.13.1-release.1'],
    ];
    CwStand::$db['tblusers_clients'] = [
        ['auth_user_id' => 10, 'client_id' => 100, 'owner' => 1],
        ['auth_user_id' => 11, 'client_id' => 100, 'owner' => 0],
        ['auth_user_id' => 30, 'client_id' => 300, 'owner' => 1],
        ['auth_user_id' => 41, 'client_id' => 400, 'owner' => 1],
    ];
    CwStand::$db['tblhosting'] = [
        ['id' => 500, 'userid' => 100, 'packageid' => 7, 'domain' => 'acme.example', 'domainstatus' => 'Active'],
        ['id' => 501, 'userid' => 300, 'packageid' => 7, 'domain' => 'other.example', 'domainstatus' => 'Suspended'],
    ];
    CwStand::$db['tblproducts'] = [
        ['id' => 7, 'name' => 'Starter Hosting'],
    ];
    CwStand::$db['tblinvoices'] = [
        ['id' => 700, 'userid' => 100, 'status' => 'Unpaid'],
        ['id' => 701, 'userid' => 300, 'status' => 'Paid'],
    ];
    CwStand::$db['tbltickets'] = [
        ['id' => 1, 'tid' => 'ABC123', 'userid' => 100],
        ['id' => 2, 'tid' => 'XYZ999', 'userid' => 300],
    ];

    CwStand::$clients = [
        100 => cw_stand_client([
            'email' => 'owner@example.test', 'fullname' => 'Olga Owner', 'companyname' => 'Acme',
        ]),
        300 => cw_stand_client([
            // WHMCS keeps client fields HTML-encoded in the database.
            'email' => 'tricky@example.test', 'fullname' => '&lt;/script&gt; Test', 'companyname' => 'O&#039;Brien\\',
            'city' => 'Back\\slash',
        ]),
    ];

    $users = [
        10 => new CwStandUser(10, 'owner@example.test', 'Olga', 'Owner', [100]),
        11 => new CwStandUser(11, 'invited@example.test', 'Ivan', 'Invited', []),
        12 => new CwStandUser(12, 'nobody@example.test', 'Nora', 'Noclient', []),
        30 => new CwStandUser(30, 'tricky@example.test', '</script>', 'O&#039;Brien\\', [300]),
    ];
    $map = [
        'guest'    => [null, null],
        'owner'    => [10, 100],
        'invited'  => [11, 100],
        'noclient' => [12, null],
        'tricky'   => [30, 300],
    ];
    [$userId, $clientId] = $map[$scenario] ?? [null, null];
    CwStand::$user = $userId ? $users[$userId] : null;
    CwStand::$client = $clientId ? new CwStandClient($clientId) : null;
}

function cw_stand_client(array $client): array
{
    return [
        'result' => 'success',
        'client' => $client + [
            'phonenumberformatted' => '+380.661234567',
            'countryname'          => 'Ukraine',
            'city'                 => 'Kyiv',
            'fullstate'            => 'Kyiv',
            'postcode'             => '01001',
            'language'             => 'russian',
            'email_verified'       => true,
        ],
        'stats' => [
            'numactivetickets'       => 2,
            'creditbalance'          => '$5.00 USD',
            'income'                 => '$120.00 USD',
            'numunpaidinvoices'      => 1,
            'unpaidinvoicesamount'   => '$10.00 USD',
            'numoverdueinvoices'     => 0,
            'overdueinvoicesbalance' => '$0.00 USD',
            'isAffiliate'            => false,
        ],
        'email_verified' => true,
    ];
}

/** Template variables ClientAreaFooterOutput would get for this request. */
function cw_stand_vars(string $path, array $get, array $cookies): array
{
    $vars = ['language' => $cookies['stand_lang'] ?? 'english', 'templatefile' => 'clientareahome'];
    $withIds = empty($cookies['stand_novars']);
    if ($path === '/clientarea.php' && ($get['action'] ?? '') === 'productdetails') {
        $vars['templatefile'] = 'clientareaproductdetails';
        if ($withIds) {
            $vars['id'] = $get['id'] ?? null;
        }
    } elseif ($path === '/viewinvoice.php') {
        $vars['templatefile'] = 'viewinvoice';
        if ($withIds) {
            $vars['invoiceid'] = $get['id'] ?? null;
        }
    } elseif ($path === '/viewticket.php') {
        $vars['templatefile'] = 'viewticket';
        if ($withIds) {
            $vars['tid'] = $get['tid'] ?? null;
        }
    }
    return $vars;
}
