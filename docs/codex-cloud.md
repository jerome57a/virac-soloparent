# Codex cloud setup

This repository can run in a Linux Codex cloud environment independently of the
Windows/XAMPP installation. Local configuration, applicant files, database dumps,
and generated screenshots are excluded from Git.

In Codex, choose **Work in > Cloud**, open **Select environment**, then choose
**Create environment** and select this GitHub repository. If it is missing, grant
the Codex GitHub connection access to this private repository. Choose **Get
started** and give the setup agent these requirements:

> Set up PHP 8.2 or later, Composer, MariaDB, and PHP extensions pdo_mysql,
> mbstring, gd, and xml. Run composer install. Provision a fresh isolated test
> database named virac_solo_parent_test using a reviewed test-only schema setup;
> do not import the repository schema.sql unchanged because it drops/recreates
> virac_solo_parent. Configure explicit process variables APP_ENV=testing,
> DB_HOST, DB_NAME=virac_solo_parent_test, a dedicated DB_USER/DB_PASS, and
> SMS_PROVIDER=test. Never copy real applicant data. Run the database safety
> guard checks, then the database tests only after verifying this configuration.
> Start PHP on port 8080 and save the environment setup for later use.

`database/schema.sql` drops and recreates `virac_solo_parent`; never import it
unchanged. Database tests now refuse to connect without explicit isolated test
environment variables. The workflow tests need a database user that can create
and drop generated disposable test databases. Use synthetic data only.

After installing tools and starting MariaDB, the setup uses:

```sh
composer install --no-interaction --prefer-dist
php database/tool_safety_guard_checks.php
export APP_ENV=testing DB_HOST=localhost DB_NAME=virac_solo_parent_test
export DB_USER=soloparent_test DB_PASS='<test-only password>' SMS_PROVIDER=test
php database/test_staff_review_workflow.php
php database/test_public_tracking.php
php -S 0.0.0.0:8080 -t .
```

The MySQL commands assume a local development root connection; adapt the user
and authentication to the cloud installation. The app reads `DB_HOST`, `DB_NAME`,
`DB_USER`, and `DB_PASS`. Defaults match XAMPP and may need changing in Linux.
Never commit credentials or reuse real applicant data.

Open `/admin/login.php` in the preview. The fresh seed's administrator credentials
are documented in the README; change the password before sharing an instance.
Create staff accounts through User Management.

Complete and publish the environment setup, then select it for cloud tasks.
Environment selection and GitHub access are account settings and are not changed
by pushing this repository.

Official instructions: https://learn.chatgpt.com/docs/environments/cloud-environments
