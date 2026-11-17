# Offline presentation

1. Start Apache and MySQL in XAMPP.
2. Open `http://localhost/virac-soloparent/` on the presentation computer.
3. Refresh an already-open page with Ctrl+F5 after updating the project.
4. Disconnect internet access and navigate between the public, staff, admin, and applicant portals.

Bootstrap CSS and JavaScript are bundled in `public/lib/bootstrap/`. Copy that folder along with the rest of the project. Layouts do not need an internet connection or a previously warmed browser cache. Charts are also bundled locally.

The PHP server, database, and existing uploaded files must remain available. If another device is used, it must stay connected to the same local network and
 open the XAMPP computer's local address; `localhost` refers to the device itself.

External government reference links and real SMS delivery still require internet access. SMS test mode is available through `SMS_PROVIDER = 'test'` in the local configuration; it logs test messages without delivering them. This layout fix does not change your SMS configuration. A camera demonstration needs browser camera permission; use localhost on the presentation computer or HTTPS when accessing from another device.

## Repeat the layout check

With Node.js, PHP, Playwright, Chrome, Apache, and MySQL available, run:

```powershell
node scripts/test-offline-layout.cjs
```

The check renders CLI-only previews under read-only database transactions and blocks internet requests in a fresh browser context for each page. It checks desktop/mobile layouts, light/dark themes, missing assets, JavaScript errors, and the staff application review columns. It never submits forms or sends messages. Existing applicant and application records are required for the dashboard and review previews. Playwright must be installed or available through `NODE_PATH`.
