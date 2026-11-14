<?php

/***************************************************************************
// *                                                                       *
// * Chatwoot by inSave Hosting (v3.0.0): Chatwoot live chat for the       *
//   WHMCS client area. Requires WHMCS 8 or later.                         *
// * Maintained by inSave Hosting OÜ:                                      *
//   https://github.com/insavehosting/Chatwoot-WHMCS                       *
// *                                                                       *
// *************************************************************************
// *                                                                       *
// * Based on Chatwoot-WHMCS 2.0.4 by WevrLabs Hosting (MIT):              *
//   https://github.com/WevrLabs-Group/Chatwoot-WHMCS                      *
// * Email: hello@wevrlabs.net                                             *
// * Website: https://wevrlabs.net                                         *
// *                                                                       *
// *************************************************************************/

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function chatwoot_config()
{
    return [
        "name"        => "Chatwoot by inSave Hosting",
        "description" => "Chatwoot live chat for the WHMCS client area with signed user identity, a WHMCS client card inside Chatwoot and tickets from chats. Maintained by inSave Hosting; based on <a href='https://github.com/WevrLabs-Group/Chatwoot-WHMCS' target='_blank'>Chatwoot-WHMCS by WevrLabs</a> (MIT).",
        "version"     => "3.0.0",
        "author"      => "<a href='https://github.com/insavehosting/Chatwoot-WHMCS' target='_blank'>inSave Hosting</a>",
        "fields"      => [
            'chatwoot_enable'           => [
                'FriendlyName' => 'Enable Chatwoot',
                'Type'         => 'yesno',
                'Size'         => '55',
                'Default'      => 'yes',
                'Description'  => 'Check to activate the chat box.',
            ],
            'chatwoot_url'              => [
                'FriendlyName' => 'Chatwoot URL',
                'Type'         => 'text',
                'Rows'         => '',
                'Cols'         => '',
                'Default'      => '',
                'Description'  => 'The address of your Chatwoot, for example https://chat.example.com',
            ],
            'chatwoot_app_origin'       => [
                'FriendlyName' => 'Dashboard App Origin',
                'Type'         => 'text',
                'Size'         => '',
                'Default'      => '',
                'Description'  => 'Origin of the Chatwoot dashboard allowed to show the WHMCS card (Chatwoot &rarr; Settings &rarr; Integrations &rarr; Dashboard apps, URL: your WHMCS address + modules/addons/chatwoot/app.php), e.g. https://chat.example.com. Leave empty to use the origin of the Chatwoot URL. Admin roles that may see the card: Access Control below.',
            ],
            'chatwoot_ticket_departments' => [
                'FriendlyName' => 'Ticket departments',
                'Type'         => 'text',
                'Size'         => '',
                'Default'      => '',
                'Description'  => 'Lets agents open a WHMCS ticket from a conversation in the WHMCS card, with its transcript. Chatwoot team = WHMCS department ID, comma-separated; <code>*</code> is a conversation without a team, e.g. <code>sales=2,support=3,*=1</code>. Empty: no ticket button. The admin also needs the "Open New Ticket" permission.',
            ],
            'chatwoot_token'            => [
                'FriendlyName' => 'Website Widget Token',
                'Type'         => 'text',
                'Rows'         => '',
                'Cols'         => '',
                'Default'      => '',
                'Description'  => 'The <code>websiteToken</code> of your Website inbox: Chatwoot &rarr; Settings &rarr; Inboxes &rarr; the inbox &rarr; Configuration, in the widget script.',
            ],
            'chatwoot_verhash'          => [
                'FriendlyName' => 'Secret Key (Required)',
                'Type'         => 'text',
                'Size'         => '',
                'Default'      => '',
                'Description'  => 'The Identity Validation key of the same inbox: Chatwoot &rarr; Settings &rarr; Inboxes &rarr; the inbox &rarr; Configuration &rarr; Identity Validation. It signs the client&#39;s identity so nobody can pose as another client. Turn on <strong>Enforce user identity validation</strong> there as well. <a href="https://www.chatwoot.com/hc/user-guide/articles/1677587479-how-to-enable-identity-validation-in-chatwoot" target="_blank">Chatwoot Docs</a>',
            ],
            'chatwoot_position'         => [
                'FriendlyName' => 'Chat Box Position',
                'Type'         => 'radio',
                'Options'      => 'right,left',
                'Default'      => 'right',
                'Description'  => 'Set your chat box position, whether to be left or right in the page.',
            ],
            'chatwoot_bubble'           => [
                'FriendlyName' => 'Chat Box Bubble',
                'Type'         => 'radio',
                'Options'      => 'Standard,Expanded Bubble',
                'Default'      => 'Standard',
                'Description'  => 'A round bubble (Standard) or a bubble with text (Expanded Bubble).',
            ],
            'chatwoot_launcherTitle'    => [
                'FriendlyName' => 'Bubble Launcher Title',
                'Type'         => 'text',
                'Size'         => '',
                'Default'      => '',
                'Description'  => 'The text on the expanded bubble, for example: Chat with us.',
            ],
            'chatwoot_dark'             => [
                'FriendlyName' => 'Enable Dark Mode on Widget',
                'Type'         => 'yesno',
                'Size'         => '55',
                'Default'      => 'no',
                'Description'  => 'Tick to let the chat box follow the visitor&#39;s light or dark system theme. Unticked: always light.',
            ],
            'chatwoot_lang'             => [
                'FriendlyName' => 'Dynamic Language',
                'Type'         => 'yesno',
                'Size'         => '55',
                'Default'      => 'no',
                'Description'  => 'Tick to show the chat box in the language of the client area, when Chatwoot has that language.',
            ],
            'chatwoot_setlabel'         => [
                'FriendlyName' => 'Default non-logged In Conversation Label',
                'Type'         => 'text',
                'Size'         => '15',
                'Default'      => '',
                'Description'  => 'Set the default label for conversations for non-logged in visitors.<br /> The Label must already be present in your Chatwoot Dashboard > Labels',
            ],
            'chatwoot_setlabelloggedin' => [
                'FriendlyName' => 'Default Logged In Conversation Label',
                'Type'         => 'text',
                'Size'         => '15',
                'Default'      => '',
                'Description'  => 'Set the default label for conversations for logged in clients.<br /> The Label must already be present in your Chatwoot Dashboard > Labels',
            ],
            'chatwoot_enableonadmin'    => [
                'FriendlyName' => 'Enable on Login as Client',
                'Type'         => 'yesno',
                'Size'         => '55',
                'Default'      => 'no',
                'Description'  => 'Show the chat box while an admin is logged in to WHMCS in the same browser, including Login as Client. The widget is not identified in admin sessions, so an admin never chats as the client.',
            ],
        ],
    ];
}

function chatwoot_activate()
{
    return ['status' => 'success', 'description' => "Chatwoot for WHMCS has been successfully activated! Don't forget to configure the settings below!"];
}

# Runs when the version above changes (Setup -> Addon Modules after an upload).
function chatwoot_upgrade($vars)
{
    # chatwoot_jscode was a setting of 2.0.0 that nothing has read since 2.0.1;
    # it still holds the old widget snippet. Other modules' rows stay.
    Capsule::table('tbladdonmodules')
        ->where('module', 'chatwoot')
        ->where('setting', 'chatwoot_jscode')
        ->delete();
}
