# Codex cloud setup

This repository can run in a Linux Codex cloud environment independently of the
Windows/XAMPP installation. Local configuration, applicant files, database dumps,
and generated screenshots are excluded from Git.

In Codex, choose **Work in > Cloud**, open **Select environment**, then choose
**Create environment** and select this GitHub repository. If it is missing, grant
the Codex GitHub connection access to this private repository. Choose **Get
started** and give the setup agent these requirements:

> Set up PHP 8.2 or later, Composer, MariaDB, and PHP extensions pdo_mysql,
> mbstring, gd, and xml. Run composer install. Start MariaDB and create a fresh,
> isolated development database using database/schema.sql and database/seed.sql.
> Keep SMS_PROVIDER=test. Configure the database connection through environment
> variables or an ignored config/local.php. Run the disposable staff workflow
> test and public tracking test, then start PHP on port 8080. Save and test the
> environment setup so future tasks can start it again.

`database/schema.sql` drops and recreates `virac_solo_parent`. Import it only in
the isolated cloud database. The workflow tests need a database user that can
create and drop temporary test databases. Use sample data in cloud development.

After installing tools and starting MariaDB, the setup uses:

```sh
composer install --no-interaction --prefer-dist
mysql -u root < database/schema.sql
mysql -u root virac_solo_parent < database/seed.sql
export SMS_PROVIDER=test
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
