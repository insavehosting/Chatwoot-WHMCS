# Changelog

## 2.0.4-insave.6 — 2026-10-30

- **WHMCS card on Chatwoot's main background** (`--background-color`, as in its sidebar), light and dark. Chatwoot 4.19.1 puts the iframe on a near-white pane, so the card used to sit white on white.

## 2.0.4-insave.5 — 2026-10-30

- **WHMCS card redesigned** on Chatwoot's own color tokens for light and dark: soft status pills, every text at 4.5:1 or more, the page follows Chatwoot's theme in every state and shows the dashboard's background through. A summary per account (credit, unpaid total, overdue invoices, open tickets), collapsible sections, rows that wrap on narrow panels.

## 2.0.4-insave.4 — 2026-10-30

- **WHMCS card in Chatwoot** (`app.php`, a Chatwoot Dashboard App): the client behind the contact of a conversation — account, credit, services, unpaid invoices with balance and overdue flag, open tickets, with links to the WHMCS admin area. Read-only. Requires a WHMCS admin session in the same browser and an admin role ticked in the addon's Access Control; the context is accepted only from the Chatwoot origin (new setting "Dashboard App Origin", default: the origin of the Chatwoot URL); `frame-ancestors` limits framing to that origin. Contacts with a `whmcs:u:<id>` identifier are matched by user, others by email and marked "not confirmed".
- **No money amounts from the widget**: `credit_balance`, `revenue`, `unpaid_amount` and `overdue_amount` are no longer sent; agents see amounts in the card.

## 2.0.4-insave.3 — 2026-10-30

- **Admin sessions.** With "Enable on Login as Client" on, the widget is shown while an admin is logged in to WHMCS in the same browser, but it is never identified: no `setUser`, no reset, the visitor label. Before, "Login as Client" identified the admin as the client, who could then chat and see history as that client. With the setting off there is still no widget in admin sessions.

## 2.0.4-insave.2 — 2026-10-30

Upgrading from 2.0.4 changes the contact identifier: see [docs/migrate-identifiers.md](docs/migrate-identifiers.md) before deploying.

- **The Chatwoot contact is the WHMCS user, not the client account.** The identifier is `whmcs:u:<tblusers.id>` and `identifier_hash` is its HMAC-SHA256 with the inbox secret. Before, users invited into someone else's account were identified as the account owner and saw the owner's conversations, and users without an account shared one contact. `setUser` sends the user's email and name and the account's company; a user with no account selected is not identified. New attributes: `whmcs_user_id`, `whmcs_client_id`, `whmcs_is_owner`.
- **Contacts from 2.0.4**:
  - `tools/clear-legacy-identifiers.rb` clears the old identifiers, so Chatwoot re-matches account owners by email on their first login;
  - or `tools/export-identifier-map.php` (read-only, on the WHMCS server) and `tools/migrate-identifiers.rb` (on the Chatwoot server) rename or merge contacts by a map, idempotent, with `DRY_RUN` and `REVERSE`.
- **Escaping.** Values reach the page only through `json_encode`, after HTML entities are decoded. `O'Brien` used to arrive as `&#039;`, and a trailing `\` or a `</script>` in any field broke the widget.
- **`current_page`** = SystemURL + route, without the query string. Routes with a token in the path (`/password/reset/redeem/…`, `/user/verify/…`, `/invite/…`) are cut to the route. The Host header is not used.
- **snake_case attribute keys.** The old keys with spaces are no longer sent; `deleteCustomAttribute('Test Attribute')` is gone.
- **Load.** Settings come from one query instead of 13. `GetClientsDetails` with stats is cached in the session for 5 minutes. Contact attributes are sent only when they change.
- **Page context in conversation attributes**:
  - service: `service_id`, `product`, `domain`, `service_status`;
  - invoice: `invoice_id`, `invoice_status`;
  - ticket: `ticket_tid`.

  Values are read from the database, and only for an object of the current client.
- **Widget locale** from an explicit table of WHMCS languages and Chatwoot 4.19.1 locales. Languages Chatwoot lacks get no `setLocale`.
- **`chatwoot_upgrade`** deletes the stale `chatwoot_jscode` setting.
- The logout hook is registered as `UserLogout` without reading the WHMCS version on every request. WHMCS 8 or later is required.
- Shown in WHMCS as **Chatwoot by inSave**, maintained by inSave Hosting OÜ. The author field no longer loads an image from a third-party site. The module's system name stays `chatwoot`, so its settings carry over.
- The module lives in `modules/addons/chatwoot/`, as in a WHMCS install: copy `modules/` into the WHMCS root.
- Local test stand: `python3 tests/stand/run.py`.

## fix-guest-reset — 2026-10-29

PR #2. The module version stayed 2.0.4.

- The widget is reset only after a logout or when another client is identified in the same browser. The reset on every `/login` and `/user/accounts` view is gone: a visitor chatting from the login page lost the conversation.
- `reset()` runs on `chatwoot:ready`, not before the SDK has loaded.
- A trailing slash in the Chatwoot URL is trimmed.

## 2.0.4 — 2024-12-24

WevrLabs release: https://github.com/WevrLabs-Group/Chatwoot-WHMCS/releases/tag/2.0.4
