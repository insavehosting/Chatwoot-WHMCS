<?php

/***************************************************************************
// *                                                                       *
// * Chatwoot by inSave: the WHMCS card for Chatwoot Dashboard Apps.       *
// * Chatwoot loads this page in an iframe next to a conversation and      *
//   posts the conversation context to it; the page shows the WHMCS        *
//   client behind the contact. With "Ticket departments" set, an agent    *
//   can open a WHMCS ticket from the conversation, with its transcript.   *
//   Nothing else is written, and nothing is sent to Chatwoot.             *
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

# Ticket from chat: limits. Declared up here because the request is handled
# (and the script exits) before the functions further down. The ticket
# message is a TEXT column in WHMCS: 65 535 bytes, not characters.
const CWAPP_TRANSCRIPT_MAX = 60000;   # bytes of the ticket message
const CWAPP_POST_MAX       = 2000000; # bytes of the posted messages
const CWAPP_MESSAGES_MAX   = 5000;
const CWAPP_PRIORITIES     = ['Low', 'Medium', 'High'];

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
    if (($_POST['action'] ?? '') === 'ticket') {
        [$status, $answer] = cwapp_ticket($cwapp_admin, $cwapp_settings);
        http_response_code($status);
        echo cwapp_json($answer);
        exit;
    }
    echo cwapp_json(cwapp_lookup((string) ($_POST['identifier'] ?? ''), (string) ($_POST['email'] ?? ''), $cwapp_admin, $cwapp_settings));
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
function cwapp_lookup($identifier, $email, $admin = null, array $settings = [])
{
    $user  = cwapp_signed_user($identifier);
    $match = $user ? 'identifier' : 'none';
    $email = trim($email);
    if (!$user && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $user  = Capsule::table('tblusers')->where('email', $email)->first();
        $match = $user ? 'email' : 'none';
    }

    $accounts = [];
    $canTicket = [];
    if ($user) {
        $links = Capsule::table('tblusers_clients')->where('auth_user_id', (int) $user->id)->get();
        foreach ($links as $link) {
            $accounts[(int) $link->client_id] = (bool) $link->owner;
            $canTicket[(int) $link->client_id] = $match === 'identifier' && cwapp_user_can_ticket($link);
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
            $account['can_ticket'] = !empty($canTicket[$clientId]);
            $out[] = $account;
        }
    }

    return [
        'ticket'   => cwapp_ticket_config($admin, $settings),
        'match'    => $out ? $match : 'none',
        'user'     => $user ? [
            'id'    => (int) $user->id,
            'name'  => trim($user->first_name . ' ' . $user->last_name),
            'email' => $user->email,
        ] : null,
        'accounts' => $out,
    ];
}

# The WHMCS user behind a whmcs:u:<id> identifier, or null.
function cwapp_signed_user($identifier)
{
    if (!preg_match('/^whmcs:u:(\d+)$/', (string) $identifier, $m)) {
        return null;
    }
    return Capsule::table('tblusers')->where('id', (int) $m[1])->first() ?: null;
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

# --- Ticket from chat -------------------------------------------------------

# "Ticket departments": Chatwoot team => WHMCS department id, "*" for a
# conversation without a team. Departments that do not exist are dropped and
# reported, so a typo shows up in the card instead of opening tickets nowhere.
function cwapp_departments(array $settings)
{
    $raw = trim((string) ($settings['chatwoot_ticket_departments'] ?? ''));
    $out = ['map' => [], 'names' => [], 'ignored' => []];
    if ($raw === '') {
        return $out;
    }
    $existing = Capsule::table('tblticketdepartments')->pluck('name', 'id')->all();
    foreach (explode(',', $raw) as $pair) {
        $pair = trim($pair);
        if ($pair === '') {
            continue;
        }
        if (!preg_match('/^([^=]+)=\s*(\d+)$/', $pair, $m) || !isset($existing[(int) $m[2]])) {
            $out['ignored'][] = $pair;
            continue;
        }
        $out['map'][mb_strtolower(trim($m[1]))] = (int) $m[2];
    }
    foreach (array_unique(array_values($out['map'])) as $id) {
        $out['names'][$id] = (string) $existing[$id];
    }
    return $out;
}

# WHMCS's own permission check for the admin behind the session.
function cwapp_admin_may_open_tickets($admin)
{
    try {
        if (!$admin || !class_exists('\WHMCS\User\Admin')) {
            return false;
        }
        $model = \WHMCS\User\Admin::find((int) $admin->id);
        return $model && method_exists($model, 'hasPermission') && (bool) $model->hasPermission('Open New Ticket');
    } catch (\Throwable $e) {
        return false;
    }
}

# A user may open tickets for an account they own, or with the "tickets"
# permission when invited.
function cwapp_user_can_ticket($link)
{
    if ((int) $link->owner === 1) {
        return true;
    }
    $permissions = array_map('trim', explode(',', (string) ($link->permissions ?? '')));
    return in_array('tickets', $permissions, true);
}

# What the card needs to offer a ticket: the departments to choose from and
# why there is no button, if there is none.
function cwapp_ticket_config($admin, array $settings)
{
    $departments = cwapp_departments($settings);
    if (!$departments['map']) {
        return ['enabled' => false, 'reason' => 'off', 'ignored' => $departments['ignored']];
    }
    if (!cwapp_admin_may_open_tickets($admin)) {
        return ['enabled' => false, 'reason' => 'permission', 'ignored' => $departments['ignored']];
    }
    $list = [];
    foreach ($departments['names'] as $id => $name) {
        $list[] = ['id' => $id, 'name' => $name];
    }
    return [
        'enabled'     => true,
        'teams'       => $departments['map'],
        'departments' => $list,
        'ignored'     => $departments['ignored'],
        'priorities'  => CWAPP_PRIORITIES,
    ];
}

# The public part of a conversation as plain text: client (0) and agent (1)
# messages that are not private notes, oldest first. Activity (2) and
# template (3) messages stay out. Attachments are named, never linked: the
# links Chatwoot hands out are signed and open the file to anyone. When the
# text is longer than the ticket allows, the oldest messages go; $skipped is
# how many the card already left out.
function cwapp_transcript($chat, array $messages, $skipped = 0)
{
    $rows = [];
    foreach ($messages as $message) {
        if (!is_array($message) || !in_array($message['type'] ?? null, [0, 1], true) || ($message['private'] ?? true) !== false) {
            continue;
        }
        # Chatwoot's created_at: Unix seconds (a message just sent may carry a fraction)
        $time = $message['time'] ?? null;
        if ((!is_int($time) && !is_float($time)) || $time <= 0) {
            continue;
        }
        $time = (int) $time;
        $text = is_string($message['text'] ?? null) ? cwapp_bmp($message['text']) : '';
        $files = [];
        foreach ((array) ($message['files'] ?? []) as $file) {
            if (is_string($file) && trim($file) !== '') {
                $files[] = preg_replace('/[\x00-\x1F\x7F]/u', '', cwapp_bmp(mb_substr(trim($file), 0, 200)));
            }
        }
        if (trim($text) === '' && !$files) {
            continue;
        }
        $name = is_string($message['name'] ?? null) && trim($message['name']) !== '' ? trim($message['name'])
            : ($message['type'] === 0 ? 'Client' : 'Agent');
        $line = '[' . gmdate('Y-m-d H:i', $time) . ' UTC] ' . preg_replace('/\s+/u', ' ', cwapp_bmp(mb_substr($name, 0, 100))) . ': '
            . str_replace(["\r\n", "\r"], "\n", $text);
        foreach ($files as $file) {
            $line .= "\n" . '(attachment ' . $file . ' is in the chat)';
        }
        $rows[] = ['time' => $time, 'id' => (int) ($message['id'] ?? 0), 'line' => $line, 'client' => $message['type'] === 0, 'text' => $text];
    }
    usort($rows, function ($a, $b) {
        return [$a['time'], $a['id']] <=> [$b['time'], $b['id']];
    });
    if (!$rows) {
        return null;
    }

    $first = null;
    foreach ($rows as $row) {
        if ($row['client'] && trim($row['text']) !== '') {
            $first = $row['text'];
            break;
        }
    }
    $from = gmdate('Y-m-d H:i', $rows[0]['time']);
    $to   = gmdate('Y-m-d H:i', $rows[count($rows) - 1]['time']);

    # newest first until the budget is spent; the header is measured with the
    # longest omitted count it can carry
    $header = function ($omitted) use ($chat, $from, $to) {
        return 'Chat transcript #' . $chat . ', ' . $from . ' – ' . $to . ' UTC'
            . ($omitted ? "\n" . $omitted . ' earlier message' . ($omitted === 1 ? '' : 's') . ' omitted to fit the ticket.' : '');
    };
    $budget = CWAPP_TRANSCRIPT_MAX - strlen($header(count($rows) + $skipped)) - 2;
    $kept = [];
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $size = strlen($rows[$i]['line']) + 2;
        if ($size > $budget) {
            break;
        }
        $budget -= $size;
        $kept[] = $rows[$i]['line'];
    }
    if (!$kept) {
        # one message longer than the whole ticket: its end is cut
        $kept[] = mb_strcut($rows[count($rows) - 1]['line'], 0, max(0, $budget));
    }
    $omitted = count($rows) - count($kept) + $skipped;
    $text = $header($omitted) . "\n\n" . implode("\n\n", array_reverse($kept));

    return ['text' => $text, 'count' => count($kept), 'omitted' => $omitted, 'first' => $first];
}

# WHMCS's ticket tables are often utf8 (3 bytes), where a 4-byte character
# (emoji) cuts the rest of the text off without an error: such characters
# become U+FFFD.
function cwapp_bmp($text)
{
    return (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", (string) $text);
}

# WHMCS escapes every request variable (as htmlspecialchars does) before a
# module runs, which breaks JSON and changes quotes in a subject. The card
# sends both base64-encoded, which that escaping leaves alone.
function cwapp_post_base64($key, $max)
{
    $raw = (string) ($_POST[$key] ?? '');
    if ($raw === '' || strlen($raw) > $max) {
        return null;
    }
    $decoded = base64_decode($raw, true);
    return $decoded === false ? null : $decoded;
}

# The subject every ticket from chat starts with; it is also how a second
# ticket for the same conversation is recognised.
function cwapp_ticket_prefix($chat)
{
    return 'Chat #' . (int) $chat . ':';
}

function cwapp_ticket_links($id, $tid, $c)
{
    return [
        'id'         => (int) $id,
        'tid'        => (string) $tid,
        'admin_url'  => cwapp_admin_url('supporttickets.php?action=view&id=' . (int) $id),
        'client_url' => rtrim((string) Setting::getValue('SystemURL'), '/') . '/viewticket.php?tid=' . rawurlencode((string) $tid)
            . '&c=' . rawurlencode((string) $c),
    ];
}

# POST action=ticket. Everything the card sent is checked again here: the
# contact must be a signed-in user (whmcs:u:<id>) of the account, allowed to
# open tickets; the department must be one of the setting; the admin must
# hold WHMCS's "Open New Ticket". Returns [HTTP status, answer].
function cwapp_ticket($admin, array $settings)
{
    $departments = cwapp_departments($settings);
    if (!$departments['map']) {
        return [400, ['error' => 'off']];
    }
    if (!cwapp_admin_may_open_tickets($admin)) {
        return [403, ['error' => 'permission']];
    }
    $user = cwapp_signed_user($_POST['identifier'] ?? '');
    if (!$user) {
        return [403, ['error' => 'identity']];
    }
    $clientId = (int) ($_POST['client'] ?? 0);
    $link = Capsule::table('tblusers_clients')->where('auth_user_id', (int) $user->id)->where('client_id', $clientId)->first();
    if (!$link || !cwapp_user_can_ticket($link)) {
        return [403, ['error' => 'user']];
    }
    $deptId = (int) ($_POST['dept'] ?? 0);
    if (!in_array($deptId, array_values($departments['map']), true)) {
        return [400, ['error' => 'department']];
    }
    $chat = (int) ($_POST['chat'] ?? 0);
    $priority = (string) ($_POST['priority'] ?? 'Medium');
    if ($chat <= 0 || !in_array($priority, CWAPP_PRIORITIES, true)) {
        return [400, ['error' => 'request']];
    }
    if (($_POST['loaded'] ?? '') !== '1') {
        return [400, ['error' => 'incomplete']];
    }
    $json = cwapp_post_base64('messages', intdiv(CWAPP_POST_MAX * 4, 3) + 4);
    $messages = $json !== null ? json_decode($json, true) : null;
    if (!is_array($messages) || count($messages) > CWAPP_MESSAGES_MAX) {
        return [400, ['error' => 'messages']];
    }
    $skipped = max(0, min(1000000, (int) ($_POST['skipped'] ?? 0)));
    $transcript = cwapp_transcript($chat, $messages, $skipped);
    if (!$transcript) {
        return [400, ['error' => 'empty']];
    }

    $prefix = cwapp_ticket_prefix($chat);
    $existing = Capsule::table('tbltickets')->where('userid', $clientId)->where('merged_ticket_id', 0)
        ->where('title', 'like', $prefix . '%')->first();
    if ($existing) {
        return [200, ['duplicate' => true, 'ticket' => cwapp_ticket_links($existing->id, $existing->tid, $existing->c)]];
    }

    $topic = (string) cwapp_post_base64('topic', 2000);
    $topic = mb_check_encoding($topic, 'UTF-8') ? trim(preg_replace('/\s+/u', ' ', cwapp_bmp($topic))) : '';
    if ($topic === '') {
        $topic = trim(preg_replace('/\s+/u', ' ', (string) $transcript['first']));
    }
    $subject = mb_substr($prefix . ' ' . ($topic !== '' ? mb_substr($topic, 0, 60) : 'conversation'), 0, 200);

    $result = localAPI('OpenTicket', [
        'clientid' => $clientId,
        'userid'   => (int) $user->id,
        'deptid'   => $deptId,
        'subject'  => $subject,
        'message'  => $transcript['text'],
        'priority' => $priority,
        'admin'    => true,
        'markdown' => false,
    ], (string) $admin->username);
    if (($result['result'] ?? '') !== 'success') {
        return [502, ['error' => 'whmcs', 'message' => (string) ($result['message'] ?? '')]];
    }
    logActivity('Chatwoot card: ticket #' . $result['tid'] . ' opened from chat #' . $chat);

    return [200, ['ticket' => cwapp_ticket_links($result['id'], $result['tid'], $result['c'] ?? ''), 'messages' => $transcript['count'], 'omitted' => $transcript['omitted']]];
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
.note { margin-top: 10px; padding: 8px 10px; border-radius: 8px; background: rgb(var(--amber-bg)); color: rgb(var(--amber)); font-size: 12px; }
.actions { margin-top: 10px; }
button.button { font: inherit; cursor: pointer; }
.button.primary { background: rgb(var(--link)); border-color: rgb(var(--link)); color: rgb(var(--surface)); }
.button.primary:hover { background: rgb(var(--link)); filter: brightness(1.08); }
.button[disabled] { opacity: .55; cursor: not-allowed; filter: none; }
.ticket { margin-top: 10px; padding: 12px; border: 1px solid rgb(var(--border)); border-radius: 10px; background: rgb(var(--raised)); display: grid; gap: 10px; }
.ticket label { display: grid; gap: 4px; font-size: 12px; font-weight: 600; color: rgb(var(--muted)); }
.ticket select, .ticket input { font: inherit; font-weight: 400; color: rgb(var(--text)); background: rgb(var(--surface)); border: 1px solid rgb(var(--border));
  border-radius: 8px; padding: 6px 8px; min-width: 0; width: 100%; }
.subject { display: flex; align-items: center; gap: 6px; }
.subject span { flex: none; color: rgb(var(--text)); font-weight: 500; }
.result { display: grid; gap: 6px; }
.result .copy { display: flex; gap: 6px; }
@media (max-width: 460px) { .row { flex-direction: column; gap: 4px; } .side { flex-direction: row; flex-wrap: wrap; align-items: center; gap: 4px 8px; text-align: left; } }
CSS;
}

function cwapp_script()
{
    return <<<'JS'
(function () {
  var cfg = window.CWAPP || {}, root = document.documentElement, card = document.getElementById('card'), last = null;
  // The open conversation as Chatwoot last sent it: kept up to date on every
  // appContext, so the ticket form sees messages loaded after the card was.
  var conv = null, reopen = {}, refreshing = false;
  // What the card sends at most: the newest messages; the server is told how
  // many older ones it left out, and trims to the ticket size itself.
  var SEND_MAX = 2000, SEND_BYTES = 400000;
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

  // --- ticket from chat ---------------------------------------------------
  function fileName(file) {
    try {
      var name = decodeURIComponent(new URL(file.data_url).pathname.split('/').pop() || '');
      if (name) return name;
    } catch (e) {}
    return 'file' + (file.extension ? '.' + file.extension : '');
  }
  // The public part of the conversation: client (0) and agent (1) messages
  // that are not private notes. Attachments go by name only.
  function publicMessages() {
    var list = (conv && Array.isArray(conv.messages)) ? conv.messages : [];
    return list.filter(function (m) {
      return m && (m.message_type === 0 || m.message_type === 1) && m.private === false;
    }).map(function (m) {
      var sender = m.sender || {};
      return {
        id: m.id, type: m.message_type, private: false, time: m.created_at,
        name: sender.available_name || sender.name || '',
        text: typeof m.content === 'string' ? m.content : '',
        files: (m.attachments || []).map(fileName)
      };
    }).filter(function (m) {
      return typeof m.time === 'number' && m.time > 0 && (m.text.trim() !== '' || m.files.length);
    }).sort(function (a, b) { return a.time - b.time || a.id - b.id; });
  }
  // The newest public messages that fit what the card sends, and how many
  // older ones were left out.
  function messagesToSend() {
    var all = publicMessages(), out = [], bytes = 0;
    for (var i = all.length - 1; i >= 0 && out.length < SEND_MAX; i--) {
      var size = new Blob([all[i].text + all[i].name + all[i].files.join('')]).size + 120;
      if (out.length && bytes + size > SEND_BYTES) break;
      bytes += size;
      out.unshift(all[i]);
    }
    return { messages: out, skipped: all.length - out.length };
  }
  // base64 of the UTF-8 text: WHMCS escapes request variables, base64 survives it
  function b64(text) {
    var bytes = new TextEncoder().encode(text), out = '';
    for (var i = 0; i < bytes.length; i += 0x8000) out += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return btoa(out);
  }
  function firstClientText(messages) {
    for (var i = 0; i < messages.length; i++) {
      if (messages[i].type === 0 && messages[i].text.trim()) return messages[i].text.replace(/\s+/g, ' ').trim().slice(0, 60);
    }
    return '';
  }
  function select(field, options, value) {
    var node = el('select');
    node.setAttribute('data-field', field);
    options.forEach(function (o) {
      var option = el('option', o.name);
      option.value = String(o.id);
      if (String(o.id) === String(value)) option.selected = true;
      node.appendChild(option);
    });
    return node;
  }
  function labelled(text, control) {
    return add(el('label', text), control);
  }
  // Count and button follow the conversation as Chatwoot last sent it.
  function refreshForm(form) {
    var messages = publicMessages(), loaded = !!(conv && conv.allMessagesLoaded === true);
    var info = form.querySelector('[data-field="count"]'), button = form.querySelector('[data-create]');
    if (!loaded) {
      info.textContent = 'Not every message is loaded: scroll the conversation up to its first message, then press Refresh.';
    } else if (!messages.length) {
      info.textContent = 'No public messages to put into a ticket.';
    } else {
      info.textContent = messages.length + ' message' + (messages.length === 1 ? '' : 's') + ' will go into the ticket.';
    }
    info.setAttribute('data-loaded', loaded ? '1' : '0');
    button.disabled = !loaded || !messages.length || form.getAttribute('data-busy') === '1';
    var prefix = form.querySelector('[data-field="prefix"]');
    if (prefix) prefix.textContent = 'Chat #' + (conv ? conv.id : '?') + ':';
  }
  // Another conversation of the same contact: an open form starts over for it.
  function refreshForms() {
    if (!card) return;
    Array.prototype.forEach.call(card.querySelectorAll('[data-ticket-form]'), function (form) {
      if (conv && form.getAttribute('data-chat') !== String(conv.id) && form.getAttribute('data-busy') !== '1' && form.rebuild) {
        form.replaceWith(form.rebuild());
      } else {
        refreshForm(form);
      }
    });
  }
  function result(form, answer) {
    var box = form.querySelector('[data-result]');
    box.textContent = '';
    if (answer.error) {
      var reasons = {
        user: 'This user may not open tickets for the account.', identity: 'The contact is not a signed-in WHMCS user.',
        permission: 'Your admin role may not open tickets.', department: 'That department is not in the setting.',
        incomplete: 'Not every message is loaded: scroll up and press Refresh.', empty: 'No public messages to put into a ticket.',
        messages: 'The conversation could not be read; press Refresh and try again.', request: 'The request was not valid.',
        whmcs: 'WHMCS refused the ticket' + (answer.message ? ': ' + answer.message : '.'), login: 'The WHMCS admin session has ended.'
      };
      add(box, el('p', reasons[answer.error] || 'Request refused (' + answer.error + ').', 'note'));
      return;
    }
    var t = answer.ticket;
    box.setAttribute('data-tid', t.tid);
    add(box, el('p', (answer.duplicate ? 'This chat already has ticket #' : 'Ticket #') + t.tid + (answer.duplicate ? '.' : ' opened.')),
      link('Open in WHMCS', t.admin_url));
    var input = el('input');
    input.readOnly = true;
    input.value = t.client_url;
    input.setAttribute('data-field', 'client-url');
    input.setAttribute('aria-label', 'Link for the client');
    var copy = el('button', 'Copy', 'button');
    copy.type = 'button';
    copy.onclick = function () {
      input.focus(); input.select();
      var done = function () { copy.textContent = 'Copied'; };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t.client_url).then(done, function () { if (document.execCommand('copy')) done(); });
      } else if (document.execCommand('copy')) done();
    };
    add(box, el('span', 'Link for the client, to paste into the chat:', 'sub'), add(el('div', null, 'copy'), input, copy));
  }
  function ticketForm(acc, contact, ticket) {
    var form = el('div', null, 'ticket');
    form.setAttribute('data-ticket-form', acc.id);
    form.setAttribute('data-chat', conv ? String(conv.id) : '');
    form.rebuild = function () { return ticketForm(acc, contact, ticket); };
    var team = conv && conv.meta && conv.meta.team && conv.meta.team.name ? String(conv.meta.team.name).toLowerCase() : '';
    var dept = ticket.teams[team] || ticket.teams['*'] || (ticket.departments[0] && ticket.departments[0].id);
    var topic = el('input');
    topic.setAttribute('data-field', 'topic');
    topic.maxLength = 60;
    topic.value = firstClientText(publicMessages());
    var priority = select('priority', (ticket.priorities || ['Medium']).map(function (p) { return { id: p, name: p }; }), 'Medium');
    var prefix = el('span');
    prefix.setAttribute('data-field', 'prefix');
    var info = el('p', null, 'sub');
    info.setAttribute('data-field', 'count');
    var create = el('button', 'Create ticket', 'button primary');
    create.type = 'button';
    create.setAttribute('data-create', '');
    var out = el('div', null, 'result');
    out.setAttribute('data-result', '');
    create.onclick = function () {
      if (create.disabled || !conv) return;
      form.setAttribute('data-busy', '1');
      refreshForm(form);
      var body = new FormData();
      body.append('token', cfg.token);
      body.append('action', 'ticket');
      body.append('identifier', contact.identifier || '');
      body.append('client', acc.id);
      body.append('dept', form.querySelector('[data-field="dept"]').value);
      body.append('topic', b64(topic.value));
      body.append('priority', priority.value);
      body.append('chat', conv.id);
      body.append('loaded', conv.allMessagesLoaded === true ? '1' : '0');
      var sending = messagesToSend();
      body.append('messages', b64(JSON.stringify(sending.messages)));
      body.append('skipped', sending.skipped);
      fetch(location.pathname, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (answer) { result(form, answer); })
        .catch(function () { result(form, { error: 'network' }); })
        .then(function () { form.removeAttribute('data-busy'); refreshForm(form); });
    };
    add(form,
      labelled('Department', select('dept', ticket.departments, dept)),
      labelled('Subject', add(el('div', null, 'subject'), prefix, topic)),
      labelled('Priority', priority),
      info, add(el('div'), create), out);
    refreshForm(form);
    return form;
  }
  // Refresh redraws the card; a ticket form that was open comes back with
  // what the agent typed in it.
  function keepForms() {
    reopen = {};
    if (!card) return;
    Array.prototype.forEach.call(card.querySelectorAll('[data-ticket-form]'), function (form) {
      reopen[form.getAttribute('data-ticket-form')] = {
        chat: form.getAttribute('data-chat'),
        dept: form.querySelector('[data-field="dept"]').value,
        topic: form.querySelector('[data-field="topic"]').value,
        priority: form.querySelector('[data-field="priority"]').value
      };
    });
  }
  function ticketPart(box, acc, data, contact) {
    var ticket = data.ticket || {};
    if (!ticket.enabled || data.match === 'email') return;
    if (!acc.can_ticket) {
      add(box, el('p', 'This user may not open tickets for this account (no "tickets" permission).', 'note'));
      return;
    }
    var open = el('button', 'Open ticket', 'button');
    open.type = 'button';
    open.setAttribute('data-open-ticket', acc.id);
    var wrap = add(el('div', null, 'actions'), open);
    open.onclick = function () {
      var existing = box.querySelector('[data-ticket-form]');
      if (existing) { existing.remove(); open.setAttribute('aria-expanded', 'false'); return; }
      open.setAttribute('aria-expanded', 'true');
      wrap.after(ticketForm(acc, contact, ticket));
    };
    add(box, wrap);
    var kept = reopen[String(acc.id)];
    if (kept && conv && kept.chat === String(conv.id)) {
      open.onclick();
      var form = box.querySelector('[data-ticket-form]');
      ['dept', 'topic', 'priority'].forEach(function (f) {
        var node = form.querySelector('[data-field="' + f + '"]');
        if (node && kept[f] !== undefined && (f !== 'dept' || node.querySelector('option[value="' + kept[f] + '"]'))) node.value = kept[f];
      });
    }
  }

  function render(data, contact) {
    card.textContent = '';
    var who = el('div', null, 'who');
    var refresh = el('button', null, 'icon');
    refresh.type = 'button';
    refresh.title = 'Refresh';
    refresh.setAttribute('aria-label', 'Refresh');
    refresh.innerHTML = REFRESH;
    // not while a ticket is being created: the answer would land in a form that is gone
    refresh.onclick = function () {
      if (card.querySelector('[data-ticket-form][data-busy]')) return;
      last = null; refreshing = true; requestContext();
    };
    if (data.match === 'identifier') who.appendChild(pill('Signed-in user', 'iris'));
    if (data.match === 'email') who.appendChild(pill('Matched by email, not confirmed', 'amber'));
    if (data.match) who.setAttribute('data-match', data.match);
    if (data.user) who.appendChild(el('span', data.user.name + ' · ' + data.user.email));
    add(card, add(el('div', null, 'top'), who, refresh));
    var ticket = data.ticket || {};
    if (ticket.ignored && ticket.ignored.length) {
      add(card, el('p', 'Ticket departments: ignored ' + ticket.ignored.join(', ') + ' (no such WHMCS department).', 'note'));
    }
    if (ticket.enabled && data.match === 'email') {
      add(card, el('p', 'To open a ticket, first merge this contact in Chatwoot with the contact of the signed-in WHMCS user.', 'note'));
    }

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
      ticketPart(box, acc, data, contact);
      card.appendChild(box);
    });
  }

  function load(contact) {
    if (!contact || (!contact.identifier && !contact.email)) {
      state('This conversation has no contact with an email or a WHMCS identifier.');
      return;
    }
    if (refreshing) keepForms(); else reopen = {};
    refreshing = false;
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
        reopen = {};
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
    conv = message.data.conversation || null;
    // Chatwoot sends the context again on theme and attribute changes and on
    // Refresh: load the client once per contact, again only on Refresh; the
    // conversation (for a ticket) is taken from every context.
    var contact = message.data.contact || null;
    var key = contact ? [contact.id, contact.identifier, contact.email].join('|') : '';
    if (key === last) { refreshForms(); return; }
    last = key;
    load(contact);
  });

  if (window.parent !== window) requestContext();
})();
JS;
}
