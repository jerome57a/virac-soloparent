'use strict';
/**
 * Where browser-test artifacts (screenshots) may be written. Mirrors config/storage.php: with no valid
 * TEST_STORAGE_ROOT the tests refuse to run; there is no fallback to the application's storage/ folder.
 *
 * Requirements (any failure throws): APP_ENV=testing and TEST_STORAGE_ROOT in the process environment; an absolute
 * local path (no UNC, no "." / ".." segments) that exists; no component is a link and every component resolves to
 * itself (fs.realpathSync.native, which also resolves NTFS junctions); the folder lies outside the application
 * folder and does not overlap the real location of <app>/storage; it holds a hand-made ".virac-test-storage" file.
 * Screenshots go to <root>/screenshots as plain *.png files, each created new (never overwritten, never via a link).
 */
const fs = require('node:fs');
const path = require('node:path');

const MARKER = '.virac-test-storage';
const appRoot = path.resolve(__dirname, '..', '..');
const win = process.platform === 'win32';
const norm = p => { const n = p.replace(/\\/g, '/').replace(/\/+$/, ''); return win ? n.toLowerCase() : n; };
const within = (p, base) => p === base || (p + '/').startsWith(base + '/');
const fail = why => { throw new Error('Test artifacts refused: ' + why + ' Nothing is written to the application storage folder.'); };

function realOrNull(p) { try { return fs.realpathSync.native(p); } catch { return null; } }

function checkNoLinks(absolute) {
  const parsed = path.parse(absolute);
  let built = parsed.root;
  for (const segment of absolute.slice(parsed.root.length).split(/[\/]+/).filter(Boolean)) {
    built = path.join(built, segment);
    let stat;
    try { stat = fs.lstatSync(built); } catch { fail('a component of the path does not exist: ' + built + '.'); }
    const real = realOrNull(built);
    if (stat.isSymbolicLink() || real === null || norm(real) !== norm(built)) {
      fail('the path is, or passes through, a link, junction or alias: ' + built + '.');
    }
  }
}

function screenshotDir() {
  if (process.env.APP_ENV !== 'testing') fail('APP_ENV must be testing.');
  const given = process.env.TEST_STORAGE_ROOT;
  if (!given || given !== given.trim() || given.includes('\0')) fail('TEST_STORAGE_ROOT is not set.');
  const slashed = given.replace(/\\/g, '/');
  if (slashed.startsWith('//') || !/^(?:[A-Za-z]:)?\//.test(slashed)) fail('TEST_STORAGE_ROOT must be a local absolute path.');
  if (slashed.replace(/^[A-Za-z]:/, '').split('/').some(s => s === '.' || s === '..')) fail('TEST_STORAGE_ROOT must not contain "." or ".." segments.');
  const root = path.resolve(slashed);
  if (path.parse(root).root === root) fail('TEST_STORAGE_ROOT must not be a filesystem root.');
  if (!fs.existsSync(root) || !fs.statSync(root).isDirectory()) fail('TEST_STORAGE_ROOT does not exist; create it first.');
  checkNoLinks(root);
  const test = norm(realOrNull(root));
  for (const candidate of [appRoot, path.join(appRoot, 'storage'), path.join(appRoot, 'storage', 'uploads'),
    path.join(appRoot, 'storage', 'avatars'), path.join(appRoot, 'storage', 'sessions'), path.join(appRoot, 'storage', 'logs')]) {
    for (const c of [norm(candidate), realOrNull(candidate) && norm(realOrNull(candidate))].filter(Boolean)) {
      if (within(test, c) || within(c, test)) fail('TEST_STORAGE_ROOT overlaps the application folder or its storage.');
    }
  }
  const marker = path.join(root, MARKER);
  if (!fs.existsSync(marker) || fs.lstatSync(marker).isSymbolicLink() || !fs.statSync(marker).isFile()) {
    fail('TEST_STORAGE_ROOT must contain a regular file named ' + MARKER + ' (create it by hand).');
  }
  const dir = path.join(root, 'screenshots');
  fs.mkdirSync(dir, { recursive: true });
  checkNoLinks(dir);
  return dir;
}

/**
 * Absolute path for a NEW screenshot file name such as "admin-dashboard-desktop.png".
 *
 * Every call re-validates TEST_STORAGE_ROOT, the marker and <root>/screenshots (no links anywhere), then reserves the
 * name by creating an empty regular file with the exclusive flag "wx". That flag fails if anything already exists at
 * the path, including a file, a directory, or a symbolic link / junction (even a dangling one), and it never follows
 * a link, so an existing file is never overwritten and a link is never written through. A name can be reserved only
 * once: a repeat call, or a leftover file from an earlier run, is refused. Empty or clear <root>/screenshots by hand
 * between runs. Use screenshotDir() when you only need to validate the folder without reserving a name.
 *
 * Residual limit: Playwright writes the image later, by path. Between the reservation and that write, someone with
 * write access to the test folder could swap the reserved file for a link. The folder is meant to be private to the
 * test user (see docs/database-tooling-safety.md).
 */
function screenshotPath(name) {
  if (typeof name !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9._-]{0,100}\.png$/.test(name) || name.includes('..')) fail('unsafe screenshot name: ' + name + '.');
  const dir = screenshotDir();
  const target = path.join(dir, name);
  if (path.dirname(target) !== dir) fail('the screenshot path would leave <TEST_STORAGE_ROOT>/screenshots.');
  let fd;
  try {
    fd = fs.openSync(target, 'wx', 0o600);
  } catch (error) {
    if (error && error.code === 'EEXIST') fail('a screenshot already exists at ' + target + ' (file, folder or link); existing files are never overwritten or followed. Clear the screenshots folder first.');
    fail('the screenshot file could not be reserved (' + (error && error.code) + ').');
  }
  fs.closeSync(fd);
  const stat = fs.lstatSync(target);
  const real = realOrNull(target);
  if (!stat.isFile() || stat.isSymbolicLink() || real === null || norm(real) !== norm(target)) {
    fail('the reserved screenshot file is not a plain file at the expected location: ' + target + '.');
  }
  return target;
}

module.exports = { screenshotPath, screenshotDir };
