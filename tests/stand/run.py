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
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
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
    for f in ('modules/addons/chatwoot/chatwoot.php', 'modules/addons/chatwoot/hooks.php',
              'modules/addons/chatwoot/app.php', 'tools/export-identifier-map.php'):
        r = sh('php', '-l', f)
        check(f'php -l {f}', r.returncode == 0 and 'No syntax errors detected' in r.stdout, r.stdout + r.stderr)

    hooks = (ROOT / 'modules/addons/chatwoot/hooks.php').read_text()
    hits = [l for l in hooks.splitlines() if re.search(r"'\$[A-Za-z_]+'", l)]
    check("no '$var' inside JS strings in hooks.php", not hits, '; '.join(hits))
    check('hooks.php does not read HTTP_HOST', 'HTTP_HOST' not in hooks)

    r = sh('php', 'tests/stand/current_page_test.php')
    check('current_page cases (tests/stand/current_page_test.php)', r.returncode == 0, r.stdout)

    upgrade = sh('php', '-r', '''
        require "tests/stand/bootstrap.php"; cw_stand_boot("guest", "x"); require "modules/addons/chatwoot/chatwoot.php";
        chatwoot_upgrade(["version" => "2.0.4"]);
        echo json_encode(CwStand::$db["tbladdonmodules"]);''')
    rows = json.loads(upgrade.stdout or '[]')
    check('chatwoot_upgrade removes chatwoot_jscode of this module only',
          not any(r['module'] == 'chatwoot' and r['setting'] == 'chatwoot_jscode' for r in rows)
          and any(r['module'] == 'other_module' for r in rows)
          and sum(r['module'] == 'chatwoot' for r in rows) == 14, upgrade.stdout + upgrade.stderr)

    config = sh('php', '-r', 'define("WHMCS", 1); require "modules/addons/chatwoot/chatwoot.php"; echo json_encode(chatwoot_config());')
    config = json.loads(config.stdout or '{}')
    check('module version is 2.0.4-insave.5', config.get('version') == '2.0.4-insave.5', config.get('version'))
    check('shown as "Chatwoot by inSave", author without a third-party image',
          config.get('name') == 'Chatwoot by inSave' and '<img' not in config.get('author', '')
          and 'insavehosting/Chatwoot-WHMCS' in config.get('author', ''), (config.get('name'), config.get('author')))

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

    deploy = (ROOT / 'docs/deploy.md').read_text()
    for name in ('chatwoot.php', 'hooks.php', 'app.php'):
        actual = hashlib.md5((ROOT / 'modules/addons/chatwoot' / name).read_bytes()).hexdigest()
        m = re.search(r'\| `' + re.escape(name) + r'` \| `([0-9a-f]{32})` \|', deploy)
        check(f'docs/deploy.md has the current md5 of {name}', m is not None and m.group(1) == actual,
              f'doc={m and m.group(1)} file={actual}')

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

    def html(self, path):
        page = self.context.new_page()
        page.goto(self.stand.base + path)
        content = page.content()
        page.close()
        return content

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

    # an admin logged in to WHMCS in the same browser ("Login as Client")
    v = Visitor(browser, stand, 'owner', stand_admin=1)
    check('admin session, setting off: no widget', 'chatwootSettings' not in v.html('/clientarea.php'))
    v.set(stand_enableonadmin=1)
    c = v.visit('/clientarea.php')
    check('admin session, setting on: widget shown, never identified, no reset, visitor label',
          'setUser' not in c.names() and 'reset' not in c.names() and c.first('setLabel') == ['visitor']
          and 'whmcs_user_id' not in json.dumps(c.calls), c.names())
    v.set(stand_admin='')
    c = v.visit('/clientarea.php')
    user = c.first('setUser')
    check('the same client without the admin session is identified again',
          user is not None and user[0] == 'whmcs:u:10' and 'reset' not in c.names(), c.names())
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


# --- The WHMCS card (app.php) in a fake Chatwoot dashboard ---------------------

APP_INIT = """<?php
// Stand-in for WHMCS's init.php: the session, the stubs and the test data.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require getenv('CW_STAND_DIR') . '/bootstrap.php';
cw_stand_boot('guest', getenv('CW_CHATWOOT_URL'));
"""


class AppStand:
    """A throwaway WHMCS root on billing.example.test and the fake Chatwoot
    dashboard on chat.example.test: one site, two origins. evil.test is
    another site."""

    def __init__(self):
        self.whmcs_port, self.chat_port = free_port(), free_port()
        self.root = Path(tempfile.mkdtemp(prefix='cw-whmcs-'))
        module = self.root / 'modules/addons/chatwoot'
        module.mkdir(parents=True)
        for f in (ROOT / 'modules/addons/chatwoot').glob('*.php'):
            shutil.copy(f, module / f.name)
        (self.root / 'init.php').write_text(APP_INIT)
        self.origin = f'http://chat.example.test:{self.chat_port}'
        env = dict(os.environ, CW_STAND_DIR=str(STAND), CW_CHATWOOT_URL=self.origin + '/')
        self.procs = [
            subprocess.Popen(['php', '-S', f'127.0.0.1:{self.whmcs_port}', '-t', str(self.root),
                              str(STAND / 'app_router.php')], env=env,
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
            subprocess.Popen(['php', '-S', f'127.0.0.1:{self.chat_port}', '-t', str(STAND / 'dashboard')],
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
        ]
        for port in (self.whmcs_port, self.chat_port):
            for _ in range(50):
                try:
                    socket.create_connection(('127.0.0.1', port), timeout=0.2).close()
                    break
                except OSError:
                    time.sleep(0.1)
        self.app = f'http://billing.example.test:{self.whmcs_port}/modules/addons/chatwoot/app.php'
        self.direct = f'http://127.0.0.1:{self.whmcs_port}/modules/addons/chatwoot/app.php'

    def stop(self):
        for proc in self.procs:
            proc.terminate()
            proc.wait()
        shutil.rmtree(self.root, ignore_errors=True)


def app_checks(p):
    stand = AppStand()
    browser = p.chromium.launch(args=['--host-resolver-rules=MAP *.example.test 127.0.0.1, MAP evil.test 127.0.0.1'])
    try:
        _app_checks(stand, browser)
    finally:
        browser.close()
        stand.stop()


def _app_checks(stand, browser):
    # plain HTTP, no session
    with urllib.request.urlopen(stand.direct) as r:
        headers, body = r.headers, r.read().decode()
    check('card: CSP frame-ancestors is the Chatwoot origin only',
          headers.get('Content-Security-Policy') == f'frame-ancestors {stand.origin}', headers.get('Content-Security-Policy'))
    check('card: no X-Frame-Options, Cache-Control no-store, Referrer-Policy no-referrer',
          headers.get('X-Frame-Options') is None and headers.get('Cache-Control') == 'no-store'
          and headers.get('Referrer-Policy') == 'no-referrer', dict(headers))
    check('card without a session: login link to the admin folder, no token, no data',
          'Log in to WHMCS admin' in body and 'https://billing.example.test/staffpanel/' in body
          and '"token"' not in body and not re.search(r'[\w.+-]+@[\w-]+\.\w+', body), body[:300])
    form = urllib.parse.urlencode({'identifier': 'whmcs:u:10', 'email': 'owner@example.test', 'token': 'x'}).encode()
    try:
        urllib.request.urlopen(stand.direct, data=form)
        status, answer = 200, ''
    except urllib.error.HTTPError as e:
        status, answer = e.code, e.read().decode()
    check('card POST without a session: 403, no email or amounts in the answer',
          status == 403 and '@' not in answer and '$' not in answer and 'login' in answer, (status, answer))

    ctx = browser.new_context()
    page = ctx.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: m.type == 'error' and 'frame-ancestors' not in m.text and errors.append(m.text))

    def open_card(contact, theme='light', host='chat.example.test'):
        q = urllib.parse.urlencode({'app': stand.app, 'contact': json.dumps(contact), 'theme': theme})
        page.goto(f'http://{host}:{stand.chat_port}/?{q}')
        body = page.frame_locator('#dashboard-app--frame-0-0').locator('body')
        text = ''
        for _ in range(60):
            try:
                text = body.inner_text(timeout=500)
            except Exception:
                text = ''
            if text and 'Waiting for the conversation' not in text and 'Loading' not in text:
                break
            time.sleep(0.1)
        return text

    owner = {'id': 1, 'name': 'Olga Owner', 'email': 'owner@example.test', 'identifier': 'whmcs:u:10'}
    text = open_card(owner)
    check('card in Chatwoot without an admin session: login link, no data',
          'Log in to WHMCS admin' in text and 'Acme' not in text, text[:200])

    card = page.frame_locator('#dashboard-app--frame-0-0')
    open_card(owner, theme='dark')
    check("without a session the login note follows Chatwoot's dark theme",
          'dark' in (card.locator('html').get_attribute('class') or ''))

    def attr_list(selector, attribute):
        return card.locator(selector).evaluate_all(f'els => els.map(e => e.getAttribute("{attribute}"))')

    def part(selector):
        loc = card.locator(selector)
        return loc.first.inner_text() if loc.count() else ''

    page.goto(f'http://billing.example.test:{stand.whmcs_port}/stand/admin?id=1')
    text = open_card(owner)
    check('admin session reaches the iframe (same site): the card loads', 'Acme' in text, text[:300])
    account = part('[data-account="100"]')
    check('card by identifier: signed-in user, owner, client details',
          card.locator('[data-match="identifier"]').count() == 1 and 'Signed-in user' in text
          and attr_list('[data-account="100"] [data-role]', 'data-role') == ['owner']
          and 'since 2024-03-01' in account and 'owner@example.test' in account, account)
    check('card summary: credit, unpaid total after payments, overdue and ticket counts',
          '$5.00 USD' in part('[data-stat="credit"]') and '$25.00 USD' in part('[data-stat="unpaid"]')
          and part('[data-stat="overdue"]').strip().endswith('1') and part('[data-stat="tickets"]').strip().endswith('1'),
          [part(f'[data-stat="{k}"]') for k in ('credit', 'unpaid', 'overdue', 'tickets')])
    check('card services: active first, product names, next due',
          attr_list('[data-service]', 'data-service') == ['500', '502'] and 'Starter Hosting' in part('[data-service="500"]')
          and 'next due 2026-11-15' in part('[data-service="500"]') and '1 active / 2' in account, account)
    check('card invoices: balance after payments, overdue flagged',
          '$10.00 USD' in part('[data-invoice="700"]') and 'Overdue' in part('[data-invoice="700"]')
          and 'INV-702' in part('[data-invoice="702"]') and '$15.00 USD' in part('[data-invoice="702"]')
          and 'Overdue' not in part('[data-invoice="702"]'), [part('[data-invoice="700"]'), part('[data-invoice="702"]')])
    check('card tickets: open only, merged and closed left out', attr_list('[data-ticket]', 'data-ticket') == ['ABC123'],
          attr_list('[data-ticket]', 'data-ticket'))
    hrefs = card.locator('a').evaluate_all('els => els.map(e => e.href)')
    check('card links go to the configured admin folder',
          hrefs and all(h.startswith('https://billing.example.test/staffpanel/') for h in hrefs)
          and 'https://billing.example.test/staffpanel/clientssummary.php?userid=100' in hrefs
          and 'https://billing.example.test/staffpanel/invoices.php?action=edit&id=700' in hrefs, hrefs)
    check('card asked Chatwoot for the context (fetch-info)', page.evaluate('window.__fetchInfo') >= 1)

    # every text over its own background: 4.5:1 or more, light and dark
    contrast_js = """() => {
      const rgb = s => (s.match(/[\\d.]+/g) || []).map(Number);
      const lum = c => { const [r, g, b] = c.slice(0, 3).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
      const bg = node => { for (let n = node; n; n = n.parentElement) { const c = rgb(getComputedStyle(n).backgroundColor); if (c.length >= 3 && (c.length < 4 || c[3] > 0)) return c; } return null; };
      const pageBg = window.__pageBg;
      const worst = [];
      for (const node of document.querySelectorAll('#card *')) {
        if (![...node.childNodes].some(t => t.nodeType === 3 && t.textContent.trim())) continue;
        const fg = rgb(getComputedStyle(node).color), back = bg(node) || pageBg;
        const [a, b] = [lum(fg), lum(back)].sort((x, y) => y - x);
        const ratio = (a + 0.05) / (b + 0.05);
        if (ratio < 4.5) worst.push([node.textContent.trim().slice(0, 30), ratio.toFixed(2)]);
      }
      return worst;
    }"""
    for theme, page_bg in (('light', [247, 247, 247]), ('dark', [28, 29, 32])):
        open_card(owner, theme=theme)
        frame = page.query_selector('#dashboard-app--frame-0-0').content_frame()
        frame.evaluate(f'() => {{ window.__pageBg = {page_bg}; }}')
        low = frame.evaluate(contrast_js)
        check(f'card text contrast 4.5:1 or more in the {theme} theme', not low, low)
        if os.environ.get('CW_STAND_SHOTS'):
            for width in (640, 380):
                q = urllib.parse.urlencode({'app': stand.app, 'contact': json.dumps(owner), 'theme': theme, 'width': width})
                page.set_viewport_size({'width': width, 'height': 1000})
                page.goto(f'http://chat.example.test:{stand.chat_port}/?{q}')
                time.sleep(1.2)
                page.screenshot(path=os.path.join(os.environ['CW_STAND_SHOTS'], f'card-{theme}-{width}.png'), full_page=True)
            page.set_viewport_size({'width': 1280, 'height': 1000})

    text = open_card({'id': 2, 'name': 'Ivan', 'email': 'invited@example.test', 'identifier': 'whmcs:u:11'})
    check('card for an invited user: the account, marked as invited user',
          'Acme' in text and attr_list('[data-account="100"] [data-role]', 'data-role') == ['invited'], text[:300])
    text = open_card({'id': 3, 'name': 'Olga', 'email': 'owner@example.test'})
    check('contact without identifier: matched by email, not confirmed',
          card.locator('[data-match="email"]').count() == 1 and 'not confirmed' in text and 'Acme' in text, text[:300])
    text = open_card({'id': 4, 'name': 'Accounts', 'email': 'accounts@example.test'})
    check('account email no user logs in with: found, not confirmed, inactive flagged',
          'Billing Only' in text and 'not confirmed' in text and 'Inactive' in part('[data-account="500"]'), text[:300])
    text = open_card({'id': 5, 'name': 'Stranger', 'email': 'stranger@example.test', 'identifier': 'whmcs:u:999'})
    check('unknown contact: no client', 'No WHMCS client for stranger@example.test' in text, text[:300])
    text = open_card({'id': 6, 'name': 'Telegram user'})
    check('contact without email or identifier (Telegram): says so', 'no contact with an email' in text, text[:300])
    text = open_card({'id': 7, 'name': 'T', 'email': 'tricky@example.test', 'identifier': 'whmcs:u:30'})
    check("O'Brien\\ and </script> shown as text, no JS errors",
          "O'Brien\\" in text and '</script> Test' in text and not errors, (text[:200], errors))
    open_card(owner, theme='dark')
    html_class = card.locator('html').get_attribute('class') or ''
    check("Chatwoot's dark theme reaches the card", 'dark' in html_class, html_class)

    frame = page.query_selector('#dashboard-app--frame-0-0').content_frame()
    status = frame.evaluate("fetch(location.pathname, {method: 'POST', body: new FormData()}).then(r => r.status)")
    check('card POST without the CSRF token: 403', status == 403, status)

    page.goto(stand.app)
    page.evaluate("""() => window.postMessage(JSON.stringify({event: 'appContext',
        data: {contact: {identifier: 'whmcs:u:10', email: 'owner@example.test'}}}), '*')""")
    time.sleep(1)
    check('context from another origin is ignored', 'Waiting for the conversation' in page.inner_text('body'))

    page.goto(f'http://evil.test:{stand.chat_port}/?' + urllib.parse.urlencode(
        {'app': stand.app, 'contact': json.dumps(owner)}))
    time.sleep(1.5)
    framed = page.query_selector('#dashboard-app--frame-0-0').content_frame()
    framed_url = framed.url if framed else ''
    framed_text = framed.evaluate('document.body ? document.body.innerText : ""') if framed else ''
    check('another site cannot frame the card (frame-ancestors)',
          framed_url.startswith('chrome-error://') and 'Acme' not in framed_text, (framed_url, framed_text[:100]))

    for admin, expected, name in ((2, 'no access to this card', 'admin role without Access Control: note, no data'),
                                  (3, 'Log in to WHMCS admin', 'disabled admin: treated as logged out')):
        page.goto(f'http://billing.example.test:{stand.whmcs_port}/stand/admin?id={admin}')
        text = open_card(owner)
        check(name, expected in text and 'Acme' not in text, text[:200])
    ctx.close()


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
            app_checks(p)
    finally:
        stand.stop()
    print(f'\n{"FAILED: " + str(len(failed)) if failed else "ALL PASS"}')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
