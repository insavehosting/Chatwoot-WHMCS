# Chatwoot by inSave

A WHMCS addon that puts the [Chatwoot](https://www.chatwoot.com) website widget into the client area and identifies the logged-in user in Chatwoot. Maintained by inSave Hosting OÜ; based on [WevrLabs-Group/Chatwoot-WHMCS](https://github.com/WevrLabs-Group/Chatwoot-WHMCS) 2.0.4. What changed is in [CHANGELOG.md](CHANGELOG.md).

Requires WHMCS 8 or later and PHP 7.4+. SDK and API behaviour checked against Chatwoot v4.19.1.

## Contents

| Path | What it is | Goes to the server |
|---|---|---|
| `modules/addons/chatwoot/chatwoot.php` | addon configuration, `chatwoot_upgrade` | yes, same path under the WHMCS root |
| `modules/addons/chatwoot/hooks.php` | the widget (`ClientAreaFooterOutput`), the reset flag on logout (`UserLogout`) | yes, same path under the WHMCS root |
| `tools/clear-legacy-identifiers.rb` | upgrading from 2.0.4: clears the old identifiers in Chatwoot (rails runner) | only while upgrading |
| `tools/export-identifier-map.php`, `tools/migrate-identifiers.rb` | upgrading from 2.0.4: maps old contacts to the new identifiers instead | only while upgrading, outside the web root |
| `docs/` | [upgrading from 2.0.4](docs/deploy.md), [contacts from 2.0.4](docs/migrate-identifiers.md) | no |
| `tests/stand/` | local test stand | no |

## Fresh install

1. Copy `modules/` into the WHMCS root, so that the files land in `<WHMCS>/modules/addons/chatwoot/`.
2. WHMCS Admin → Setup → Addon Modules → Chatwoot by inSave → Activate, then Configure.
3. Fill in the settings below. In Chatwoot, open Inboxes → your website inbox → Configuration → Identity Validation, copy the key into "Secret Key" and turn on **Enforce user identity validation**: without it the identifier can be forged.

Upgrading from 2.0.4: follow [docs/deploy.md](docs/deploy.md).

## Settings

| Setting | Key in `tbladdonmodules` | What it does |
|---|---|---|
| Enable Chatwoot | `chatwoot_enable` | turns the widget on |
| Chatwoot URL | `chatwoot_url` | Chatwoot address, e.g. `https://chat.example.com`; a trailing slash is fine |
| Website Widget Token | `chatwoot_token` | website inbox token (Inboxes → website → Configuration) |
| Secret Key | `chatwoot_verhash` | the inbox's Identity Validation key; signs the identifier and never reaches the page |
| Chat Box Position | `chatwoot_position` | `right` or `left` |
| Chat Box Bubble | `chatwoot_bubble` | `Standard` or `Expanded Bubble` |
| Bubble Launcher Title | `chatwoot_launcherTitle` | text on the expanded bubble |
| Enable Dark Mode on Widget | `chatwoot_dark` | on: `darkMode: auto` (follows the OS); off: light |
| Dynamic Language | `chatwoot_lang` | widget language = client area language (table `cw_locale()` in `hooks.php`) |
| Default non-logged In Conversation Label | `chatwoot_setlabel` | conversation label for visitors; the label must exist in Chatwoot |
| Default Logged In Conversation Label | `chatwoot_setlabelloggedin` | conversation label for clients |
| Enable on Login as Client | `chatwoot_enableonadmin` | show the widget while an admin is logged in to WHMCS in the same browser (including Login as Client). The widget is never identified in an admin session, so an admin cannot chat as the client. Off: no widget in admin sessions |

## What is sent to Chatwoot

Everything is set from the visitor's browser. Only the identifier is signed (HMAC): with identity validation enforced it cannot be forged. Attributes and labels can be changed by the client from the browser console, so do not base decisions about money or access on them.

### Visitors, users with no account selected, admin sessions

- label `chatwoot_setlabel`, locale (with Dynamic Language);
- contact attribute `current_page`.

No `setUser`. For a user without a selected account (e.g. on `/user/accounts`) and in an admin session (with "Enable on Login as Client" on) the widget is not reset either.

### A user in a client account

`setUser` — the Chatwoot contact is the WHMCS **user**:

| Field | Source |
|---|---|
| `identifier` | `whmcs:u:<tblusers.id>` |
| `identifier_hash` | HMAC-SHA256 of `identifier` with `chatwoot_verhash` |
| `email`, `name` | the user (`tblusers`): email, first and last name |
| `company_name` | the client account the user is in |

Contact attributes (`setCustomAttributes`), for the account the user is in now (`GetClientsDetails` with stats):

| Key | Type | Source |
|---|---|---|
| `whmcs_user_id` | number | user ID |
| `whmcs_client_id` | number | client account ID |
| `whmcs_is_owner` | boolean | the user owns the account |
| `phone` | string | account phone (`phonenumberformatted`) |
| `language` | string | account language |
| `city`, `state`, `postcode`, `country` | string | account address |
| `active_tickets` | number | active tickets |
| `credit_balance` | string | credit, with currency |
| `revenue` | string | income from the account, with currency |
| `unpaid_invoices`, `unpaid_amount` | number, string | unpaid invoices: count and total |
| `overdue_invoices`, `overdue_amount` | number, string | overdue invoices: count and total |
| `email_verified` | boolean | the account email is verified |
| `is_affiliate` | boolean | the account is an affiliate |
| `current_page` | string | see below |

The financial attributes are in the page source for every user of the account, including invited users without invoice permissions. Keep that in mind when inviting users.

Labels: `chatwoot_setlabelloggedin` is set, `chatwoot_setlabel` removed.

Conversation attributes (`setConversationCustomAttributes`), on three pages only and only for an object of the current account (checked in the database):

| Page | Keys |
|---|---|
| service (`clientareaproductdetails`) | `service_id`, `product`, `domain`, `service_status` |
| invoice (`viewinvoice`) | `invoice_id`, `invoice_status` |
| ticket (`viewticket`) | `ticket_tid` |

Chatwoot replaces the conversation's attributes with this set as a whole; with no conversation yet, the widget keeps the set and sends it with the first message. Do not put other attributes on conversations of this inbox by hand: the next such page overwrites them.

### `current_page`

The WHMCS SystemURL plus the route, without the query string. `index.php?rp=/route` and `index.php/route` are read as the route. On `/password/reset/…`, `/user/verify/…` and `/invite/…` only the route is kept (plus `redeem`/`use`); any path is cut at a segment that looks like a token.

## Widget reset

The widget is reset when its session belongs to someone else:

- **after a logout.** The `UserLogout` hook sets a session flag, and client area pages leave the identifier in `localStorage['insave_cw_client']`; the next visitor page resets the widget.
- **when another user is identified in the same browser.** The marker differs: reset, and the new user is identified from the next page (Chatwoot does not fire `chatwoot:ready` again after `reset()`).

A visitor who simply opens `/login` is never reset.

## Load per page

- one query for the module settings;
- `GetClientsDetails` at most once per 5 minutes per user and account (cached in `$_SESSION['insave_cw_details']`);
- one lookup on the service, invoice and ticket pages;
- in the browser: Chatwoot skips `setUser` when nothing changed; `setCustomAttributes` goes out only when the set or the widget session changed (fingerprint in `localStorage['insave_cw_attrs']`); labels are sent on every page, as in 2.0.4.

## Upgrade and rollback

- [docs/deploy.md](docs/deploy.md) — order, files with md5, checks, rollback.
- [docs/migrate-identifiers.md](docs/migrate-identifiers.md) — what to do with the contacts 2.0.4 created.

## Testing without a live WHMCS

```bash
python3 tests/stand/run.py
# PASS: the last line is ALL PASS
```

Needs php 8.x, ruby and Playwright for Python with Chromium. The stand renders the real `hooks.php` with WHMCS stubs and opens it in Chromium with a fake SDK that records every call.

## License

MIT (see [LICENSE](LICENSE)): © 2020 WevrLabs Group, © 2026 inSave Hosting OÜ.

Chatwoot is a trademark of its owner. This project is not affiliated with or endorsed by Chatwoot.
