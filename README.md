# Chatwoot by inSave

A WHMCS addon that puts the [Chatwoot](https://www.chatwoot.com) website widget into the client area and identifies the logged-in user in Chatwoot. Maintained by inSave Hosting OÜ; based on [WevrLabs-Group/Chatwoot-WHMCS](https://github.com/WevrLabs-Group/Chatwoot-WHMCS) 2.0.4. What changed is in [CHANGELOG.md](CHANGELOG.md).

Requires WHMCS 8 or later and PHP 7.4+. SDK and API behaviour checked against Chatwoot v4.19.1.

## Contents

| Path | What it is | Goes to the server |
|---|---|---|
| `modules/addons/chatwoot/chatwoot.php` | addon configuration, `chatwoot_upgrade` | yes, same path under the WHMCS root |
| `modules/addons/chatwoot/hooks.php` | the widget (`ClientAreaFooterOutput`), the reset flag on logout (`UserLogout`) | yes, same path under the WHMCS root |
| `modules/addons/chatwoot/app.php` | the WHMCS card for a Chatwoot Dashboard App | yes, same path under the WHMCS root |
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
| Dashboard App Origin | `chatwoot_app_origin` | origin of the Chatwoot dashboard allowed to show the WHMCS card; empty: the origin of the Chatwoot URL |
| Ticket departments | `chatwoot_ticket_departments` | lets agents open a WHMCS ticket from the card: `team=department ID` pairs, comma-separated, `*` for a conversation without a team, e.g. `sales=2,support=3,*=1`; empty: no ticket button (see "Ticket from chat") |
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
| `unpaid_invoices` | number | unpaid invoices |
| `overdue_invoices` | number | overdue invoices |
| `email_verified` | boolean | the account email is verified |
| `is_affiliate` | boolean | the account is an affiliate |
| `current_page` | string | see below |

No money amounts are sent: everything here is in the page source for every user of the account, invited users included. Agents see amounts in the WHMCS card below.

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

## WHMCS card in Chatwoot (Dashboard App)

`app.php` shows the WHMCS client behind the contact of a Chatwoot conversation, in Chatwoot's light or dark theme: the account (name, company, status, client since, credit), services (product, domain, status, next due date), unpaid invoices with the balance left and an overdue flag, and open tickets, each linked to the WHMCS admin area. It writes nothing, except a ticket an agent opens from the conversation ("Ticket from chat", off unless set up), and never writes to Chatwoot.

**Setup**

1. WHMCS Admin → Setup → Addon Modules → Chatwoot by inSave → Configure → **Access Control**: tick the admin roles that may see the card. With no role ticked nobody sees it.
2. Chatwoot → Settings → Integrations → Dashboard apps → Add: any name, URL `<WHMCS SystemURL>modules/addons/chatwoot/app.php`.
3. The page sends `Content-Security-Policy: frame-ancestors <Chatwoot origin>` itself. If the web server adds `X-Frame-Options: SAMEORIGIN` to every response, browsers ignore it here because `frame-ancestors` is present (checked in Chromium, WebKit and Firefox); dropping it for this path is still cleaner, e.g. with nginx `map $uri $xfo { /modules/addons/chatwoot/app.php ""; default "SAMEORIGIN"; }` and `add_header X-Frame-Options $xfo;` (an empty value sends no header).

**Access.** The card uses the WHMCS admin session of the browser the agent works in: log in to the WHMCS admin area in the same browser. Chatwoot and WHMCS must be on the same site (e.g. `chat.example.com` and `billing.example.com`), otherwise the browser does not send the WHMCS session cookie into the iframe and the card asks to log in. Without a session, or for a role without access, the page returns no data.

**How it works.** Chatwoot posts the conversation context to the iframe (`appContext`). The page accepts it only from the Dashboard App Origin, then posts the contact's identifier and email back to `app.php` with the WHMCS CSRF token. A `whmcs:u:<id>` identifier was signed by the widget and identifies the user; a contact without one (Telegram, Meta, a visitor) is matched by email and marked "not confirmed". Response headers: `frame-ancestors` (the Chatwoot origin only), `Cache-Control: no-store`, `Referrer-Policy: no-referrer`; no `X-Frame-Options`.

### Ticket from chat

When a chat turns into work, an agent opens a WHMCS ticket from the card, with the conversation in it.

**Setup.** Fill in **Ticket departments** (Settings above): each Chatwoot team goes to a WHMCS department, `*` is a conversation without a team. A department ID that does not exist is ignored and named in the card. The admin needs WHMCS's own **Open New Ticket** permission (Setup → Staff Management → Administrator Roles), checked with `WHMCS\User\Admin::hasPermission()`, besides the addon's Access Control.

**When the button is there.** On an account in the card, when all of these hold:

- the contact is a signed-in WHMCS user (identifier `whmcs:u:<id>`). A contact matched only by email gets no button and a hint: merge it in Chatwoot with the contact of the signed-in user first (Telegram and Meta contacts are like that until merged);
- the user owns the account, or is invited with the **tickets** permission;
- the admin has Open New Ticket.

**The form.** Department (the one mapped to the conversation's team), subject `Chat #<conversation number>: ` followed by the start of the client's first message (editable), priority (Medium), and how many messages will go in. **Create ticket** stays disabled until Chatwoot has loaded the conversation from its first message: Chatwoot loads a conversation page by page and does not post the context again when older messages load, so scroll the conversation up to the start and press Refresh in the card (the open form and what was typed in it are kept).

**What goes into the ticket.** A plain-text transcript (Markdown off): a header `Chat transcript #<number>, <first> – <last> UTC`, then `[YYYY-MM-DD HH:MM UTC] <name>: <text>` per message. Only public messages of the client and the agents; private notes, activity and template messages (greeting, out of office, email request, CSAT) stay out, and so do Chatwoot's translations. Attachments are named, never linked: Chatwoot's file links are signed and open the file to anyone who has them. The ticket message is a `TEXT` column in WHMCS (65 535 bytes), so the transcript is kept under 60 000 bytes: the oldest messages are left out and the header says how many. The card sends at most the newest 2 000 messages (about 400 KB) and tells the server how many older ones it left out. Characters outside the Basic Multilingual Plane (emoji) become `�`: WHMCS's ticket tables are often `utf8` (3 bytes), where such a character silently cuts off the rest of the text.

**What WHMCS does.** `OpenTicket` with the client, the user (`userid`, WHMCS 8.5+), the department, the subject and priority, as an admin-opened ticket. WHMCS sends the client its "Support Ticket Opened by Admin" email; the module does not suppress it. The admin activity log gets `Chatwoot card: ticket #<tid> opened from chat #<number>`, without the text. A second press for the same conversation finds the existing ticket by its subject (merged tickets are skipped) and shows it instead of opening another. It is a check by subject, not a stored link: a ticket renamed in WHMCS, or two agents pressing at the same moment, can still give a second ticket; conversation numbers repeat across Chatwoot accounts, so with several accounts the subject can point at another account's chat.

**After.** The card shows the ticket number, a link to it in the admin area and the client's link (`viewticket.php?tid=…&c=…`) with a Copy button, for the agent to paste into the chat; the card posts nothing to Chatwoot.

**Checks on the server.** The request carries the WHMCS CSRF token and needs the admin session and Access Control, like the lookup. The server checks everything again: the identifier, the user's link to the account and its permission, the department against the setting, the admin's permission, the size of the request (2 MB, 5 000 messages) and every message's type and fields. No new tables.

Do not set `chatwootSettings.baseDomain` to share one widget session across subdomains: in SDK 4.19.1 `$chatwoot.reset()` removes the `cw_conversation` and user cookies without a domain, so a cookie set with `baseDomain` survives a logout.

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

Needs php 8.x, ruby and Playwright for Python with Chromium. The stand renders the real `hooks.php` with WHMCS stubs and opens it in Chromium with a fake SDK that records every call. The card (`app.php`) runs under `php -S` in a fake Chatwoot dashboard; tickets it opens are recorded by a stubbed `OpenTicket`. Set `CW_STAND_SHOTS=<dir>` to save screenshots of the card and the ticket form, light and dark.

## License

MIT (see [LICENSE](LICENSE)): © 2020 WevrLabs Group, © 2026 inSave Hosting OÜ.

Chatwoot is a trademark of its owner. This project is not affiliated with or endorsed by Chatwoot.
