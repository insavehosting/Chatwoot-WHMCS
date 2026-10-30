<?php
// Test data and request plumbing shared by the router (browser runs) and
// render.php (CLI runs). Nothing here is used by the module itself.

require_once __DIR__ . '/stubs.php';

const CW_STAND_SECRET = 'stand-identity-secret';
const CW_STAND_TOKEN = 'standWebsiteToken';

function cw_stand_boot(string $scenario, string $chatwootUrl): void
{
    $settings = [
        'access'                    => '1',
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
        ['id' => 500, 'userid' => 100, 'packageid' => 7, 'domain' => 'acme.example', 'domainstatus' => 'Active',
         'nextduedate' => '2026-11-15', 'amount' => '4.99', 'billingcycle' => 'Monthly'],
        ['id' => 501, 'userid' => 300, 'packageid' => 7, 'domain' => 'other.example', 'domainstatus' => 'Suspended',
         'nextduedate' => '2026-10-01', 'amount' => '4.99', 'billingcycle' => 'Monthly'],
        ['id' => 502, 'userid' => 100, 'packageid' => 8, 'domain' => 'old.example', 'domainstatus' => 'Terminated',
         'nextduedate' => '0000-00-00', 'amount' => '9.99', 'billingcycle' => 'Annually'],
    ];
    CwStand::$db['tblproducts'] = [
        ['id' => 7, 'name' => 'Starter Hosting'],
        ['id' => 8, 'name' => 'Legacy Plan'],
    ];
    CwStand::$db['tblinvoices'] = [
        ['id' => 700, 'userid' => 100, 'invoicenum' => '', 'status' => 'Unpaid', 'duedate' => '2026-01-10', 'total' => '10.00'],
        ['id' => 701, 'userid' => 300, 'invoicenum' => '', 'status' => 'Paid', 'duedate' => '2026-01-10', 'total' => '4.99'],
        ['id' => 702, 'userid' => 100, 'invoicenum' => 'INV-702', 'status' => 'Unpaid', 'duedate' => '2099-01-01', 'total' => '20.00'],
    ];
    CwStand::$db['tblaccounts'] = [
        ['id' => 1, 'invoiceid' => 702, 'amountin' => '5.00', 'amountout' => '0.00'],
    ];
    CwStand::$db['tbltickets'] = [
        ['id' => 1, 'tid' => 'ABC123', 'userid' => 100, 'title' => 'Cannot log in', 'status' => 'Open',
         'lastreply' => '2026-10-29 10:00:00', 'merged_ticket_id' => 0],
        ['id' => 2, 'tid' => 'XYZ999', 'userid' => 300, 'title' => 'Other client', 'status' => 'Open',
         'lastreply' => '2026-10-29 10:00:00', 'merged_ticket_id' => 0],
        ['id' => 3, 'tid' => 'OLD111', 'userid' => 100, 'title' => 'Closed one', 'status' => 'Closed',
         'lastreply' => '2026-01-01 10:00:00', 'merged_ticket_id' => 0],
        ['id' => 4, 'tid' => 'MRG222', 'userid' => 100, 'title' => 'Merged one', 'status' => 'Open',
         'lastreply' => '2026-10-28 10:00:00', 'merged_ticket_id' => 1],
    ];
    CwStand::$db['tblticketstatuses'] = [
        ['title' => 'Open', 'showactive' => 1],
        ['title' => 'Answered', 'showactive' => 1],
        ['title' => 'Closed', 'showactive' => 0],
    ];
    CwStand::$db['tblcurrencies'] = [
        ['id' => 1, 'code' => 'USD', 'prefix' => '$', 'suffix' => ' USD', 'default' => 1],
    ];
    // Admins: 1 has a role with access to the addon, 2 does not, 3 is disabled.
    CwStand::$db['tbladmins'] = [
        ['id' => 1, 'roleid' => 1, 'disabled' => 0],
        ['id' => 2, 'roleid' => 2, 'disabled' => 0],
        ['id' => 3, 'roleid' => 1, 'disabled' => 1],
    ];
    CwStand::$db['tblusers'] = [
        ['id' => 10, 'first_name' => 'Olga', 'last_name' => 'Owner', 'email' => 'owner@example.test'],
        ['id' => 11, 'first_name' => 'Ivan', 'last_name' => 'Invited', 'email' => 'invited@example.test'],
        ['id' => 12, 'first_name' => 'Nora', 'last_name' => 'Noclient', 'email' => 'nobody@example.test'],
        ['id' => 30, 'first_name' => '</script>', 'last_name' => 'O&#039;Brien\\', 'email' => 'tricky@example.test'],
    ];
    CwStand::$db['tblclients'] = [
        ['id' => 100, 'firstname' => 'Olga', 'lastname' => 'Owner', 'companyname' => 'Acme', 'email' => 'owner@example.test',
         'status' => 'Active', 'datecreated' => '2024-03-01', 'credit' => '5.00', 'currency' => 1],
        ['id' => 300, 'firstname' => '&lt;/script&gt;', 'lastname' => 'Test', 'companyname' => 'O&#039;Brien\\',
         'email' => 'tricky@example.test', 'status' => 'Active', 'datecreated' => '2025-01-01', 'credit' => '0.00', 'currency' => 1],
        // an account whose email no user logs in with
        ['id' => 500, 'firstname' => 'Accounts', 'lastname' => 'Dept', 'companyname' => 'Billing Only', 'email' => 'accounts@example.test',
         'status' => 'Inactive', 'datecreated' => '2023-05-05', 'credit' => '0.00', 'currency' => 1],
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
