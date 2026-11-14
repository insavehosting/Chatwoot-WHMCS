# Upgrading to 3.0.0

3.0.0 is 2.0.4-insave.8 under its own version number: `hooks.php` and `app.php` are the same files, `chatwoot.php` has the new version and clearer setting descriptions.

- **From 2.0.4-insave.8:** replace `chatwoot.php` (md5 below) and open the Addon Modules page in the WHMCS admin area; the settings stay.
- **From 2.0.4-insave.7:** replace `chatwoot.php` and `app.php` and open Addon Modules. 2.0.4-insave.7 could not open a ticket on a real WHMCS ("The conversation could not be read").
- **From 2.0.4-insave.4, .5 or .6:** replace `chatwoot.php` and `app.php` and open Addon Modules. To let agents open tickets from chats, then fill in **Ticket departments** and check the admin roles' **Open New Ticket** permission ([README, "Ticket from chat"](../README.md#ticket-from-chat)); left empty, the card stays as it was.
- **From 2.0.4-insave.2 or .3:** put the three files in place, open Addon Modules, then set up the WHMCS card ([README, "WHMCS card in Chatwoot"](../README.md#whmcs-card-in-chatwoot)): Access Control roles, the Dashboard App in Chatwoot.
- **From Chatwoot-WHMCS 2.0.4 by WevrLabs:** the whole page applies.

## Files

Three files go to the server, `modules/addons/chatwoot/chatwoot.php`, `hooks.php` and `app.php`, into the same path under the WHMCS root:

| File | md5 of 3.0.0 |
|---|---|
| `chatwoot.php` | `83587e8d9fbb4dc41c0d74f3a8978ae5` |
| `hooks.php` | `6af44aac22868c7b1aa068cb79a28846` |
| `app.php` | `b3d0ffe72a3551abc49d1a24242e6108` |

What may be there now (keep a copy for a rollback):

| File | Version | md5 |
|---|---|---|
| `chatwoot.php` | 2.0.4 | `a3a63c891d6ff25c87869679a0496b75` |
| `hooks.php` | 2.0.4 (upstream tag `2.0.4`) | `440870a44a9c4ab7ad3fcd6cfa07c861` |
| `hooks.php` | 2.0.4 + the reset fix (branch `fix-guest-reset`, `17d5e6d`) | `fcd926d8d6e4570b91a7de63e838362b` |
| `chatwoot.php` | 2.0.4-insave.8 | `8e6ec27ad02b9732e1a0b08a9577efd8` |
| `chatwoot.php` | 2.0.4-insave.7 | `712d9206271bdff8e7a642d29335f346` |
| `app.php` | 2.0.4-insave.7 | `548f9d15c43b3d64e9a2298db28db943` |
| `chatwoot.php` | 2.0.4-insave.6 | `ffe55a5dcd4defae97d892f674ba7846` |
| `app.php` | 2.0.4-insave.6 | `9450aa5d08b55ec65efbc3e338d311b1` |
| `chatwoot.php` | 2.0.4-insave.5 | `f85833a93b18a48bef0ffda61cfe5fdb` |
| `app.php` | 2.0.4-insave.5 | `6dc16caf412d1cd621f3dc9a4c96f969` |
| `chatwoot.php` | 2.0.4-insave.4 | `494a0c12c58f1c7f9f4ec8a66f718e13` |
| `app.php` | 2.0.4-insave.4 | `09053e17100c5190cc2e8fce48061975` |
| `chatwoot.php` | 2.0.4-insave.3 | `f8906396cf231a40e47aa117a2b55980` |
| `hooks.php` | 2.0.4-insave.3 | `1f3f8e51fcb9e91f172503d80ee67b37` |
| `chatwoot.php` | 2.0.4-insave.2 | `d003f4243cd579d3bd246b35bfae2503` |
| `chatwoot.php` | 2.0.4-insave.2 before the branding, shown as "Chatwoot" | `0b2c0c93b914cf0ef19f233e60a9bd9d` |
| `hooks.php` | 2.0.4-insave.2 | `03243043bcdcc0269357ec10f073e193` |

2.0.4-insave.2 and later, and 3.0.0, contain the reset fix from `fix-guest-reset`.

## Before you start

1. **Enforce user identity validation** is on in the Chatwoot website inbox.
2. Decide what happens to the contacts 2.0.4 created: [migrate-identifiers.md](migrate-identifiers.md), way A (clear, after the upload) or way B (map, before the upload).
3. The files come from the release and their md5 match the table above:

   ```bash
   md5sum modules/addons/chatwoot/*.php
   # PASS: 83587e8d… chatwoot.php, 6af44aac… hooks.php, b3d0ffe7… app.php
   ```

## Order

1. Way B only: the map and the migration, [migrate-identifiers.md](migrate-identifiers.md) B.1–B.2.
2. **WHMCS server — files.** Copy the current files outside the web root (a copy inside the addon directory would be served as plain text), then put the new ones in place:

   ```bash
   mkdir -p ~/chatwoot-backup-$(date +%Y%m%d)
   cp -p <WHMCS>/modules/addons/chatwoot/*.php ~/chatwoot-backup-$(date +%Y%m%d)/
   # then copy modules/ from the release zip into <WHMCS>/
   md5sum <WHMCS>/modules/addons/chatwoot/*.php
   # PASS: the md5 from "Files"
   ```
3. **WHMCS Admin → Setup → Addon Modules.** Opening the page is enough: WHMCS sees the new version and calls `chatwoot_upgrade`. The addon is now listed as "Chatwoot by inSave".

   ```sql
   SELECT setting, LEFT(value, 20) FROM tbladdonmodules WHERE module = 'chatwoot' AND setting IN ('version', 'chatwoot_jscode')
   -- PASS: one row, version = 3.0.0; no chatwoot_jscode row
   ```
4. **Chatwoot server, right away:**
   - way A: clear the old identifiers, [migrate-identifiers.md](migrate-identifiers.md) A;
   - way B: run the migration once more, B.2.

## Checks after the upgrade

Use a private browser window with the console open.

1. **Visitor.** Open `/login`, accept cookies if there is a banner, reload twice. PASS: no JS errors, the `cw_conversation` cookie does not change.
2. **Client.** Log in as a test client and send a chat message. PASS:
   - searching Chatwoot for `whmcs:u:<user ID>` finds the contact, and it has an email;
   - its attributes include `whmcs_user_id`, `whmcs_client_id` and `current_page` without a query string.
3. **Service page** (`clientarea.php?action=productdetails&id=…`), then a chat message. PASS: the conversation has `service_id`, `product`, `domain`, `service_status`.
4. **Logout.** PASS: the next page starts a new chat session, the client's conversation is not in the window.
5. **Invited user** (if there is a test one). PASS: their own contact `whmcs:u:<their ID>` in Chatwoot, no access to the owner's conversations.
6. **Password reset.** Open a reset link from the email. PASS: `current_page` in Chatwoot is `<SystemURL>password/reset/redeem`, without the token.
7. **Ticket from chat** (with Ticket departments set). In Chatwoot, open a test client's conversation (a signed-in user, contact `whmcs:u:<ID>`), scroll it up to the first message, press Refresh in the card, then Open ticket → Create ticket. PASS:
   - a ticket under that client, from that user, in the department of the conversation's team, subject `Chat #<number>: …`;
   - its message is the transcript: client and agent messages only, no private notes, attachments by name only;
   - the client gets the "Support Ticket Opened by Admin" email, and the admin activity log has `Chatwoot card: ticket #… opened from chat #…`;
   - pressing Create ticket again shows the same ticket instead of opening a second one.

## Rollback

From 3.0.0 to 2.0.4-insave.8: put back `chatwoot.php` from the backup and open Addon Modules.

From 3.0.0, 2.0.4-insave.8 or .7 to .6: put back `chatwoot.php` and `app.php` from the backup and open Addon Modules. The Ticket departments value stays in `tbladdonmodules` and is not read by .6; tickets opened from chats stay in WHMCS.

From 3.0.0 or any 2.0.4-insave version to 2.0.4:

1. Put back the files from `~/chatwoot-backup-<date>/` (2.0.4, or 2.0.4 + `fix-guest-reset`) and check their md5 against the table.
2. Way B: map the contacts back with `REVERSE=1` ([migrate-identifiers.md](migrate-identifiers.md), B, Rollback). Way A: nothing to undo on the Chatwoot side. 2.0.4 identifies users by its own identifiers again and creates contacts for them.
3. Do not restore `chatwoot_jscode`: no version since 2.0.1 reads it.
