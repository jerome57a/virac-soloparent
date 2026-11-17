'use strict';
/**
 * Browser-test request isolation.
 *
 * VIRAC_TEST_URL must name an instance that the operator started separately for tests. This module can only check
 * the SHAPE of the URL. It cannot know what answers on that port, so nothing here claims the URL is isolated:
 * the server itself must be independently configured with APP_ENV=testing, the isolated test database and
 * TEST_STORAGE_ROOT (see docs/database-tooling-safety.md).
 *
 * parseTestBase() rules (any failure throws):
 *   - the raw value is matched against one strict pattern: http:// + localhost | 127.0.0.1 | [::1] + an EXPLICIT
 *     port + an optional path of [A-Za-z0-9._~-] segments that ends in "/";
 *   - so no https/other protocols, no credentials ("user:pw@"), no query, no fragment, no whitespace, no backslash,
 *     no percent-encoding, no "//" or "." / ".." path segments, no external or numeric-alias hosts (e.g. 2130706433);
 *   - the port is 1024-65535 with no leading zero, and not 8080, 3306 or 3307 (3307 is the isolated test database,
 *     never an HTTP test origin). The ordinary local site answers on the
 *     default port 80, which is therefore always rejected (an explicit ":80" is rejected as well). This is a
 *     heuristic: a test server on any port other than the ordinary one is the operator's responsibility;
 *   - the WHATWG URL parser must agree with the pattern (same origin, same port, same path).
 *
 * guardContext() installs a catch-all route on a Playwright context so that EVERY request, including sub-resources
 * and redirect targets, is decided here: cross-origin and non-GET requests are aborted; same-origin requests are
 * either answered from a fixture, answered with a stub page, passed through as a static file, or (only when the
 * script lists them) passed through as a live GET page. Anything else is aborted. Pass-through requests are fetched
 * with redirects disabled, so a 3xx response is never followed or handed to the browser.
 */
const path = require('node:path');

const RAW = /^http:\/\/(localhost|127\.0\.0\.1|\[::1\]):([1-9][0-9]{3,4})((?:\/[A-Za-z0-9._~-]+)*\/)$/;
const ORDINARY_BASE = 'http://localhost/virac-soloparent'; // BASE_URL hard-coded by scripts/fixtures/*.php
const FORBIDDEN_PORTS = new Set([8080, 3306, 3307]);
const STATIC_EXT = new Set(['.css', '.js', '.mjs', '.map', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.ico', '.woff', '.woff2', '.ttf', '.otf']);

function parseTestBase(value) {
  const refuse = why => { throw new Error('VIRAC_TEST_URL refused: ' + why + ' There is no default, and the ordinary application URL is never accepted.'); };
  if (typeof value !== 'string' || value === '') refuse('it is not set.');
  const match = RAW.exec(value);
  if (!match) refuse('expected http://localhost:PORT/, http://127.0.0.1:PORT/ or http://[::1]:PORT/ with an explicit test-only port, an optional plain path, a trailing "/", and no credentials, query, fragment, encoding or other protocol.');
  const port = Number(match[2]);
  if (port < 1024 || port > 65535) refuse('the port must be 1024-65535.');
  if (FORBIDDEN_PORTS.has(port)) refuse('port ' + port + ' is reserved for the ordinary web server or a database server (including the isolated test database).');
  if (match[3].split('/').some(segment => segment === '.' || segment === '..')) refuse('the path has a "." or ".." segment.');
  const url = new URL(value);
  if (url.protocol !== 'http:' || url.username || url.password || url.search || url.hash || url.port !== match[2] || url.pathname !== match[3]) {
    refuse('the URL is ambiguous (the URL parser disagrees with the strict pattern).');
  }
  return url.href;
}

/**
 * @param context  Playwright BrowserContext (create it with serviceWorkers: 'block').
 * @param base     value returned by parseTestBase().
 * @param options  fixture(relPath, url) -> html | undefined   served for navigations
 *                 stubPages: true  -> other GET navigations to *.php get a placeholder instead of reaching the server
 *                 livePages: [...]  -> relative paths (e.g. 'portal.php') that may reach the test server as GET
 */
function guardContext(context, base, options = {}) {
  const baseUrl = new URL(base);
  const live = new Set(options.livePages || []);
  const state = { external: [], violations: [], served: 0 };
  context.route('**/*', async route => {
    const request = route.request();
    const url = new URL(request.url());
    if (['data:', 'blob:', 'about:'].includes(url.protocol)) return route.continue();
    if (url.origin !== baseUrl.origin || !url.pathname.startsWith(baseUrl.pathname)) {
      (url.origin === baseUrl.origin ? state.violations : state.external).push(url.href);
      return route.abort('blockedbyclient');
    }
    const relative = decodeURIComponent(url.pathname.slice(baseUrl.pathname.length));
    if (request.method() !== 'GET' || relative.split('/').some(segment => segment === '..' || segment === '.')) {
      state.violations.push(request.method() + ' ' + url.href);
      return route.abort('blockedbyclient');
    }
    const html = request.isNavigationRequest() && options.fixture ? options.fixture(relative, url) : undefined;
    if (html !== undefined) {
      // The PHP fixtures hard-code the ordinary BASE_URL in their markup (links, stylesheets, scripts, images).
      // Point every such reference at the test origin so that no asset request is ever aimed at the ordinary app.
      state.served++;
      return route.fulfill({ contentType: 'text/html', body: html.split(ORDINARY_BASE).join(baseUrl.href.replace(/\/$/, '')) });
    }
    if (request.isNavigationRequest() && options.stubPages && relative.endsWith('.php') && !live.has(relative)) {
      return route.fulfill({ contentType: 'text/html', body: '<p>Fixture destination</p>' });
    }
    if (live.has(relative) || STATIC_EXT.has(path.extname(relative).toLowerCase())) {
      const response = await route.fetch({ maxRedirects: 0 });
      if (response.status() >= 300 && response.status() < 400) {
        state.violations.push(response.status() + ' redirect from ' + url.href);
        return route.abort('blockedbyclient');
      }
      return route.fulfill({ response });
    }
    state.violations.push('unexpected request ' + url.href);
    return route.abort('blockedbyclient');
  });
  return Object.assign(state, {
    assertClean(assert, label = 'browser requests') {
      assert.deepEqual(state.violations, [], label + ' stayed within fixtures and the explicit test URL');
    },
  });
}

async function newGuardedContext(browser, base, contextOptions = {}, guardOptions = {}) {
  const context = await browser.newContext({ ...contextOptions, serviceWorkers: 'block' });
  return { context, guard: guardContext(context, base, guardOptions) };
}

module.exports = { parseTestBase, guardContext, newGuardedContext };
