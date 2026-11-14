# The WHMCS card: how it works

Reference for [the WHMCS card](../README.md#whmcs-card-in-chatwoot) and [Ticket from chat](../README.md#ticket-from-chat). Setup steps are in the README; this page is for whoever runs the servers or reviews the module.

`modules/addons/chatwoot/app.php` is a page for a Chatwoot Dashboard App. It shows the WHMCS client behind the contact of a conversation, in Chatwoot's light or dark theme: the account (name, company, status, client since, credit), services (product, domain, status, next due date), unpaid invoices with the balance left and an overdue flag, and open tickets, each linked to the WHMCS admin area. It writes nothing, except a ticket an agent opens from the conversation (off unless set up), and never writes to Chatwoot.

## Access

The card uses the WHMCS admin session of the browser the agent works in, plus the addon's Access Control. Chatwoot and WHMCS must be on the same site (e.g. `chat.example.com` and `billing.example.com`), otherwise the browser does not send the WHMCS session cookie into the iframe and the card asks to log in. Without a session, or for a role without access, the page returns no data.

## How it works

Chatwoot posts the conversation context to the iframe (`appContext`). The page accepts it only from the Dashboard App Origin (the setting, or the origin of the Chatwoot URL), then posts the contact's identifier and email back to `app.php` with the WHMCS CSRF token. A `whmcs:u:<id>` identifier was signed by the widget and identifies the user; a contact without one (Telegram, Meta, a visitor) is matched by email and marked "not confirmed". At most five client accounts are shown per contact.

## Web server

The page sends these headers itself:

- `Content-Security-Policy: frame-ancestors <Chatwoot origin>`: only your Chatwoot may frame the page;
- `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`;
- no `X-Frame-Options`.

If the web server adds `X-Frame-Options: SAMEORIGIN` to every response, browsers ignore it here because `frame-ancestors` is present (checked in Chromium, WebKit and Firefox); dropping it for this path is still cleaner. With nginx:

```nginx
map $uri $xfo { /modules/addons/chatwoot/app.php ""; default "SAMEORIGIN"; }
add_header X-Frame-Options $xfo;   # an empty value sends no header
```

A second `Content-Security-Policy` header with its own `frame-ancestors`, added by the web server for the whole site, is different: browsers enforce every policy they get, so `frame-ancestors 'self'` there blocks the card. Leave that header out for `/modules/addons/chatwoot/app.php`, or add your Chatwoot origin to it.

Do not set `chatwootSettings.baseDomain` to share one widget session across subdomains: in SDK 4.19.1 `$chatwoot.reset()` removes the `cw_conversation` and user cookies without a domain, so a cookie set with `baseDomain` survives a logout.

## Ticket from chat

**Setup.** Fill in **Ticket departments**: each Chatwoot team goes to a WHMCS department, `*` is a conversation without a team, e.g. `sales=2,support=3,*=1`. A department ID that does not exist is ignored and named in the card. The admin needs WHMCS's own **Open New Ticket** permission (Administrator Roles), checked with `WHMCS\User\Admin::hasPermission()`, besides the addon's Access Control.

**When the button is there.** On an account in the card, when all of these hold:

- the contact is a signed-in WHMCS user (identifier `whmcs:u:<id>`). A contact matched only by email gets no button and a hint: merge it in Chatwoot with the contact of the signed-in user first (Telegram and Meta contacts are like that until merged);
- the user owns the account, or is invited with the **tickets** permission;
- the admin has Open New Ticket.

**The form.** Department (the one mapped to the conversation's team), subject `Chat #<conversation number>: ` followed by the start of the client's first message (editable), priority (Medium), and how many messages will go in. **Create ticket** stays disabled until Chatwoot has loaded the conversation from its first message: Chatwoot loads a conversation page by page and does not post the context again when older messages load, so scroll the conversation up to the start and press Refresh in the card (the open form and what was typed in it are kept).

**What goes into the ticket.** A plain-text transcript (Markdown off): a header `Chat transcript #<number>, <first> – <last> UTC`, then `[YYYY-MM-DD HH:MM UTC] <name>: <text>` per message. Only public messages of the client and the agents; private notes, activity and template messages (greeting, out of office, email request, CSAT) stay out, and so do Chatwoot's translations. Attachments are named, never linked: Chatwoot's file links are signed and open the file to anyone who has them. The ticket message is a `TEXT` column in WHMCS (65 535 bytes), so the transcript is kept under 60 000 bytes: the oldest messages are left out and the header says how many. The card sends at most the newest 2 000 messages (about 400 KB) and tells the server how many older ones it left out. Characters outside the Basic Multilingual Plane (emoji) become `�`: WHMCS's ticket tables are often `utf8` (3 bytes), where such a character silently cuts off the rest of the text.

**What WHMCS does.** `OpenTicket` with the client, the user (`userid`, WHMCS 8.5+), the department, the subject and priority, as an admin-opened ticket. WHMCS sends the client its "Support Ticket Opened by Admin" email; the module does not suppress it. The admin activity log gets `Chatwoot card: ticket #<tid> opened from chat #<number>`, without the text. A second press for the same conversation finds the existing ticket by its subject (merged tickets are skipped) and shows it instead of opening another. It is a check by subject, not a stored link: a ticket renamed in WHMCS, or two agents pressing at the same moment, can still give a second ticket; conversation numbers repeat across Chatwoot accounts, so with several accounts the subject can point at another account's chat.

**After.** The card shows the ticket number, a link to it in the admin area and the client's link (`viewticket.php?tid=…&c=…`) with a Copy button, for the agent to paste into the chat; the card posts nothing to Chatwoot.

**Checks on the server.** WHMCS escapes every request variable before a module runs, so the card sends the conversation and the subject base64-encoded. The request carries the WHMCS CSRF token and needs the admin session and Access Control, like the lookup. The server checks everything again: the identifier, the user's link to the account and its permission, the department against the setting, the admin's permission, the size of the request (600 KB, 5 000 messages) and every message's type and fields. WHMCS keeps ticket text HTML-encoded (its own forms arrive escaped) and prints it as stored, so the subject and the transcript are HTML-encoded before `OpenTicket` — markup a client typed in the chat shows as text — and the 60 000-byte limit is counted on the encoded text. No new tables.
