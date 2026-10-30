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
use WHMCS\Database\Capsule;

function hook_chatwoot_output($vars)
{

    $isenabled              = Capsule::table('tbladdonmodules')->select('value')->where('module', '=', 'chatwoot')->where('setting', '=', 'chatwoot_enable')->where('value', 'on')->count();
    $chatwoot_url           = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_url')->value('value');
    $chatwoot_token         = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_token')->value('value');
    $verification_hash      = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_verhash')->value('value');
    $chatwoot_position      = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_position')->value('value');
    $chatwoot_bubble        = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_bubble')->value('value');
    $chatwoot_launcherTitle = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_launcherTitle')->value('value');
    $chatwoot_dark          = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_dark')->value('value');
    $chatwoot_lang_setting  = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_lang')->value('value');

    $chatwoot_setlabel         = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_setlabel')->value('value');
    $chatwoot_setlabelloggedin = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_setlabelloggedin')->value('value');

    $chatwoot_admin = Capsule::table('tbladdonmodules')->where('module', 'chatwoot')->where('setting', 'chatwoot_enableonadmin')->value('value');

    # ignore if admin
    if (empty($chatwoot_admin) && isset($_SESSION['adminid'])) {
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

    # widget lang
    if ($chatwoot_lang_setting) {
        $chatwoot_lang = cw_langCode(ucfirst($vars['language']));
    }

    # user basic info
    $currentUser  = new CurrentUser;
    $client       = $currentUser->client();
    $user         = $currentUser->user();
    $currentpage  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

    # Fetch labels
    if (!is_null($user)) {
        $chatwoot_label = $chatwoot_setlabelloggedin;
    } else {
        $chatwoot_label = $chatwoot_setlabel;
    }

    # Get client ID and set contact ID
    if ($user && $client && $user->isOwner($client)) {
        $ClientID = $client->id;
    } elseif ($user) {
        //$ownedClients = $user->ownedClients()->all();
        //$ClientID     = $ownedClients[0]['id'];
        $ClientID = $client->id;
    }

    if (!is_null($user)) {
        $clientChatID    = hash_hmac("sha256", $ClientID, $verification_hash);
        $identifier_hash = hash_hmac("sha256", $clientChatID, $verification_hash);
    }

    # build contact info
    if (!is_null($user)) {

        $apiPostData = array('clientid' => $ClientID, 'stats' => true);
        $apiResults  = localAPI('GetClientsDetails', $apiPostData);

        # Client Info
        $clientemail    = $apiResults['client']['email'];
        $clientname     = $apiResults['client']['fullname'];
        $clientphone    = $apiResults['client']['phonenumberformatted'];
        $clientcompany  = $apiResults['client']['companyname'];
        $clientcountry  = $apiResults['client']['countryname'];
        $clientcity     = $apiResults['client']['city'];
        $clientstate    = $apiResults['client']['fullstate'];
        $clientpostcode = $apiResults['client']['postcode'];
        $clientlang     = $apiResults['client']['language'];

        # Extra Meta
        $clienttickets          = $apiResults['stats']['numactivetickets'];
        $clientcredit           = $apiResults['stats']['creditbalance'];
        $clientrevenue          = $apiResults['stats']['income'];
        $clientunpaid           = $apiResults['stats']['numunpaidinvoices'];
        $clientunpaidtotal      = $apiResults['stats']['unpaidinvoicesamount'];
        $clientoverdue          = $apiResults['stats']['numoverdueinvoices'];
        $clientoverduetotal     = $apiResults['stats']['overdueinvoicesbalance'];
        $isClientAffiliate      = $apiResults["stats"]["isAffiliate"];
        $clientemailstatus      = $apiResults["email_verified"];

        # Is Email Verified?
        if ($clientemailstatus) {
            $clientemailver = 'Verified';
        } else {
            $clientemailver = 'Not Verified';
        }

        # Is Client an Affiliate?
        if ($isClientAffiliate == 1) {
            $clientaffiliate = 'Yes';
        } else {
            $clientaffiliate = 'No';
        }
    }

    # the SDK appends '/packs/js/sdk.js' and '/widget' itself
    $chatwoot_url = rtrim((string) $chatwoot_url, '/');

    # Reset the widget only when its session belongs to someone else: after a
    # logout (flag from the logout hook, or a client marker left in the
    # browser) or when a different client is now logged in. Never for a
    # visitor who has not logged in -- resetting on every /login view dropped
    # the conversation of anyone asking for help there.
    $chatwoot_reset = !empty($_SESSION['chatwoot_reset']);
    unset($_SESSION['chatwoot_reset']);
    $chatwoot_client_key = empty($ClientID) ? '' : $clientChatID;

    $settings = [
        'position'      => $chatwoot_position,
        'type'          => $chatwoot_bubble,
        'launcherTitle' => $chatwoot_launcherTitle,
        'darkMode'      => $chatwoot_dark,
    ];
    $config = [
        'baseUrl'      => $chatwoot_url,
        'websiteToken' => $chatwoot_token,
        'locale'       => isset($chatwoot_lang) ? $chatwoot_lang : '',
    ];

    if (!is_null($user)) {
        $config += [
            'clientKey'   => $chatwoot_client_key,
            'identifier'  => $clientChatID,
            'user'        => [
                'email'           => $clientemail,
                'name'            => $clientname,
                'identifier_hash' => $identifier_hash,
                'company_name'    => $clientcompany,
            ],
            'attributes'  => [
                'ID'               => $ClientID,
                'Phone'            => $clientphone,
                'Language'         => $clientlang,
                'City'             => $clientcity,
                'State'            => $clientstate,
                'Post Code'        => $clientpostcode,
                'Country'          => $clientcountry,
                'Active Tickets'   => $clienttickets,
                'Credit Balance'   => $clientcredit,
                'Revenue'          => $clientrevenue,
                'Unpaid Invoices'  => $clientunpaid,
                'Account Unpaid'   => $clientunpaidtotal,
                'Overdue Invoices' => $clientoverdue,
                'Account Overdue'  => $clientoverduetotal,
                'Email Status'     => $clientemailver,
                'Is Affiliate'     => $clientaffiliate,
                'Current Page'     => $currentpage,
            ],
            'label'       => $chatwoot_setlabelloggedin,
            'removeLabel' => $chatwoot_setlabel,
        ];
        $handler = <<<'JS'
    window.addEventListener('chatwoot:ready', function () {
      var cw = window.$chatwoot;
      // Another client chatted last in this browser: start clean.
      // chatwoot:ready does not fire again after reset(), so this
      // client is identified on the next page.
      var current = cfg.clientKey, previous = null;
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
      cw.setUser(cfg.identifier, cfg.user);
      cw.setCustomAttributes(cfg.attributes);
      cw.deleteCustomAttribute('Test Attribute');
      cw.setLabel(cfg.label);
      cw.removeLabel(cfg.removeLabel);
      cw.setLocale(cfg.locale);
    });
JS;
    } else {
        $config += [
            'reset'      => $chatwoot_reset,
            'label'      => $chatwoot_label,
            'attributes' => ['Current Page' => $currentpage],
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
      cw.setLocale(cfg.locale);
      cw.setCustomAttributes(cfg.attributes);
    });
JS;
    }

    return cw_widget_script($settings, $config, $handler);
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

$whmcsver   = cwoot_whmcs_version();
$LogoutHook = ($whmcsver > 7) ? 'UserLogout' : 'ClientLogout';

# The reset itself happens in the widget code above, once the SDK is ready:
# the old readystatechange handler ran before window.$chatwoot existed.
add_hook($LogoutHook, 1, function ($vars) {
    $_SESSION['chatwoot_reset'] = true;
});

add_hook('ClientAreaFooterOutput', 1, 'hook_chatwoot_output');

# meta
function cwoot_whmcs_version() {
    $whmcsversion = Capsule::table('tblconfiguration')->where('setting', 'Version')->value('value');
    return substr($whmcsversion, 0, 1);
}

# to deal with langs
function cw_langCode($name)
{
    $languageCodes = array(
        "aa" => "Afar",
        "ab" => "Abkhazian",
        "ae" => "Avestan",
        "af" => "Afrikaans",
        "ak" => "Akan",
        "am" => "Amharic",
        "an" => "Aragonese",
        "ar" => "Arabic",
        "as" => "Assamese",
        "av" => "Avaric",
        "ay" => "Aymara",
        "az" => "Azerbaijani",
        "ba" => "Bashkir",
        "be" => "Belarusian",
        "bg" => "Bulgarian",
        "bh" => "Bihari",
        "bi" => "Bislama",
        "bm" => "Bambara",
        "bn" => "Bengali",
        "bo" => "Tibetan",
        "br" => "Breton",
        "bs" => "Bosnian",
        "ca" => "Catalan",
        "ce" => "Chechen",
        "ch" => "Chamorro",
        "co" => "Corsican",
        "cr" => "Cree",
        "cs" => "Czech",
        "cu" => "Church Slavic",
        "cv" => "Chuvash",
        "cy" => "Welsh",
        "da" => "Danish",
        "de" => "German",
        "dv" => "Divehi",
        "dz" => "Dzongkha",
        "ee" => "Ewe",
        "el" => "Greek",
        "en" => "English",
        "eo" => "Esperanto",
        "es" => "Spanish",
        "et" => "Estonian",
        "eu" => "Basque",
        "fa" => "Persian",
        "ff" => "Fulah",
        "fi" => "Finnish",
        "fj" => "Fijian",
        "fo" => "Faroese",
        "fr" => "French",
        "fy" => "Western Frisian",
        "ga" => "Irish",
        "gd" => "Scottish Gaelic",
        "gl" => "Galician",
        "gn" => "Guarani",
        "gu" => "Gujarati",
        "gv" => "Manx",
        "ha" => "Hausa",
        "he" => "Hebrew",
        "hi" => "Hindi",
        "ho" => "Hiri Motu",
        "hr" => "Croatian",
        "ht" => "Haitian",
        "hu" => "Hungarian",
        "hy" => "Armenian",
        "hz" => "Herero",
        "ia" => "Interlingua (International Auxiliary Language Association)",
        "id" => "Indonesian",
        "ie" => "Interlingue",
        "ig" => "Igbo",
        "ii" => "Sichuan Yi",
        "ik" => "Inupiaq",
        "io" => "Ido",
        "is" => "Icelandic",
        "it" => "Italian",
        "iu" => "Inuktitut",
        "ja" => "Japanese",
        "jv" => "Javanese",
        "ka" => "Georgian",
        "kg" => "Kongo",
        "ki" => "Kikuyu",
        "kj" => "Kwanyama",
        "kk" => "Kazakh",
        "kl" => "Kalaallisut",
        "km" => "Khmer",
        "kn" => "Kannada",
        "ko" => "Korean",
        "kr" => "Kanuri",
        "ks" => "Kashmiri",
        "ku" => "Kurdish",
        "kv" => "Komi",
        "kw" => "Cornish",
        "ky" => "Kirghiz",
        "la" => "Latin",
        "lb" => "Luxembourgish",
        "lg" => "Ganda",
        "li" => "Limburgish",
        "ln" => "Lingala",
        "lo" => "Lao",
        "lt" => "Lithuanian",
        "lu" => "Luba-Katanga",
        "lv" => "Latvian",
        "mg" => "Malagasy",
        "mh" => "Marshallese",
        "mi" => "Maori",
        "mk" => "Macedonian",
        "ml" => "Malayalam",
        "mn" => "Mongolian",
        "mr" => "Marathi",
        "ms" => "Malay",
        "mt" => "Maltese",
        "my" => "Burmese",
        "na" => "Nauru",
        "nb" => "Norwegian Bokmal",
        "nd" => "North Ndebele",
        "ne" => "Nepali",
        "ng" => "Ndonga",
        "nl" => "Dutch",
        "nn" => "Norwegian Nynorsk",
        "no" => "Norwegian",
        "nr" => "South Ndebele",
        "nv" => "Navajo",
        "ny" => "Chichewa",
        "oc" => "Occitan",
        "oj" => "Ojibwa",
        "om" => "Oromo",
        "or" => "Oriya",
        "os" => "Ossetian",
        "pa" => "Panjabi",
        "pi" => "Pali",
        "pl" => "Polish",
        "ps" => "Pashto",
        "pt" => "Portuguese",
        "qu" => "Quechua",
        "rm" => "Raeto-Romance",
        "rn" => "Kirundi",
        "ro" => "Romanian",
        "ru" => "Russian",
        "rw" => "Kinyarwanda",
        "sa" => "Sanskrit",
        "sc" => "Sardinian",
        "sd" => "Sindhi",
        "se" => "Northern Sami",
        "sg" => "Sango",
        "si" => "Sinhala",
        "sk" => "Slovak",
        "sl" => "Slovenian",
        "sm" => "Samoan",
        "sn" => "Shona",
        "so" => "Somali",
        "sq" => "Albanian",
        "sr" => "Serbian",
        "ss" => "Swati",
        "st" => "Southern Sotho",
        "su" => "Sundanese",
        "sv" => "Swedish",
        "sw" => "Swahili",
        "ta" => "Tamil",
        "te" => "Telugu",
        "tg" => "Tajik",
        "th" => "Thai",
        "ti" => "Tigrinya",
        "tk" => "Turkmen",
        "tl" => "Tagalog",
        "tn" => "Tswana",
        "to" => "Tonga",
        "tr" => "Turkish",
        "ts" => "Tsonga",
        "tt" => "Tatar",
        "tw" => "Twi",
        "ty" => "Tahitian",
        "ug" => "Uighur",
        "uk" => "Ukrainian",
        "ur" => "Urdu",
        "uz" => "Uzbek",
        "ve" => "Venda",
        "vi" => "Vietnamese",
        "vo" => "Volapuk",
        "wa" => "Walloon",
        "wo" => "Wolof",
        "xh" => "Xhosa",
        "yi" => "Yiddish",
        "yo" => "Yoruba",
        "za" => "Zhuang",
        "zh" => "Chinese",
        "zu" => "Zulu",
    );
    return array_search($name, $languageCodes);
}
