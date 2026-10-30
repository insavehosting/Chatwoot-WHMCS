<?php

/***************************************************************************
// *                                                                       *
// * Chatwoot by inSave: the WHMCS card for Chatwoot Dashboard Apps.       *
// * Chatwoot loads this page in an iframe next to a conversation and      *
//   posts the conversation context to it; the page shows the WHMCS        *
//   client behind the contact. Read-only.                                 *
// *                                                                       *
// * Access: a WHMCS admin session in the same browser, for an admin whose *
//   role is ticked in the addon's Access Control. Chatwoot and WHMCS must *
//   be on the same site (chat.example.com and billing.example.com), or    *
//   the browser does not send the WHMCS session cookie into the iframe.   *
// *                                                                       *
// *************************************************************************/

require __DIR__ . '/../../../init.php';

use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

$cwapp_settings = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->pluck('value', 'setting')->all();
$cwapp_origin   = cwapp_origin($cwapp_settings);

# Only the Chatwoot dashboard may frame this page. No X-Frame-Options: it
# cannot name another origin, and frame-ancestors replaces it.
header_remove('X-Frame-Options');
header('Content-Security-Policy: frame-ancestors ' . ($cwapp_origin !== '' ? $cwapp_origin : "'none'"));
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$cwapp_admin = cwapp_admin($cwapp_settings);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$cwapp_admin) {
        http_response_code(403);
        echo cwapp_json(['error' => $cwapp_admin === false ? 'access' : 'login']);
        exit;
    }
    if (!hash_equals((string) generate_token('plain'), (string) ($_POST['token'] ?? ''))) {
        http_response_code(403);
        echo cwapp_json(['error' => 'token']);
        exit;
    }
    echo cwapp_json(cwapp_lookup((string) ($_POST['identifier'] ?? ''), (string) ($_POST['email'] ?? '')));
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo cwapp_page($cwapp_admin, $cwapp_origin);
exit;

# The Chatwoot origin allowed to frame the page and post the context: the
# "Dashboard App Origin" setting, or the origin of the Chatwoot URL.
function cwapp_origin(array $settings)
{
    $value = trim((string) ($settings['chatwoot_app_origin'] ?? ''));
    if ($value === '') {
        $value = (string) ($settings['chatwoot_url'] ?? '');
    }
    $parts = parse_url($value);
    if (empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return '';
    }
    return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
}

# null: no admin session; false: an admin whose role has no access to the
# addon; otherwise the admin row.
function cwapp_admin(array $settings)
{
    $adminId = (int) ($_SESSION['adminid'] ?? 0);
    if ($adminId <= 0) {
        return null;
    }
    $admin = Capsule::table('tbladmins')->where('id', $adminId)->first();
    if (!$admin || (int) $admin->disabled !== 0) {
        return null;
    }
    $roles = array_map('intval', array_filter(explode(',', (string) ($settings['access'] ?? ''))));
    return in_array((int) $admin->roleid, $roles, true) ? $admin : false;
}

function cwapp_admin_url($path)
{
    $folder = 'admin';
    if (class_exists('App') && method_exists('App', 'get_admin_folder_name')) {
        $folder = (string) App::get_admin_folder_name();
    } elseif (!empty($GLOBALS['customadminpath'])) {
        $folder = (string) $GLOBALS['customadminpath'];
    }
    return rtrim((string) Setting::getValue('SystemURL'), '/') . '/' . trim($folder, '/') . '/' . $path;
}

# The client behind a Chatwoot contact. A whmcs:u:<id> identifier was signed
# by the widget, so it is trusted; a contact without one (Telegram, Meta, a
# visitor) is matched by email and marked as not confirmed.
function cwapp_lookup($identifier, $email)
{
    $user  = null;
    $match = 'none';
    if (preg_match('/^whmcs:u:(\d+)$/', $identifier, $m)) {
        $user  = Capsule::table('tblusers')->where('id', (int) $m[1])->first();
        $match = $user ? 'identifier' : 'none';
    }
    $email = trim($email);
    if (!$user && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user  = Capsule::table('tblusers')->where('email', $email)->first();
        $match = $user ? 'email' : 'none';
    }

    $accounts = [];
    if ($user) {
        $links = Capsule::table('tblusers_clients')->where('auth_user_id', (int) $user->id)->get();
        foreach ($links as $link) {
            $accounts[(int) $link->client_id] = (bool) $link->owner;
        }
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        # an account email that no user logs in with
        foreach (Capsule::table('tblclients')->where('email', $email)->get() as $client) {
            $accounts[(int) $client->id] = null;
            $match = 'email';
        }
    }
    arsort($accounts);

    $out = [];
    foreach (array_slice($accounts, 0, 5, true) as $clientId => $isOwner) {
        $account = cwapp_account($clientId, $isOwner);
        if ($account) {
            $out[] = $account;
        }
    }

    return [
        'match'    => $out ? $match : 'none',
        'user'     => $user ? [
            'id'    => (int) $user->id,
            'name'  => trim($user->first_name . ' ' . $user->last_name),
            'email' => $user->email,
        ] : null,
        'accounts' => $out,
    ];
}

function cwapp_account($clientId, $isOwner)
{
    $client = Capsule::table('tblclients')->where('id', $clientId)->first();
    if (!$client) {
        return null;
    }
    $currency = Capsule::table('tblcurrencies')->where('id', (int) $client->currency)->first()
        ?: Capsule::table('tblcurrencies')->where('default', 1)->first();
    $money = function ($amount) use ($currency) {
        $amount = number_format((float) $amount, 2, '.', ',');
        return $currency ? $currency->prefix . $amount . $currency->suffix : $amount;
    };

    # services: active first, then by next due date
    $services = [];
    $hosting  = Capsule::table('tblhosting')->where('userid', $clientId)->get()->all();
    usort($hosting, function ($a, $b) {
        return [$a->domainstatus !== 'Active', (string) $a->nextduedate] <=> [$b->domainstatus !== 'Active', (string) $b->nextduedate];
    });
    $productIds = array_unique(array_map(function ($row) {
        return (int) $row->packageid;
    }, $hosting));
    $products = $productIds ? Capsule::table('tblproducts')->whereIn('id', $productIds)->pluck('name', 'id')->all() : [];
    foreach (array_slice($hosting, 0, 30) as $row) {
        $services[] = [
            'id'        => (int) $row->id,
            'product'   => (string) ($products[(int) $row->packageid] ?? ''),
            'domain'    => (string) $row->domain,
            'status'    => (string) $row->domainstatus,
            'next_due'  => cwapp_date($row->nextduedate),
            'amount'    => $money($row->amount) . ' ' . $row->billingcycle,
            'url'       => cwapp_admin_url('clientsservices.php?userid=' . $clientId . '&id=' . (int) $row->id),
        ];
    }

    # unpaid invoices with what is left to pay, overdue ones flagged
    $invoices = [];
    $unpaid   = Capsule::table('tblinvoices')->where('userid', $clientId)->where('status', 'Unpaid')->orderBy('duedate')->get()->all();
    $paid     = [];
    if ($unpaid) {
        $ids = array_map(function ($row) {
            return (int) $row->id;
        }, $unpaid);
        foreach (Capsule::table('tblaccounts')->whereIn('invoiceid', $ids)->get() as $tx) {
            $paid[(int) $tx->invoiceid] = ($paid[(int) $tx->invoiceid] ?? 0) + (float) $tx->amountin - (float) $tx->amountout;
        }
    }
    $today   = date('Y-m-d');
    $owed    = 0.0;
    $overdue = 0;
    foreach ($unpaid as $row) {
        $owed += (float) $row->total - ($paid[(int) $row->id] ?? 0);
        $overdue += (string) $row->duedate < $today ? 1 : 0;
    }
    foreach (array_slice($unpaid, 0, 20) as $row) {
        $invoices[] = [
            'id'      => (int) $row->id,
            'number'  => (string) ($row->invoicenum !== '' && $row->invoicenum !== null ? $row->invoicenum : $row->id),
            'due'     => cwapp_date($row->duedate),
            'balance' => $money((float) $row->total - ($paid[(int) $row->id] ?? 0)),
            'overdue' => (string) $row->duedate < $today,
            'url'     => cwapp_admin_url('invoices.php?action=edit&id=' . (int) $row->id),
        ];
    }

    # open tickets: statuses that WHMCS counts as active, merged ones left out
    $tickets  = [];
    $statuses = Capsule::table('tblticketstatuses')->where('showactive', 1)->pluck('title')->all();
    $open     = $statuses ? Capsule::table('tbltickets')->where('userid', $clientId)->whereIn('status', $statuses)
        ->where('merged_ticket_id', 0)->orderBy('lastreply', 'desc')->get()->all() : [];
    foreach (array_slice($open, 0, 20) as $row) {
        $tickets[] = [
            'tid'        => (string) $row->tid,
            'title'      => (string) $row->title,
            'status'     => (string) $row->status,
            'last_reply' => cwapp_date($row->lastreply),
            'url'        => cwapp_admin_url('supporttickets.php?action=view&id=' . (int) $row->id),
        ];
    }

    return [
        'id'         => (int) $client->id,
        'name'       => trim($client->firstname . ' ' . $client->lastname),
        'company'    => (string) $client->companyname,
        'email'      => (string) $client->email,
        'status'     => (string) $client->status,
        'since'      => cwapp_date($client->datecreated),
        'owner'      => $isOwner,
        'credit'     => $money($client->credit),
        'url'        => cwapp_admin_url('clientssummary.php?userid=' . (int) $client->id),
        'services'   => $services,
        'services_n' => count($hosting),
        'active_n'   => count(array_filter($hosting, function ($row) {
            return $row->domainstatus === 'Active';
        })),
        'invoices'   => $invoices,
        'unpaid_n'   => count($unpaid),
        'unpaid'     => $money($owed),
        'overdue_n'  => $overdue,
        'tickets'    => $tickets,
        'tickets_n'  => count($open),
    ];
}

function cwapp_date($value)
{
    $value = substr((string) $value, 0, 10);
    return ($value === '' || $value === '0000-00-00') ? '' : $value;
}

# WHMCS keeps text HTML-encoded; the page puts every value in with
# textContent, so entities are decoded here and the JSON is escaped anyway.
function cwapp_json(array $data)
{
    array_walk_recursive($data, function (&$value) {
        if (is_string($value)) {
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    });
    return json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

function cwapp_page($admin, $origin)
{
    $login = htmlspecialchars(cwapp_admin_url(''), ENT_QUOTES, 'UTF-8');
    if ($origin === '') {
        $body = '<div class="state"><p>Set the Chatwoot URL in Setup &rarr; Addon Modules &rarr; Chatwoot by inSave.</p></div>';
    } elseif ($admin === null) {
        $body = '<div class="state"><p>Log in to the WHMCS admin area in this browser to see the client.</p>'
            . '<p><a class="button" href="' . $login . '" target="_blank" rel="noopener">Log in to WHMCS admin</a> '
            . '<a class="button quiet" href="" onclick="location.reload(); return false;">Reload</a></p></div>';
    } elseif ($admin === false) {
        $body = '<div class="state"><p>Your admin role has no access to this card: Setup &rarr; Addon Modules &rarr; '
            . 'Chatwoot by inSave &rarr; Configure &rarr; Access Control.</p></div>';
    } else {
        $body = '<div id="card"><div class="state"><p>Waiting for the conversation&hellip;</p></div></div>';
    }

    # Every state follows Chatwoot's light or dark theme; only an admin with
    # access gets the token that loads client data.
    $script = '';
    if ($origin !== '') {
        $config = ['origin' => $origin];
        if ($admin) {
            $config['token'] = (string) generate_token('plain');
        }
        $script = '<script>window.CWAPP = ' . cwapp_json($config) . ';</script><script>' . cwapp_script() . '</script>';
    }

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>WHMCS</title><style>' . cwapp_style() . '</style></head><body>' . $body . $script . '</body></html>';
}

# Colors are Chatwoot's own (v4.19.1 tokens, light and dark), so the card
# reads as part of the dashboard. The page is Chatwoot's main background
# (--background-color, as in its sidebar): the pane under the iframe is
# near-white and would leave the white card on white. A few pill texts are a
# step darker than the tokens in the light theme to keep small text at 4.5:1
# or more.
function cwapp_style()
{
    $light = '--page: 247 247 247; --text: 28 32 36; --muted: 96 100 108; --surface: 255 255 255; --raised: 249 249 251; --border: 226 227 231;'
        . ' --link: 8 109 224; --focus: 39 129 246;'
        . ' --green-bg: 224 248 243; --green: 0 105 92; --amber-bg: 255 247 194; --amber: 135 80 0;'
        . ' --red-bg: 254 234 237; --red: 202 36 77; --blue-bg: 233 243 255; --blue: 0 90 190;'
        . ' --gray-bg: 240 240 243; --gray: 96 100 108; --iris-bg: 240 241 254; --iris: 87 83 198;';
    $dark = '--page: 28 29 32; --text: 237 238 240; --muted: 176 180 186; --surface: 29 30 36; --raised: 33 34 37; --border: 46 45 50;'
        . ' --link: 126 182 255; --focus: 39 129 246;'
        . ' --green-bg: 13 45 42; --green: 11 216 182; --amber-bg: 48 32 8; --amber: 255 202 22;'
        . ' --red-bg: 58 20 30; --red: 255 148 157; --blue-bg: 15 39 72; --blue: 126 182 255;'
        . ' --gray-bg: 39 42 45; --gray: 176 180 186; --iris-bg: 32 34 72; --iris: 158 177 255;';

    # No color-scheme: Chatwoot does not set one, and an iframe whose scheme
    # differs from its parent's gets the browser's default canvas.
    return ':root { ' . $light . ' }'
        . ' @media (prefers-color-scheme: dark) { :root:not(.light) { ' . $dark . ' } }'
        . ' :root.dark { ' . $dark . ' }'
        . <<<'CSS'

* { box-sizing: border-box; }
html { scrollbar-color: rgb(var(--border)) transparent; scrollbar-width: thin; }
body { margin: 0; padding: 12px; background: rgb(var(--page)); color: rgb(var(--text));
  font: 13px/1.5 Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased; }
a { color: rgb(var(--link)); text-decoration: none; }
a:hover { text-decoration: underline; }
a:focus-visible, button:focus-visible, summary:focus-visible { outline: 2px solid rgb(var(--focus)); outline-offset: 2px; border-radius: 6px; }
p { margin: 0; }
.muted { color: rgb(var(--muted)); }
.state { max-width: 380px; margin: 32px auto; text-align: center; color: rgb(var(--muted)); display: grid; gap: 14px; }
.button { display: inline-block; padding: 6px 12px; border: 1px solid rgb(var(--border)); border-radius: 8px;
  background: rgb(var(--surface)); color: rgb(var(--link)); font-weight: 500; }
.button:hover { text-decoration: none; background: rgb(var(--raised)); }
.button.quiet { color: rgb(var(--muted)); }
.top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.who { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; min-width: 0; color: rgb(var(--muted)); }
.icon { width: 30px; height: 30px; display: inline-grid; place-items: center; border: 1px solid rgb(var(--border)); border-radius: 8px;
  background: rgb(var(--surface)); color: rgb(var(--muted)); cursor: pointer; flex: none; }
.icon:hover { color: rgb(var(--text)); background: rgb(var(--raised)); }
.pill { display: inline-flex; align-items: center; padding: 0 8px; border-radius: 999px; font-size: 11px; font-weight: 500;
  line-height: 20px; white-space: nowrap; background: rgb(var(--gray-bg)); color: rgb(var(--gray)); }
.pill.green { background: rgb(var(--green-bg)); color: rgb(var(--green)); }
.pill.amber { background: rgb(var(--amber-bg)); color: rgb(var(--amber)); }
.pill.red { background: rgb(var(--red-bg)); color: rgb(var(--red)); }
.pill.blue { background: rgb(var(--blue-bg)); color: rgb(var(--blue)); }
.pill.iris { background: rgb(var(--iris-bg)); color: rgb(var(--iris)); }
.account { margin-top: 12px; padding: 14px; border: 1px solid rgb(var(--border)); border-radius: 12px; background: rgb(var(--surface)); }
.head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; }
.name { font-size: 15px; font-weight: 600; color: rgb(var(--text)); }
.name:hover { color: rgb(var(--link)); }
.sub { margin-top: 2px; color: rgb(var(--muted)); font-size: 12px; overflow-wrap: anywhere; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(118px, 1fr)); gap: 8px; margin: 12px 0 4px; }
.stat { padding: 8px 10px; border-radius: 8px; background: rgb(var(--raised)); border: 1px solid rgb(var(--border)); }
.stat span { display: block; font-size: 11px; color: rgb(var(--muted)); }
.stat b { display: block; font-size: 14px; font-weight: 600; margin-top: 1px; }
.stat.warn b { color: rgb(var(--red)); }
details { margin-top: 10px; }
summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 6px; padding: 4px 0;
  font-size: 12px; font-weight: 600; color: rgb(var(--muted)); user-select: none; }
summary::-webkit-details-marker { display: none; }
summary::before { content: ""; width: 6px; height: 6px; border-right: 1.5px solid currentColor; border-bottom: 1.5px solid currentColor;
  transform: rotate(-45deg); transition: transform .15s; margin: 0 3px 0 1px; }
details[open] > summary::before { transform: rotate(45deg); }
.count { font-weight: 500; padding: 0 6px; border-radius: 999px; background: rgb(var(--gray-bg)); color: rgb(var(--gray)); line-height: 18px; }
.rows { list-style: none; margin: 2px 0 0; padding: 0; }
.row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 8px 0; border-top: 1px solid rgb(var(--border)); }
.row:first-child { border-top: 0; }
.main { min-width: 0; }
.main a { font-weight: 500; }
.title { color: rgb(var(--text)); }
.side { flex: none; display: flex; flex-direction: column; align-items: flex-end; gap: 3px; text-align: right; }
.amount { font-weight: 600; font-variant-numeric: tabular-nums; }
.empty { padding: 6px 0 2px; color: rgb(var(--muted)); }
@media (max-width: 460px) { .row { flex-direction: column; gap: 4px; } .side { flex-direction: row; flex-wrap: wrap; align-items: center; gap: 4px 8px; text-align: left; } }
CSS;
}

function cwapp_script()
{
    return <<<'JS'
(function () {
  var cfg = window.CWAPP || {}, root = document.documentElement, card = document.getElementById('card'), last = null;
  var TONES = {
    Active: 'green', Pending: 'blue', Suspended: 'amber', Fraud: 'red', Inactive: 'gray', Closed: 'red',
    Open: 'blue', 'Customer-Reply': 'amber', Answered: 'green', 'In Progress': 'blue'
  };
  var REFRESH = '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true">'
    + '<path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9M13.5 2.5v3h-3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

  function el(tag, text, cls) {
    var node = document.createElement(tag);
    if (text !== undefined && text !== null) node.textContent = String(text);
    if (cls) node.className = cls;
    return node;
  }
  function add(parent) {
    for (var i = 1; i < arguments.length; i++) if (arguments[i]) parent.appendChild(arguments[i]);
    return parent;
  }
  function link(text, href, cls) {
    var a = el('a', text, cls);
    a.href = href; a.target = '_blank'; a.rel = 'noopener';
    return a;
  }
  function pill(text, tone) {
    return el('span', text, 'pill ' + (tone || TONES[text] || 'gray'));
  }
  function state(text) {
    card.textContent = '';
    add(card, add(el('div', null, 'state'), el('p', text)));
  }
  function stat(key, label, value, warn) {
    var tile = el('div', null, 'stat' + (warn ? ' warn' : ''));
    tile.setAttribute('data-stat', key);
    return add(tile, el('span', label), el('b', value));
  }
  function section(title, total, items, empty, render) {
    var box = el('details');
    box.open = true;
    add(box, add(el('summary', title + ' '), el('span', total, 'count')));
    if (!items.length) return add(box, el('p', empty, 'empty'));
    var list = el('ul', null, 'rows');
    items.forEach(function (item) { list.appendChild(render(item)); });
    return add(box, list);
  }
  function row(attr, value, main, side) {
    var li = el('li', null, 'row');
    li.setAttribute(attr, value);
    return add(li, add.apply(null, [el('div', null, 'main')].concat(main)), add.apply(null, [el('div', null, 'side')].concat(side)));
  }

  function render(data, contact) {
    card.textContent = '';
    var who = el('div', null, 'who');
    var refresh = el('button', null, 'icon');
    refresh.type = 'button';
    refresh.title = 'Refresh';
    refresh.setAttribute('aria-label', 'Refresh');
    refresh.innerHTML = REFRESH;
    refresh.onclick = function () { last = null; requestContext(); };
    if (data.match === 'identifier') who.appendChild(pill('Signed-in user', 'iris'));
    if (data.match === 'email') who.appendChild(pill('Matched by email, not confirmed', 'amber'));
    if (data.match) who.setAttribute('data-match', data.match);
    if (data.user) who.appendChild(el('span', data.user.name + ' · ' + data.user.email));
    add(card, add(el('div', null, 'top'), who, refresh));

    if (!data.accounts.length) {
      add(card, add(el('div', null, 'state'), el('p', 'No WHMCS client for ' + (contact.email || contact.name || 'this contact') + '.')));
      return;
    }

    data.accounts.forEach(function (acc) {
      var box = el('section', null, 'account');
      box.setAttribute('data-account', acc.id);
      var head = add(el('div', null, 'head'), link(acc.company || acc.name, acc.url, 'name'));
      if (acc.owner === true) head.appendChild(pill('Owner', 'gray')).setAttribute('data-role', 'owner');
      if (acc.owner === false) head.appendChild(pill('Invited user', 'gray')).setAttribute('data-role', 'invited');
      if (acc.status !== 'Active') head.appendChild(pill(acc.status));
      add(box, head, el('p', [acc.company ? acc.name : '', acc.email, 'client #' + acc.id + ' since ' + acc.since]
        .filter(Boolean).join(' · '), 'sub'));

      add(box, add(el('div', null, 'stats'),
        stat('credit', 'Credit', acc.credit),
        stat('unpaid', 'Unpaid' + (acc.unpaid_n ? ' (' + acc.unpaid_n + ')' : ''), acc.unpaid),
        stat('overdue', 'Overdue invoices', acc.overdue_n, acc.overdue_n > 0),
        stat('tickets', 'Open tickets', acc.tickets_n)));

      add(box, section('Services', acc.active_n + ' active / ' + acc.services_n, acc.services, 'No services', function (s) {
        return row('data-service', s.id,
          [link(s.product || 'Service', s.url), s.domain ? el('div', s.domain, 'sub') : null],
          [pill(s.status), el('div', [s.amount, s.next_due ? 'next due ' + s.next_due : ''].filter(Boolean).join(' · '), 'sub')]);
      }));
      add(box, section('Unpaid invoices', acc.unpaid_n, acc.invoices, 'No unpaid invoices', function (i) {
        return row('data-invoice', i.id,
          [link('Invoice #' + i.number, i.url), el('div', 'due ' + i.due, 'sub')],
          [el('span', i.balance, 'amount'), i.overdue ? pill('Overdue', 'red') : null]);
      }));
      add(box, section('Open tickets', acc.tickets_n, acc.tickets, 'No open tickets', function (t) {
        return row('data-ticket', t.tid,
          [link('#' + t.tid, t.url), el('div', t.title, 'sub title')],
          [pill(t.status), t.last_reply ? el('div', 'last reply ' + t.last_reply, 'sub') : null]);
      }));
      card.appendChild(box);
    });
  }

  function load(contact) {
    if (!contact || (!contact.identifier && !contact.email)) {
      state('This conversation has no contact with an email or a WHMCS identifier.');
      return;
    }
    state('Loading…');
    var form = new FormData();
    form.append('token', cfg.token);
    form.append('identifier', contact.identifier || '');
    form.append('email', contact.email || '');
    fetch(location.pathname, { method: 'POST', body: form, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) { state(data.error === 'login' ? 'The WHMCS admin session has ended: reload after logging in.' : 'Request refused (' + data.error + ').'); return; }
        render(data, contact);
      })
      .catch(function () { state('Could not load the client.'); });
  }

  function requestContext() {
    window.parent.postMessage('chatwoot-dashboard-app:fetch-info', cfg.origin);
  }

  // Only the configured Chatwoot origin may tell this page which contact to
  // show; the theme follows Chatwoot's on every page state.
  window.addEventListener('message', function (event) {
    if (event.origin !== cfg.origin || typeof event.data !== 'string') return;
    var message;
    try { message = JSON.parse(event.data); } catch (e) { return; }
    if (!message || message.event !== 'appContext' || !message.data) return;
    var dark = message.data.theme === 'dark';
    root.classList.toggle('dark', dark);
    root.classList.toggle('light', !dark);
    if (!cfg.token || !card) return;
    // Chatwoot sends the context again on theme and attribute changes: load
    // the client once per contact, again only on Refresh.
    var contact = message.data.contact || null;
    var key = contact ? [contact.id, contact.identifier, contact.email].join('|') : '';
    if (key === last) return;
    last = key;
    load(contact);
  });

  if (window.parent !== window) requestContext();
})();
JS;
}
