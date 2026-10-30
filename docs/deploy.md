# Upgrading to 2.0.4-insave.5

From 2.0.4-insave.4: replace `chatwoot.php` and `app.php` (md5 below; `hooks.php` is unchanged) and open WHMCS Admin → Setup → Addon Modules. From 2.0.4-insave.2 or .3: put the three files in place, open Setup → Addon Modules, then set up the WHMCS card (README, "WHMCS card in Chatwoot"): Access Control roles, the Dashboard App in Chatwoot. From 2.0.4: the whole page applies.

## Files

Three files go to the server, `modules/addons/chatwoot/chatwoot.php`, `hooks.php` and `app.php`, into the same path under the WHMCS root:

| File | md5 of 2.0.4-insave.5 |
|---|---|
| `chatwoot.php` | `f85833a93b18a48bef0ffda61cfe5fdb` |
| `hooks.php` | `6af44aac22868c7b1aa068cb79a28846` |
| `app.php` | `6dc16caf412d1cd621f3dc9a4c96f969` |

What may be there now (keep a copy for a rollback):

| File | Version | md5 |
|---|---|---|
| `chatwoot.php` | 2.0.4 | `a3a63c891d6ff25c87869679a0496b75` |
| `hooks.php` | 2.0.4 (upstream tag `2.0.4`) | `440870a44a9c4ab7ad3fcd6cfa07c861` |
| `hooks.php` | 2.0.4 + the reset fix (branch `fix-guest-reset`, `17d5e6d`) | `fcd926d8d6e4570b91a7de63e838362b` |
| `chatwoot.php` | 2.0.4-insave.4 | `494a0c12c58f1c7f9f4ec8a66f718e13` |
| `app.php` | 2.0.4-insave.4 | `09053e17100c5190cc2e8fce48061975` |
| `chatwoot.php` | 2.0.4-insave.3 | `f8906396cf231a40e47aa117a2b55980` |
| `hooks.php` | 2.0.4-insave.3 | `1f3f8e51fcb9e91f172503d80ee67b37` |
| `chatwoot.php` | 2.0.4-insave.2 | `d003f4243cd579d3bd246b35bfae2503` |
| `chatwoot.php` | 2.0.4-insave.2 before the branding, shown as "Chatwoot" | `0b2c0c93b914cf0ef19f233e60a9bd9d` |
| `hooks.php` | 2.0.4-insave.2 | `03243043bcdcc0269357ec10f073e193` |

2.0.4-insave.2 and later contain the reset fix from `fix-guest-reset`.

## Before you start

1. **Enforce user identity validation** is on in the Chatwoot website inbox.
2. Decide what happens to the contacts 2.0.4 created: [migrate-identifiers.md](migrate-identifiers.md), way A (clear, after the upload) or way B (map, before the upload).
3. The files come from the release and their md5 match the table above:

   ```bash
   md5sum modules/addons/chatwoot/*.php
   # PASS: f85833a9… chatwoot.php, 6af44aac… hooks.php, 6dc16caf… app.php
   ```

## Order

1. Way B only: the map and the migration, [migrate-identifiers.md](migrate-identifiers.md) B.1–B.2.
2. **WHMCS server — files.** Copy the current files outside the web root (a copy inside the addon directory would be served as plain text), then put the new ones in place:

   ```bash
   mkdir -p ~/chatwoot-backup-$(date +%Y%m%d)
   cp -p <WHMCS>/modules/addons/chatwoot/*.php ~/chatwoot-backup-$(date +%Y%m%d)/
   # then copy modules/ from the release into <WHMCS>/ (only the two module files)
   md5sum <WHMCS>/modules/addons/chatwoot/*.php
   # PASS: the md5 from "Files"
   ```
3. **WHMCS Admin → Setup → Addon Modules.** Opening the page is enough: WHMCS sees the new version and calls `chatwoot_upgrade`. The addon is now listed as "Chatwoot by inSave".

   ```sql
   SELECT setting, LEFT(value, 20) FROM tbladdonmodules WHERE module = 'chatwoot' AND setting IN ('version', 'chatwoot_jscode')
   -- PASS: one row, version = 2.0.4-insave.5; no chatwoot_jscode row
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

## Rollback

1. Put back the files from `~/chatwoot-backup-<date>/` (2.0.4, or 2.0.4 + `fix-guest-reset`) and check their md5 against the table.
2. Way B: map the contacts back with `REVERSE=1` ([migrate-identifiers.md](migrate-identifiers.md), B, Rollback). Way A: nothing to undo on the Chatwoot side. 2.0.4 identifies users by its own identifiers again and creates contacts for them.
3. Do not restore `chatwoot_jscode`: no version since 2.0.1 reads it.
