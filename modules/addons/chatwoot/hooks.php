<?php

/***************************************************************************
// *                                                                       *
// * Chatwoot WHMCS Addon                                                  *
// * This addon module enables you to integrate Chatwoot with your WHMCS   *
//   and leverage its powerful features.                                   *
// * For assistance on how to use and setup Chatwoot, visit                *
//   https://www.chatwoot.com/docs/channels/website                        *
// *                                                                       *
// *************************************************************************
// *                                                                       *
// * Contributed by: WevrLabs Hosting                                      *
// * Email: hello@wevrlabs.net                                             *
// * Website: https://wevrlabs.net                                         *
// *                                                                       *
// *************************************************************************/

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Authentication\CurrentUser;
use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

function hook_chatwoot_output($vars)
{

    # all module settings in one query
    $cw = cw_settings();
    $isenabled                 = ($cw['chatwoot_enable'] ?? '') === 'on';
    $chatwoot_url              = $cw['chatwoot_url'] ?? '';
    $chatwoot_token            = $cw['chatwoot_token'] ?? '';
    $verification_hash         = $cw['chatwoot_verhash'] ?? '';
    $chatwoot_position         = $cw['chatwoot_position'] ?? 'right';
    $chatwoot_bubble           = $cw['chatwoot_bubble'] ?? 'Standard';
    $chatwoot_launcherTitle    = $cw['chatwoot_launcherTitle'] ?? '';
    $chatwoot_dark             = $cw['chatwoot_dark'] ?? '';
    $chatwoot_lang_setting     = $cw['chatwoot_lang'] ?? '';
    $chatwoot_setlabel         = $cw['chatwoot_setlabel'] ?? '';
    $chatwoot_setlabelloggedin = $cw['chatwoot_setlabelloggedin'] ?? '';
    $chatwoot_admin            = $cw['chatwoot_enableonadmin'] ?? '';

    # An admin session shares the browser session with the client area (also
    # "Login as Client"). Without the setting there is no widget; with it the
    # widget is shown but never identified, so an admin cannot chat as a client.
    $admin_session = isset($_SESSION['adminid']);
    if ($admin_session && empty($chatwoot_admin)) {
        return;
    }

    # Disable or Enable Chatwoot
    if (empty($isenabled)) {
        return;
    }

    # bubble design
    if ($chatwoot_bubble == 'Standard') {
        $chatwoot_bubble = 'standard';
    } elseif ($chatwoot_bubble == 'Expanded Bubble') {
        $chatwoot_bubble = 'expanded_bubble';
    }

    # dark mode
    if ($chatwoot_dark == 'on') {
        $chatwoot_dark = 'auto';
    }

    # widget lang: only a language Chatwoot has
    $chatwoot_lang = $chatwoot_lang_setting ? cw_locale($vars['language'] ?? '') : null;

    # user basic info
    $currentUser = new CurrentUser;
    $user        = $currentUser->user();
    $client      = $user ? $currentUser->client() : null;
    $currentpage = cw_current_page();

    # the SDK appends '/packs/js/sdk.js' and '/widget' itself
    $chatwoot_url = rtrim((string) $chatwoot_url, '/');

    # Reset the widget only when its session belongs to someone else: after a
    # logout (flag from the logout hook, or a client marker left in the
    # browser) or when a different user is now logged in. Never for a
    # visitor who has not logged in -- resetting on every /login view dropped
    # the conversation of anyone asking for help there.
    $chatwoot_reset = !empty($_SESSION['chatwoot_reset']);
    unset($_SESSION['chatwoot_reset']);

    $settings = [
        'position'      => $chatwoot_position,
        'type'          => $chatwoot_bubble,
        'launcherTitle' => $chatwoot_launcherTitle,
        'darkMode'      => $chatwoot_dark,
    ];
    if ($chatwoot_lang) {
        $settings['locale'] = $chatwoot_lang;
    }
    $config = [
        'baseUrl'      => $chatwoot_url,
        'websiteToken' => $chatwoot_token,
        'locale'       => $chatwoot_lang,
    ];

    if ($user && $client && !$admin_session) {
        # The Chatwoot contact is the WHMCS user, not the client account: a
        # user invited into someone else's account gets a contact of their
        # own instead of the owner's, with the owner's history.
        $ClientID        = (int) $client->id;
        $identifier      = 'whmcs:u:' . $user->id;
        $identifier_hash = hash_hmac('sha256', $identifier, $verification_hash);
        $username        = trim($user->first_name . ' ' . $user->last_name);

        $apiResults = cw_client_details($user->id, $ClientID);

        # Client Info
        $clientphone    = $apiResults['client']['phonenumberformatted'] ?? '';
        $clientcompany  = $apiResults['client']['companyname'] ?? '';
        $clientcountry  = $apiResults['client']['countryname'] ?? '';
        $clientcity     = $apiResults['client']['city'] ?? '';
        $clientstate    = $apiResults['client']['fullstate'] ?? '';
        $clientpostcode = $apiResults['client']['postcode'] ?? '';
        $clientlang     = $apiResults['client']['language'] ?? '';

        # Extra Meta
        $clienttickets          = (int) ($apiResults['stats']['numactivetickets'] ?? 0);
        $clientcredit           = $apiResults['stats']['creditbalance'] ?? '';
        $clientrevenue          = $apiResults['stats']['income'] ?? '';
        $clientunpaid           = (int) ($apiResults['stats']['numunpaidinvoices'] ?? 0);
        $clientunpaidtotal      = $apiResults['stats']['unpaidinvoicesamount'] ?? '';
        $clientoverdue          = (int) ($apiResults['stats']['numoverdueinvoices'] ?? 0);
        $clientoverduetotal     = $apiResults['stats']['overdueinvoicesbalance'] ?? '';
        $clientaffiliate        = !empty($apiResults['stats']['isAffiliate']);
        $clientemailverified    = !empty($apiResults['email_verified']);

        $config += [
            'identifier'  => $identifier,
            'user'        => [
                'email'           => $user->email,
                'name'            => $username !== '' ? $username : $user->email,
                'identifier_hash' => $identifier_hash,
                'company_name'    => $clientcompany,
            ],
            # snake_case keys: Chatwoot attribute definitions and automation
            # rules are keyed by them
            'attributes'  => [
                'whmcs_user_id'    => (int) $user->id,
                'whmcs_client_id'  => $ClientID,
                'whmcs_is_owner'   => (bool) $user->isOwner($client),
                'phone'            => $clientphone,
                'language'         => $clientlang,
                'city'             => $clientcity,
                'state'            => $clientstate,
                'postcode'         => $clientpostcode,
                'country'          => $clientcountry,
                'active_tickets'   => $clienttickets,
                'credit_balance'   => $clientcredit,
                'revenue'          => $clientrevenue,
                'unpaid_invoices'  => $clientunpaid,
                'unpaid_amount'    => $clientunpaidtotal,
                'overdue_invoices' => $clientoverdue,
                'overdue_amount'   => $clientoverduetotal,
                'email_verified'   => $clientemailverified,
                'is_affiliate'     => $clientaffiliate,
                'current_page'     => $currentpage,
            ],
            'label'        => $chatwoot_setlabelloggedin,
            'removeLabel'  => $chatwoot_setlabel,
            'conversation' => cw_page_context($vars, $ClientID),
        ];
        $handler = <<<'JS'
    window.addEventListener('chatwoot:ready', function () {
      var cw = window.$chatwoot;
      // Another user chatted last in this browser: start clean.
      // chatwoot:ready does not fire again after reset(), so this
      // user is identified on the next page.
      var current = cfg.identifier, previous = null;
      if (current) {
        try {
          previous = localStorage.getItem('insave_cw_client');
          localStorage.setItem('insave_cw_client', current);
        } catch (e) {}
      }
      if (previous && previous !== current) {
        cw.reset();
        return;
      }
      var userCookie = 'cw_user_' + cfg.websiteToken, before = readCookie(userCookie);
      cw.setUser(cfg.identifier, cfg.user);
      sendAttributes(cw, readCookie(userCookie) !== before);
      cw.setLabel(cfg.label);
      cw.removeLabel(cfg.removeLabel);
      if (cfg.locale) cw.setLocale(cfg.locale);
      // Chatwoot replaces the conversation's attributes with this set; with
      // no conversation yet it keeps them for the first message.
      if (cfg.conversation) cw.setConversationCustomAttributes(cfg.conversation);
    });
JS;
    } elseif ($user) {
        # Logged in, but no account selected yet (/user/accounts), or an admin
        # session: the widget works as for a visitor and is not identified.
        # The marker of the user's session is left alone -- this is not a
        # logout.
        $config += [
            'label'      => $chatwoot_setlabel,
            'attributes' => ['current_page' => $currentpage],
        ];
        $handler = <<<'JS'
    window.addEventListener('chatwoot:ready', function () {
      var cw = window.$chatwoot;
      cw.setLabel(cfg.label);
      if (cfg.locale) cw.setLocale(cfg.locale);
      sendAttributes(cw, false);
    });
JS;
    } else {
        $config += [
            'reset'      => $chatwoot_reset,
            'label'      => $chatwoot_setlabel,
            'attributes' => ['current_page' => $currentpage],
        ];
        $handler = <<<'JS'
    window.addEventListener('chatwoot:ready', function () {
      var cw = window.$chatwoot;
      // A client logged out in this browser: drop their session.
      var previous = null;
      try {
        previous = localStorage.getItem('insave_cw_client');
        localStorage.removeItem('insave_cw_client');
      } catch (e) {}
      if (previous || cfg.reset) {
        cw.reset();
        return;
      }
      cw.setLabel(cfg.label);
      if (cfg.locale) cw.setLocale(cfg.locale);
      sendAttributes(cw, false);
    });
JS;
    }

    $config['attributesHash'] = substr(hash('sha256', cw_js([
        $config['identifier'] ?? '',
        $config['attributes'],
    ])), 0, 16);

    return cw_widget_script($settings, $config, $handler);
}

# The page the visitor is on, for agents: SystemURL plus the route, without
# the query string. Routes that carry a token in the path
# (/password/reset/redeem/<token>) are cut to the route itself, and so is
# any path from a segment that looks like a token. The Host header is not
# used: it is whatever the request says.
function cw_current_page()
{
    $systemUrl = rtrim((string) Setting::getValue('SystemURL'), '/') . '/';
    $uri       = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path      = (string) parse_url($uri, PHP_URL_PATH);

    # WHMCS installed in a subdirectory: the request path starts with it
    $base = rtrim((string) parse_url($systemUrl, PHP_URL_PATH), '/');
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base));
    }

    # "index.php/route" and "index.php?rp=/route" URL formats
    if (preg_match('~^/index\.php(/.*)?$~', $path, $m)) {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        if (!empty($m[1])) {
            $path = $m[1];
        } elseif (isset($query['rp']) && is_string($query['rp'])) {
            $path = '/' . ltrim($query['rp'], '/');
        }
    }

    $tokenRoutes = ['password/reset', 'user/verify', 'invite'];
    $kept        = [];
    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment === '' || cw_looks_like_token($segment)) {
            break;
        }
        $route = implode('/', $kept);
        foreach ($tokenRoutes as $tokenRoute) {
            # nothing after the route but an action word (redeem, use)
            if ($route === $tokenRoute || strpos($route, $tokenRoute . '/') === 0) {
                if ($route !== $tokenRoute || !in_array($segment, ['redeem', 'use'], true)) {
                    break 2;
                }
            }
        }
        $kept[] = $segment;
    }

    return $systemUrl . implode('/', $kept);
}

function cw_looks_like_token($segment)
{
    # slugs are words joined by hyphens; tokens are long runs of letters and digits
    return strlen($segment) >= 32
        || (strlen($segment) >= 20 && preg_match('/\d/', $segment) && preg_match('/^[A-Za-z0-9_.=~]+$/', $segment));
}

# What the client is looking at, for the conversation rather than the
# contact: a service, an invoice or a ticket. The id comes from the template
# variables (from the query string if a template lacks it); everything else
# is read from the database, and only for an object of the current client.
# Template values are not used as such: statuses and product names there are
# translated, and an "invalid ticket" page still carries the requested tid.
function cw_page_context(array $vars, $clientId)
{
    $page = $vars['templatefile'] ?? '';

    if ($page === 'clientareaproductdetails') {
        $id      = (int) ($vars['id'] ?? $_GET['id'] ?? 0);
        $service = Capsule::table('tblhosting')->where('id', $id)->where('userid', $clientId)->first();
        if (!$service) {
            return null;
        }
        return [
            'service_id'     => (int) $service->id,
            'product'        => (string) Capsule::table('tblproducts')->where('id', $service->packageid)->value('name'),
            'domain'         => (string) $service->domain,
            'service_status' => (string) $service->domainstatus,
        ];
    }

    if ($page === 'viewinvoice') {
        $id      = (int) ($vars['invoiceid'] ?? $_GET['id'] ?? 0);
        $invoice = Capsule::table('tblinvoices')->where('id', $id)->where('userid', $clientId)->first();
        return $invoice ? ['invoice_id' => (int) $invoice->id, 'invoice_status' => (string) $invoice->status] : null;
    }

    if ($page === 'viewticket') {
        $tid    = (string) ($vars['tid'] ?? $_GET['tid'] ?? '');
        $ticket = $tid === '' ? null : Capsule::table('tbltickets')->where('tid', $tid)->where('userid', $clientId)->first();
        return $ticket ? ['ticket_tid' => (string) $ticket->tid] : null;
    }

    return null;
}

function cw_settings()
{
    return Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->pluck('value', 'setting')->all();
}

# GetClientsDetails with stats is the heaviest call on the page: keep its
# result in the session for a few minutes, per user and client.
function cw_client_details($userId, $clientId)
{
    $key   = $userId . ':' . $clientId;
    $cache = $_SESSION['insave_cw_details'] ?? null;
    if (is_array($cache) && $cache['key'] === $key && time() - $cache['at'] < 300) {
        return $cache['data'];
    }

    $api  = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => true]);
    $data = [
        'client'         => $api['client'] ?? [],
        'stats'          => $api['stats'] ?? [],
        'email_verified' => $api['email_verified'] ?? null,
    ];
    if (($api['result'] ?? '') === 'success') {
        $_SESSION['insave_cw_details'] = ['key' => $key, 'at' => time(), 'data' => $data];
    }
    return $data;
}

# Every value reaches the page through json_encode: nothing is pasted into a
# JS string by hand. WHMCS stores client fields HTML-encoded (O&#039;Brien),
# so entities are decoded first; the HEX flags keep </script>, quotes and
# ampersands from ending the script tag or the string.
function cw_js($value)
{
    return json_encode(
        cw_js_prepare($value),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
}

function cw_js_prepare($value)
{
    if (is_array($value)) {
        return array_map('cw_js_prepare', $value);
    }
    if (is_string($value)) {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (is_bool($value) || is_int($value) || is_float($value) || is_null($value)) {
        return $value;
    }
    return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function cw_widget_script(array $settings, array $config, $handler)
{
    $loader = <<<'JS'
<script>
  window.chatwootSettings = __SETTINGS__;
  (function (d, t, cfg) {
    var g = d.createElement(t), s = d.getElementsByTagName(t)[0];
    g.src = cfg.baseUrl + '/packs/js/sdk.js';
    g.defer = true;
    g.async = true;
    s.parentNode.insertBefore(g, s);
    g.onload = function () {
      window.chatwootSDK.run({ websiteToken: cfg.websiteToken, baseUrl: cfg.baseUrl });
    };
    function readCookie(name) {
      var m = d.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
      return m ? m[1] : '';
    }
    // Contact attributes go out again only when they changed, or when the
    // widget session did: a reset or a new visitor gets a new cw_conversation
    // token. While setUser is identifying the contact, attributes may land on
    // the contact being merged away, so the next page sends them again.
    function sendAttributes(cw, identifying) {
      var token = readCookie('cw_conversation'), h = 2166136261, sent = null;
      for (var i = 0; i < token.length; i++) {
        h = Math.imul(h ^ token.charCodeAt(i), 16777619) >>> 0;
      }
      var stamp = cfg.attributesHash + ':' + h.toString(16);
      try { sent = localStorage.getItem('insave_cw_attrs'); } catch (e) {}
      if (sent === stamp && !identifying) return;
      cw.setCustomAttributes(cfg.attributes);
      try {
        if (identifying) localStorage.removeItem('insave_cw_attrs');
        else localStorage.setItem('insave_cw_attrs', stamp);
      } catch (e) {}
    }
__HANDLER__
  })(document, 'script', __CONFIG__);
</script>
JS;
    return strtr($loader, [
        '__SETTINGS__' => cw_js($settings),
        '__CONFIG__'   => cw_js($config),
        '__HANDLER__'  => $handler,
    ]);
}

# The reset itself happens in the widget code above, once the SDK is ready:
# the old readystatechange handler ran before window.$chatwoot existed.
add_hook('UserLogout', 1, function ($vars) {
    $_SESSION['chatwoot_reset'] = true;
});

add_hook('ClientAreaFooterOutput', 1, 'hook_chatwoot_output');

# WHMCS language (lang/<name>.php) -> Chatwoot widget locale, from
# config/initializers/languages.rb in Chatwoot v4.19.1. WHMCS has spelled
# Ukrainian both ways. Languages Chatwoot does not have return null.
function cw_locale($language)
{
    $locales = [
        'arabic'        => 'ar',
        'bulgarian'     => 'bg',
        'catalan'       => 'ca',
        'chinese'       => 'zh_CN',
        'czech'         => 'cs',
        'danish'        => 'da',
        'dutch'         => 'nl',
        'english'       => 'en',
        'estonian'      => 'et',
        'farsi'         => 'fa',
        'finnish'       => 'fi',
        'french'        => 'fr',
        'german'        => 'de',
        'greek'         => 'el',
        'hebrew'        => 'he',
        'hungarian'     => 'hu',
        'icelandic'     => 'is',
        'indonesian'    => 'id',
        'italian'       => 'it',
        'japanese'      => 'ja',
        'korean'        => 'ko',
        'latvian'       => 'lv',
        'lithuanian'    => 'lt',
        'norwegian'     => 'no',
        'polish'        => 'pl',
        'portuguese'    => 'pt',
        'portuguese-pt' => 'pt',
        'portuguese-br' => 'pt_BR',
        'romanian'      => 'ro',
        'russian'       => 'ru',
        'serbian'       => 'sr',
        'slovak'        => 'sk',
        'slovenian'     => 'sl',
        'spanish'       => 'es',
        'swedish'       => 'sv',
        'thai'          => 'th',
        'turkish'       => 'tr',
        'ukrainian'     => 'uk',
        'ukranian'      => 'uk',
        'uzbek'         => 'uz',
        'vietnamese'    => 'vi',
    ];
    $language = strtolower(trim((string) $language));
    return $locales[$language] ?? null;
}
