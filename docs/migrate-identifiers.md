# Contacts created by 2.0.4

2.0.4 identified a logged-in user by the HMAC-SHA256 of the **client account** ID. From 2.0.4-insave.2 the identifier is the **user**: `whmcs:u:<tblusers.id>`.

If nothing is done, the old contacts keep their old identifiers and the account email. When the new module identifies a user, Chatwoot finds that email on a contact with a different identifier and refuses to merge (`ContactIdentifyAction#merge_contacts?` in v4.19.1). As a result:

- the new contact is created **without an email**;
- the person ends up with two contacts.

Pick one of the two ways below. Both run with rails runner on the Chatwoot server, from the compose directory (Compose v2, `docker compose`). Back up the Chatwoot Postgres database first (`pg_dump`).

`<WEBSITE_TOKEN>` is the website inbox token: Chatwoot → Inboxes → the WHMCS website inbox → Configuration. It is also the module's "Website Widget Token" setting.

## A. Clear the old identifiers (no map needed)

Use this when the old conversations do not need to follow anyone exactly. The script clears the 2.0.4 identifiers: 64 hex characters, on contacts of the website inbox only. With the identifier gone, Chatwoot matches the contact by email the first time the user logs in:

- an account owner whose user email equals the old contact's email gets the old contact, with its history, under the new identifier;
- everyone else (invited users, owners whose login email differs from the account email) gets a new contact.

Run it **right after** uploading the new module files. If it runs while 2.0.4 is still live, 2.0.4 writes the old identifiers back on the next page view.

```bash
docker compose cp clear-legacy-identifiers.rb rails:/tmp/clear-legacy-identifiers.rb
docker compose exec -T -e CW_WEBSITE_TOKEN=<WEBSITE_TOKEN> \
  rails bundle exec rails runner /tmp/clear-legacy-identifiers.rb
# PASS: "DRY RUN, nothing changed: <N> contact(s) with a 2.0.4 identifier"
docker compose exec -T -e CW_WEBSITE_TOKEN=<WEBSITE_TOKEN> -e APPLY=1 \
  rails bundle exec rails runner /tmp/clear-legacy-identifiers.rb
# PASS: "cleared <N>"; run it again without APPLY: 0 contact(s)
docker compose exec rails rm /tmp/clear-legacy-identifiers.rb
```

## B. Migrate by a map

Use this to keep each account's history on the owner's new contact regardless of email. It is done **before** uploading the module.

| In 2.0.4 | After the migration |
|---|---|
| The account's contact (HMAC of the client ID): the owner's and all invited users' conversations | The **owner's** contact, `whmcs:u:<owner ID>`, with all of that history |
| An invited user chatted as the owner | Their own contact from now on; their old messages stay with the owner (2.0.4 had one contact, it cannot be split) |
| Users without an account: one shared contact (HMAC of an empty ID) | Not mapped. The export prints its identifier (`not mapped …`); leave it or rename it by hand |

### 1. WHMCS server: the map

Read-only: the inbox secret and `tblusers_clients` (`owner = 1`). Run it with the PHP that runs the WHMCS cron (ionCube), from a directory **outside** the web root:

```bash
cd ~ && php export-identifier-map.php /path/to/whmcs > identifier-map.csv
# PASS: stderr "<N> rows" (N = number of client accounts) and a "not mapped" line
head -2 identifier-map.csv
# PASS: old_identifier,new_identifier and a line <64 hex>,whmcs:u:<number>
```

### 2. Chatwoot server: dry run, then migrate

```bash
docker compose cp identifier-map.csv rails:/tmp/identifier-map.csv
docker compose cp migrate-identifiers.rb rails:/tmp/migrate-identifiers.rb
docker compose exec -T -e CW_WEBSITE_TOKEN=<WEBSITE_TOKEN> -e CW_MAP=/tmp/identifier-map.csv -e DRY_RUN=1 \
  rails bundle exec rails runner /tmp/migrate-identifiers.rb
# PASS: "DRY RUN, nothing changed: renamed=… merged=… missing=… (already migrated: …) invalid=0"
docker compose exec -T -e CW_WEBSITE_TOKEN=<WEBSITE_TOKEN> -e CW_MAP=/tmp/identifier-map.csv \
  rails bundle exec rails runner /tmp/migrate-identifiers.rb
# PASS: renamed + merged as in the dry run, invalid=0
```

What the counts mean:

- `renamed` — the contact gets the new identifier and keeps its history;
- `merged` — a contact with the new identifier already exists (the user chatted after the upgrade). It is merged into the old contact (`ContactMergeAction`, base = the old one, with the history), which then takes the new identifier;
- `missing` — no old contact (the client never chatted). `already migrated` counts the ones whose new identifier exists;
- `invalid` — must be 0. Otherwise the CSV is damaged; run nothing.

Upload the module ([deploy.md](deploy.md)). Then run the migration once more: it merges contacts that 2.0.4 created in between. After that, delete the CSV and the script on both servers.

### Rollback

When going back to 2.0.4, map the contacts back, or 2.0.4 will not find them:

```bash
docker compose exec -T -e CW_WEBSITE_TOKEN=<WEBSITE_TOKEN> -e CW_MAP=/tmp/identifier-map.csv -e REVERSE=1 \
  rails bundle exec rails runner /tmp/migrate-identifiers.rb
# PASS: "REVERSE renamed=…", invalid=0
```

Contacts of invited users (`whmcs:u:<ID>` with no row in the map) stay as they are; 2.0.4 does not use them. A full rollback of the database is the backup.
