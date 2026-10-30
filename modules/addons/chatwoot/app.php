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
    $today = date('Y-m-d');
    foreach (array_slice($unpaid, 0, 20) as $row) {
        $invoices[] = [
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
        'invoices'   => $invoices,
        'tickets'    => $tickets,
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
        $body = '<p class="note">Set the Chatwoot URL in Setup &rarr; Addon Modules &rarr; Chatwoot by inSave.</p>';
    } elseif ($admin === null) {
        $body = '<p class="note">Log in to the WHMCS admin area in this browser to see the client.</p>'
            . '<p><a href="' . $login . '" target="_blank" rel="noopener">Log in to WHMCS admin</a> &middot; '
            . '<a href="" onclick="location.reload(); return false;">Reload</a></p>';
    } elseif ($admin === false) {
        $body = '<p class="note">Your admin role has no access to this card: Setup &rarr; Addon Modules &rarr; '
            . 'Chatwoot by inSave &rarr; Configure &rarr; Access Control.</p>';
    } else {
        $config = cwapp_json(['origin' => $origin, 'token' => (string) generate_token('plain')]);
        $body   = '<div id="card"><p class="note">Waiting for the conversation&hellip;</p></div>'
            . '<script>window.CWAPP = ' . $config . ';</script>'
            . '<script>' . cwapp_script() . '</script>';
    }

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
        . '<title>WHMCS</title><style>' . cwapp_style() . '</style></head><body>' . $body . '</body></html>';
}

function cwapp_style()
{
    return <<<'CSS'
body { font: 13px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 12px; color: #1f2d3d; background: #fff; }
body.dark { color: #e4e7eb; background: #1b1f24; }
a { color: #1f6feb; } body.dark a { color: #58a6ff; }
h2 { font-size: 15px; margin: 0 0 2px; } h3 { font-size: 12px; margin: 14px 0 4px; text-transform: uppercase; letter-spacing: .04em; opacity: .7; }
.note { opacity: .75; } .muted { opacity: .65; }
.badge { display: inline-block; padding: 0 6px; border-radius: 9px; font-size: 11px; background: #e8eef6; margin-left: 4px; }
body.dark .badge { background: #2d333b; } .badge.warn { background: #fde7c4; color: #6b3f00; } .badge.bad { background: #fbd5d5; color: #7a1010; }
.account { border-top: 1px solid rgba(127,127,127,.25); padding-top: 10px; margin-top: 10px; }
ul { list-style: none; margin: 0; padding: 0; } li { padding: 3px 0; border-bottom: 1px dashed rgba(127,127,127,.2); }
button { font: inherit; padding: 2px 8px; cursor: pointer; }
CSS;
}

function cwapp_script()
{
    return <<<'JS'
(function () {
  var cfg = window.CWAPP, card = document.getElementById('card'), last = null;

  function el(tag, text, cls) {
    var node = document.createElement(tag);
    if (text !== undefined && text !== null) node.textContent = String(text);
    if (cls) node.className = cls;
    return node;
  }
  function link(text, href) {
    var a = el('a', text);
    a.href = href; a.target = '_blank'; a.rel = 'noopener';
    return a;
  }
  function note(text) {
    card.textContent = '';
    card.appendChild(el('p', text, 'note'));
  }
  function section(title, items, render) {
    var box = document.createDocumentFragment();
    box.appendChild(el('h3', title));
    if (!items.length) { box.appendChild(el('p', 'None', 'muted')); return box; }
    var list = el('ul');
    items.forEach(function (item) { list.appendChild(render(item)); });
    box.appendChild(list);
    return box;
  }

  function render(data, contact) {
    card.textContent = '';
    var head = el('p');
    var refresh = el('button', 'Refresh');
    refresh.onclick = function () { last = null; requestContext(); };
    head.appendChild(refresh);
    if (data.match === 'email') head.appendChild(el('span', 'matched by email, not confirmed', 'badge warn'));
    if (data.match === 'identifier') head.appendChild(el('span', 'signed-in user', 'badge'));
    card.appendChild(head);

    if (!data.accounts.length) {
      card.appendChild(el('p', 'No WHMCS client for ' + (contact.email || contact.name || 'this contact') + '.', 'note'));
      return;
    }
    if (data.user) card.appendChild(el('p', 'User: ' + data.user.name + ' <' + data.user.email + '>', 'muted'));

    data.accounts.forEach(function (acc) {
      var box = el('div', null, 'account');
      var title = el('h2');
      title.appendChild(link(acc.company || acc.name, acc.url));
      if (acc.owner === true) title.appendChild(el('span', 'owner', 'badge'));
      if (acc.owner === false) title.appendChild(el('span', 'invited user', 'badge'));
      if (acc.status !== 'Active') title.appendChild(el('span', acc.status, 'badge bad'));
      box.appendChild(title);
      box.appendChild(el('p', [acc.company ? acc.name : '', acc.email, 'client since ' + acc.since, 'credit ' + acc.credit]
        .filter(Boolean).join(' · '), 'muted'));

      box.appendChild(section('Services (' + acc.services_n + ')', acc.services, function (s) {
        var li = el('li');
        li.appendChild(link(s.product || 'Service', s.url));
        if (s.domain) li.appendChild(el('span', ' ' + s.domain));
        li.appendChild(el('span', s.status, s.status === 'Active' ? 'badge' : 'badge bad'));
        li.appendChild(el('div', [s.amount, s.next_due ? 'next due ' + s.next_due : ''].filter(Boolean).join(' · '), 'muted'));
        return li;
      }));
      box.appendChild(section('Unpaid invoices', acc.invoices, function (i) {
        var li = el('li');
        li.appendChild(link('#' + i.number, i.url));
        li.appendChild(el('span', ' ' + i.balance + ' · due ' + i.due));
        if (i.overdue) li.appendChild(el('span', 'overdue', 'badge bad'));
        return li;
      }));
      box.appendChild(section('Open tickets', acc.tickets, function (t) {
        var li = el('li');
        li.appendChild(link('#' + t.tid, t.url));
        li.appendChild(el('span', ' ' + t.title));
        li.appendChild(el('span', t.status, 'badge'));
        return li;
      }));
      card.appendChild(box);
    });
  }

  function load(contact) {
    if (!contact || (!contact.identifier && !contact.email)) {
      note('This conversation has no contact with an email or a WHMCS identifier.');
      return;
    }
    note('Loading…');
    var form = new FormData();
    form.append('token', cfg.token);
    form.append('identifier', contact.identifier || '');
    form.append('email', contact.email || '');
    fetch(location.pathname, { method: 'POST', body: form, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) { note(data.error === 'login' ? 'The WHMCS admin session has ended: reload after logging in.' : 'Request refused (' + data.error + ').'); return; }
        render(data, contact);
      })
      .catch(function () { note('Could not load the client.'); });
  }

  function requestContext() {
    window.parent.postMessage('chatwoot-dashboard-app:fetch-info', cfg.origin);
  }

  // Only the configured Chatwoot origin may tell this page which contact to show.
  window.addEventListener('message', function (event) {
    if (event.origin !== cfg.origin || typeof event.data !== 'string') return;
    var message;
    try { message = JSON.parse(event.data); } catch (e) { return; }
    if (!message || message.event !== 'appContext' || !message.data) return;
    document.body.classList.toggle('dark', message.data.theme === 'dark');
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
