# Development

## What is in the repository

| Path | What it is | Goes to the server |
|---|---|---|
| `modules/addons/chatwoot/chatwoot.php` | addon configuration, `chatwoot_upgrade` | yes, same path under the WHMCS root |
| `modules/addons/chatwoot/hooks.php` | the widget (`ClientAreaFooterOutput`), the reset flag on logout (`UserLogout`) | yes, same path under the WHMCS root |
| `modules/addons/chatwoot/app.php` | the WHMCS card for a Chatwoot Dashboard App | yes, same path under the WHMCS root |
| `tools/build-release.sh` | builds the release zip | no |
| `tools/clear-legacy-identifiers.rb` | upgrading from 2.0.4: clears the old identifiers in Chatwoot (rails runner) | only while upgrading |
| `tools/export-identifier-map.php`, `tools/migrate-identifiers.rb` | upgrading from 2.0.4: maps old contacts to the new identifiers instead | only while upgrading, outside the web root |
| `docs/` | reference pages and screenshots | no |
| `tests/stand/` | local test stand | no |

Settings are stored in `tbladdonmodules` under the module name `chatwoot`, with the keys of `chatwoot_config()` (`chatwoot_url`, `chatwoot_token`, `chatwoot_verhash`, …). The module creates no tables.

## Testing without a live WHMCS

```bash
python3 tests/stand/run.py
# PASS: the last line is ALL PASS
```

Needs php 8.x, ruby and Playwright for Python with Chromium. The stand renders the real `hooks.php` with WHMCS stubs and opens it in Chromium with a fake SDK that records every call. The card (`app.php`) runs under `php -S` in a fake Chatwoot dashboard; tickets it opens are recorded by a stubbed `OpenTicket`. Set `CW_STAND_SHOTS=<dir>` to save screenshots of the card and the ticket form, light and dark; the images in `docs/images/` are cut from them.

Two checks compare the module with the 2.0.4 widget and need the upstream tag in the clone:

```bash
git fetch https://github.com/WevrLabs-Group/Chatwoot-WHMCS tag 2.0.4
```

The stand also checks that the module version and the md5 table in [deploy.md](deploy.md) match the files.

## Releasing

1. Set the version in `chatwoot_config()` and in the header of `chatwoot.php`, add the CHANGELOG entry, update the md5 table and the version in [deploy.md](deploy.md) and the version check in `tests/stand/run.py`.
2. Run the stand: `ALL PASS`.
3. Build the zip:

   ```bash
   tools/build-release.sh
   # dist/chatwoot-by-insave-<version>.zip and dist/chatwoot-by-insave.zip (the same file)
   ```
4. Tag the commit with the version, create a GitHub release from the tag and attach **both** zip files. The README and the WHMCS Marketplace listing link to `releases/latest/download/chatwoot-by-insave.zip`, so every release needs the file under that name.
