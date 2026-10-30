<?php

/***************************************************************************
// *                                                                       *
// * Chatwoot by inSave (v2.0.4-insave.4): Chatwoot live chat for the      *
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
        "name"        => "Chatwoot by inSave",
        "description" => "Chatwoot live chat for the WHMCS client area, with signed user identity and page context. Maintained by inSave Hosting; based on <a href='https://github.com/WevrLabs-Group/Chatwoot-WHMCS' target='_blank'>Chatwoot-WHMCS by WevrLabs</a> (MIT).",
        "version"     => "2.0.4-insave.4",
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
                'Description'  => 'Enter Chatwoot URL. Example: https://www.chatwoot.com',
            ],
            'chatwoot_app_origin'       => [
                'FriendlyName' => 'Dashboard App Origin',
                'Type'         => 'text',
                'Size'         => '',
                'Default'      => '',
                'Description'  => 'Origin of the Chatwoot dashboard allowed to show the WHMCS card (Chatwoot &rarr; Settings &rarr; Integrations &rarr; Dashboard apps, URL: your WHMCS address + modules/addons/chatwoot/app.php), e.g. https://chat.example.com. Leave empty to use the origin of the Chatwoot URL. Admin roles that may see the card: Access Control below.',
            ],
            'chatwoot_token'            => [
                'FriendlyName' => 'Website Widget Token',
                'Type'         => 'text',
                'Rows'         => '',
                'Cols'         => '',
                'Default'      => '',
                'Description'  => 'Enter your website widget Token in this field. You can obtain it from your Chatwoot Dashboard > Inboxes > Website > Settings.<br /> For help, visit <a href="https://www.chatwoot.com/hc/user-guide/articles/1677669989-how-to-install-live-chat-on-a-word_press-website" target="_blank">Chatwoot Docs</a>',
            ],
            'chatwoot_verhash'          => [
                'FriendlyName' => 'Secret Key (Required)',
                'Type'         => 'text',
                'Size'         => '',
                'Default'      => '',
                'Description'  => 'To make sure the conversations between the customers and the support agents are private and to disallow impersonation, you can setup identity validation in Chatwoot. <br />The key used to generate HMAC hash is unique for each webwidget and you can copy it from Inboxes -> Widget Settings -> Configuration -> Identity Validation -> Copy the token shown there<br />To learn more about this, visit <a href="https://www.chatwoot.com/hc/user-guide/articles/1677587479-how-to-enable-identity-validation-in-chatwoot" target="_blank">Chatwoot Docs</a>',
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
                'Description'  => 'Set the chat box bubble design. Read more at <a href="https://www.chatwoot.com/hc/user-guide/articles/1677587234-how-to-send-additional-user-information-to-chatwoot-using-sdk" target="_blank">Chatwoot Docs</a>.',
            ],
            'chatwoot_launcherTitle'    => [
                'FriendlyName' => 'Bubble Launcher Title',
                'Type'         => 'text',
                'Size'         => '',
                'Default'      => '',
                'Description'  => 'Set the chat box bubble design. Read more at <a href="https://www.chatwoot.com/hc/user-guide/articles/1677587234-how-to-send-additional-user-information-to-chatwoot-using-sdk" target="_blank">Chatwoot Docs</a>.',
            ],
            'chatwoot_dark'             => [
                'FriendlyName' => 'Enable Dark Mode on Widget',
                'Type'         => 'yesno',
                'Size'         => '55',
                'Default'      => 'no',
                'Description'  => 'Check to activate dark mode on the chat box.',
            ],
            'chatwoot_lang'             => [
                'FriendlyName' => 'Dynamic Language',
                'Type'         => 'yesno',
                'Size'         => '55',
                'Default'      => 'no',
                'Description'  => 'check this box to set the chat box language according to current WHMCS clientarea langauge preference.',
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
