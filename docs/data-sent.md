# What the widget sends to Chatwoot

Reference for the [Privacy](../README.md#privacy) section of the README: every field the module puts into your Chatwoot, when the widget is reset, and what a page view costs.

Everything is set from the visitor's browser. Only the identifier is signed (HMAC): with identity validation enforced it cannot be forged. Attributes and labels can be changed by the client from the browser console, so do not base decisions about money or access on them.

## Visitors, users with no account selected, admin sessions

- label `chatwoot_setlabel`, locale (with Dynamic Language);
- contact attribute `current_page`.

No `setUser`. For a user without a selected account (e.g. on `/user/accounts`) and in an admin session (with "Enable on Login as Client" on) the widget is not reset either.

## A user in a client account

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

No money amounts are sent: everything here is in the page source for every user of the account, invited users included. Agents see amounts in [the WHMCS card](whmcs-card.md).

Labels: `chatwoot_setlabelloggedin` is set, `chatwoot_setlabel` removed.

Conversation attributes (`setConversationCustomAttributes`), on three pages only and only for an object of the current account (checked in the database):

| Page | Keys |
|---|---|
| service (`clientareaproductdetails`) | `service_id`, `product`, `domain`, `service_status` |
| invoice (`viewinvoice`) | `invoice_id`, `invoice_status` |
| ticket (`viewticket`) | `ticket_tid` |

Chatwoot replaces the conversation's attributes with this set as a whole; with no conversation yet, the widget keeps the set and sends it with the first message. Do not put other attributes on conversations of this inbox by hand: the next such page overwrites them.

## `current_page`

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
