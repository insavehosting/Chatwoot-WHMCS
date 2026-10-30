#!/usr/bin/env python3
"""Stand for the Chatwoot module: WHMCS stubs + PHP built-in server + Chromium
with a fake Chatwoot SDK. Prints PASS/FAIL per check, exits 1 on any FAIL.

    python3 tests/stand/run.py

Needs php (8.x), ruby and Playwright for Python with Chromium.
"""
import hashlib
import hmac
import json
import os
import re
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request
from pathlib import Path

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
STAND = ROOT / 'tests' / 'stand'
SECRET = 'stand-identity-secret'  # CW_STAND_SECRET in bootstrap.php
TOKEN = 'standWebsiteToken'
failed = []


def check(name, ok, detail=''):
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok or not detail else f'  -- {detail}'))
    if not ok:
        failed.append(name)


def free_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


def sh(*cmd, **kw):
    return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, **kw)


# --- CLI checks -------------------------------------------------------------

def cli_checks():
    for f in ('chatwoot/chatwoot.php', 'chatwoot/hooks.php', 'tools/export-identifier-map.php'):
        r = sh('php', '-l', f)
        check(f'php -l {f}', r.returncode == 0 and 'No syntax errors detected' in r.stdout, r.stdout + r.stderr)

    hooks = (ROOT / 'chatwoot/hooks.php').read_text()
    hits = [l for l in hooks.splitlines() if re.search(r"'\$[A-Za-z_]+'", l)]
    check("no '$var' inside JS strings in hooks.php", not hits, '; '.join(hits))
    check('hooks.php does not read HTTP_HOST', 'HTTP_HOST' not in hooks)

    r = sh('php', 'tests/stand/current_page_test.php')
    check('current_page cases (tests/stand/current_page_test.php)', r.returncode == 0, r.stdout)

    upgrade = sh('php', '-r', '''
        require "tests/stand/bootstrap.php"; cw_stand_boot("guest", "x"); require "chatwoot/chatwoot.php";
        chatwoot_upgrade(["version" => "2.0.4"]);
        echo json_encode(CwStand::$db["tbladdonmodules"]);''')
    rows = json.loads(upgrade.stdout or '[]')
    check('chatwoot_upgrade removes chatwoot_jscode of this module only',
          not any(r['module'] == 'chatwoot' and r['setting'] == 'chatwoot_jscode' for r in rows)
          and any(r['module'] == 'other_module' for r in rows)
          and sum(r['module'] == 'chatwoot' for r in rows) == 14, upgrade.stdout + upgrade.stderr)

    version = sh('php', '-r', 'define("WHMCS", 1); require "chatwoot/chatwoot.php"; echo chatwoot_config()["version"];')
    check('module version is 2.0.4-insave.2', version.stdout == '2.0.4-insave.2', version.stdout)

    export = sh('php', 'tools/export-identifier-map.php', 'tests/stand/fake-whmcs')
    rows = dict(reversed(line.split(',')) for line in export.stdout.split()[1:])
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False) as old_hooks:
        old_hooks.write(sh('git', 'show', '2.0.4:chatwoot/hooks.php').stdout)
    for scenario, user in (('owner', 'whmcs:u:10'), ('tricky', 'whmcs:u:30')):
        out = sh('php', 'tests/stand/render.php', old_hooks.name, scenario).stdout
        m = re.search(r"setUser\('([0-9a-f]{64})'", out)
        check(f'export old_identifier = 2.0.4 hook identifier ({scenario})',
              m is not None and rows.get(user) == m.group(1), f'csv={rows.get(user)} hook={m and m.group(1)}')
    os.unlink(old_hooks.name)
    web = sh('php-cgi', '-q', 'tools/export-identifier-map.php') if sh('which', 'php-cgi').returncode == 0 else None
    if web is not None:
        check('export refuses to run outside the CLI', 'old_identifier' not in web.stdout, web.stdout[:200])

    r = sh('ruby', 'tests/stand/migrate_test.rb')
    check('migrate-identifiers.rb (tests/stand/migrate_test.rb)', r.returncode == 0, r.stdout + r.stderr)

    r = sh('ruby', 'tests/stand/clear_test.rb')
    check('clear-legacy-identifiers.rb (tests/stand/clear_test.rb)', r.returncode == 0, r.stdout + r.stderr)


# --- Browser checks -----------------------------------------------------------

class Stand:
    def __init__(self):
        self.port = free_port()
        self.id = f'run{os.getpid()}'
        self.counter = Path(tempfile.gettempdir()) / f'cw-stand-api-{self.id}'
        self.counter.unlink(missing_ok=True)
        env = dict(os.environ, CW_STAND_ID=self.id)
        self.proc = subprocess.Popen(
            ['php', '-S', f'127.0.0.1:{self.port}', str(STAND / 'router.php')],
            cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        self.base = f'http://127.0.0.1:{self.port}'
        for _ in range(50):
            try:
                urllib.request.urlopen(self.base + '/stand/state', timeout=1)
                break
            except OSError:
                time.sleep(0.1)

    def api_calls(self):
        return json.load(urllib.request.urlopen(self.base + '/stand/state'))['apiCalls']

    def stop(self):
        self.proc.terminate()
        self.proc.wait()
        self.counter.unlink(missing_ok=True)


class Visitor:
    """One browser profile: cookies and localStorage persist across visits."""

    def __init__(self, browser, stand, scenario, **cookies):
        self.stand = stand
        self.context = browser.new_context()
        self.errors = []
        self.set(stand_scenario=scenario, **cookies)

    def set(self, **cookies):
        self.context.add_cookies([
            {'name': k, 'value': str(v), 'url': self.stand.base} for k, v in cookies.items()])

    def visit(self, path):
        page = self.context.new_page()
        page.on('pageerror', lambda e: self.errors.append(str(e)))
        page.on('console', lambda m: m.type == 'error' and self.errors.append(m.text))
        page.goto(self.stand.base + path)
        page.wait_for_function('window.__cw && window.__cw.readyFired === true', timeout=5000)
        calls = page.evaluate('window.__cw.calls')
        run = page.evaluate('window.__cw.run')
        page.close()
        return Calls(calls, run)

    def close(self):
        self.context.close()


class Calls:
    def __init__(self, calls, run):
        self.calls = calls
        self.run = run

    def names(self):
        return [c['name'] for c in self.calls]

    def args(self, name):
        return [c['args'] for c in self.calls if c['name'] == name]

    def first(self, name):
        found = self.args(name)
        return found[0] if found else None


def browser_checks(browser, stand):
    def hmac_of(identifier):
        return hmac.new(SECRET.encode(), identifier.encode(), hashlib.sha256).hexdigest()

    # guest
    v = Visitor(browser, stand, 'guest')
    c = v.visit('/login')
    check('guest: setUser not called', 'setUser' not in c.names(), c.names())
    check('guest: label visitor, locale en, current_page sent',
          c.first('setLabel') == ['visitor'] and c.first('setLocale') == ['en']
          and c.first('setCustomAttributes') == [{'current_page': 'https://billing.example.test/login'}], c.calls)
    check('guest: widget settings and token come through',
          c.run['settings'] == {'position': 'right', 'type': 'expanded_bubble', 'launcherTitle': '',
                                'darkMode': 'auto', 'locale': 'en'}
          and c.run['options'] == {'websiteToken': TOKEN, 'baseUrl': stand.base}, c.run)
    again = [v.visit('/login') for _ in range(2)]
    check('guest: /login reloads never reset the widget',
          all('reset' not in x.names() for x in [c] + again), [x.names() for x in again])
    check('guest: unchanged attributes are not sent again', 'setCustomAttributes' not in again[-1].names(),
          again[-1].names())
    c = v.visit('/index.php?rp=/password/reset/redeem/9f8e7d6c5b4a3928TOKEN172635445')
    page_attr = (c.first('setCustomAttributes') or [{}])[0].get('current_page', '')
    check('password reset: no token in current_page',
          page_attr == 'https://billing.example.test/password/reset/redeem' and 'TOKEN' not in json.dumps(c.calls),
          page_attr)
    check('guest: no JS errors', not v.errors, v.errors)
    v.close()

    # owner
    before = stand.api_calls()
    v = Visitor(browser, stand, 'owner')
    first = v.visit('/clientarea.php')
    user = first.first('setUser')
    check('owner: identifier whmcs:u:10 with a valid HMAC',
          user is not None and user[0] == 'whmcs:u:10' and user[1]['identifier_hash'] == hmac_of('whmcs:u:10'), user)
    check("owner: the user's own email and name, the account's company",
          user is not None and user[1]['email'] == 'owner@example.test' and user[1]['name'] == 'Olga Owner'
          and user[1]['company_name'] == 'Acme', user)
    attrs = (first.first('setCustomAttributes') or [{}])[0]
    check('owner: attributes whmcs_user_id / whmcs_client_id / whmcs_is_owner, snake_case keys',
          attrs.get('whmcs_user_id') == 10 and attrs.get('whmcs_client_id') == 100 and attrs.get('whmcs_is_owner') is True
          and all(re.fullmatch(r'[a-z_]+', k) for k in attrs) and attrs.get('active_tickets') == 2
          and attrs.get('email_verified') is True, attrs)
    check('owner: no deleteCustomAttribute, label client, visitor label removed',
          'deleteCustomAttribute' not in first.names() and first.first('setLabel') == ['client']
          and first.first('removeLabel') == ['visitor'], first.names())
    second = v.visit('/clientarea.php')
    third = v.visit('/clientarea.php')
    check('repeat visit: setUser skipped by the SDK, attributes resent once after identification',
          'setUser' not in second.names() and 'setCustomAttributes' in second.names(), second.names())
    check('repeat visit without changes: setCustomAttributes not called',
          'setCustomAttributes' not in third.names() and 'setUser' not in third.names(), third.names())
    same = v.visit('/clientarea.php?action=services')
    check('same route with another query string: current_page unchanged, nothing sent',
          'setCustomAttributes' not in same.names(), same.names())
    other = v.visit('/cart.php')
    check('another page: current_page changed, attributes sent',
          (other.first('setCustomAttributes') or [{}])[0].get('current_page') == 'https://billing.example.test/cart.php',
          other.calls)
    check('GetClientsDetails called once for five pages (5-minute session cache)',
          stand.api_calls() - before == 1, stand.api_calls() - before)

    c = v.visit('/clientarea.php?action=productdetails&id=500')
    check('productdetails: conversation attributes with service_id',
          c.first('setConversationCustomAttributes') == [{'service_id': 500, 'product': 'Starter Hosting',
                                                          'domain': 'acme.example', 'service_status': 'Active'}],
          c.calls)
    check('productdetails: the contact attributes carry no page context',
          'service_id' not in json.dumps(c.args('setCustomAttributes')), c.args('setCustomAttributes'))
    c = v.visit('/clientarea.php?action=productdetails&id=501')
    check("productdetails of another client's service: no conversation attributes",
          'setConversationCustomAttributes' not in c.names(), c.calls)
    c = v.visit('/viewinvoice.php?id=700')
    check('viewinvoice: invoice_id and invoice_status',
          c.first('setConversationCustomAttributes') == [{'invoice_id': 700, 'invoice_status': 'Unpaid'}], c.calls)
    c = v.visit('/viewticket.php?tid=XYZ999')
    check("viewticket of another client's ticket: no conversation attributes",
          'setConversationCustomAttributes' not in c.names(), c.calls)
    v.set(stand_novars=1)
    c = v.visit('/viewticket.php?tid=ABC123&c=key')
    check('viewticket without template ids: tid from the query, checked in the database',
          c.first('setConversationCustomAttributes') == [{'ticket_tid': 'ABC123'}], c.calls)
    v.set(stand_novars='')
    check('owner: no JS errors', not v.errors, v.errors)

    # logout in the same browser: /logout redirects to a guest page, which resets once
    v.set(stand_scenario='guest')
    c = v.visit('/logout')
    check('after logout: the first guest page resets the widget', c.names() == ['reset'], c.names())
    c = v.visit('/clientarea.php')
    check('after logout: later guest pages do not reset', 'reset' not in c.names(), c.names())
    v.close()

    # another user in the same browser
    v = Visitor(browser, stand, 'owner')
    v.visit('/clientarea.php')
    v.set(stand_scenario='invited')
    c = v.visit('/clientarea.php')
    check('user switch: the widget resets and is not identified on that page',
          'reset' in c.names() and 'setUser' not in c.names(), c.names())
    c = v.visit('/clientarea.php')
    user = c.first('setUser')
    check('invited user: own identifier whmcs:u:11, not the owner\'s',
          user is not None and user[0] == 'whmcs:u:11' and user[1]['identifier_hash'] == hmac_of('whmcs:u:11')
          and user[1]['email'] == 'invited@example.test', user)
    attrs = (c.first('setCustomAttributes') or [{}])[0]
    check('invited user: whmcs_client_id of the account, whmcs_is_owner false',
          attrs.get('whmcs_user_id') == 11 and attrs.get('whmcs_client_id') == 100 and attrs.get('whmcs_is_owner') is False,
          attrs)
    v.close()

    # logged in without an account
    v = Visitor(browser, stand, 'owner')
    v.visit('/clientarea.php')
    v.set(stand_scenario='noclient')
    c = v.visit('/user/accounts')
    check('user without a client: setUser not called, no reset, visitor label',
          'setUser' not in c.names() and 'reset' not in c.names() and c.first('setLabel') == ['visitor'], c.names())
    v.close()

    # values that used to break the widget
    v = Visitor(browser, stand, 'tricky')
    c = v.visit('/clientarea.php')
    user = c.first('setUser')
    attrs = (c.first('setCustomAttributes') or [{}])[0]
    check("O'Brien\\ and </script>: no JS errors", not v.errors, v.errors)
    check("O'Brien\\ and </script>: values arrive verbatim",
          user is not None and user[1]['name'] == "</script> O'Brien\\" and user[1]['company_name'] == "O'Brien\\"
          and attrs.get('city') == 'Back\\slash', (user, attrs.get('city')))
    v.close()

    # languages
    for lang, expected in (('ukranian', 'uk'), ('ukrainian', 'uk'), ('portuguese-br', 'pt_BR'), ('azerbaijani', None)):
        v = Visitor(browser, stand, 'guest', stand_lang=lang)
        c = v.visit('/')
        got = c.first('setLocale')
        check(f'language {lang}: ' + (f'setLocale({expected})' if expected else 'no setLocale'),
              got == ([expected] if expected else None) and c.run['settings'].get('locale') == expected, got)
        v.close()


def main():
    cli_checks()
    stand = Stand()
    try:
        with sync_playwright() as p:
            browser = p.chromium.launch()
            try:
                browser_checks(browser, stand)
            finally:
                browser.close()
    finally:
        stand.stop()
    print(f'\n{"FAILED: " + str(len(failed)) if failed else "ALL PASS"}')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
