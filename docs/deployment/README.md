# Separate local and cPanel configuration

- Local XAMPP: keep the project-root `.htaccess` with `RewriteBase "/Super market/"` and the existing redirect. No cPanel PHP handler belongs in that file.
- Hosting shown in the screenshot: use the rewrite/security section from `cpanel-root.htaccess` in the hosting document-root `.htaccess`. It uses `RewriteBase /` and internal rewrites without an external redirect. Preserve the server's current cPanel-generated PHP handler block. Do not upload the local project-root `.htaccess` over it.
- Keep the Laravel `public/.htaccess` and upload `storage/app/public/.htaccess` security rules when deploying. The cPanel template allows `/storage/...` to rewrite into public uploads while private storage remains outside that mapping.
- Preserve the hosting `.env`, database and application key. Local credentials and XAMPP paths do not belong in the hosting environment.

The template matches the domain-root setup in the supplied screenshot. It is prepared locally and has not been deployed or verified on cPanel. The `ea-php83` handler selects the PHP 8.3 family; it does not prove that the installed patch level is 8.3.36 or later. Verify the hosting web runtime separately. The previous audit's PHP 8.2.4 result applies only to local XAMPP.

## Git updates on the existing hosting checkout

The repository is already at `/home/twinsoft/public_html/shopdemo.twinsofte.com`, so pulling changes updates the application files directly. The root `.cpanel.yml` makes **Deploy HEAD Commit** run `scripts/cpanel-repair.php` and clear Laravel's generated configuration, route and view caches. The repair backs up changed configuration outside the web root, adds a direct front-controller fallback for clean URLs, preserves the existing cPanel PHP handler and disables `APP_DEBUG`. It refuses the local XAMPP configuration. It does not change credentials, application keys, uploads or database records. It requires the cPanel PHP 8.3 CLI at `/opt/cpanel/ea-php83/root/usr/bin/php` and must be committed and pushed before cPanel can see it.

If needed, run the same repair manually from the hosting checkout: `/opt/cpanel/ea-php83/root/usr/bin/php scripts/cpanel-repair.php`, then `/opt/cpanel/ea-php83/root/usr/bin/php artisan config:clear`.

A second observed hosting failure was an overdue demo snapshot with a table set that differs from the current schema. Web requests now pause that incompatible automatic reset and retain the current cycle and business data; login and normal demo actions continue, and demo restrictions stay active. The reset service and scheduled command still reject the incompatible snapshot. The installation owner must review and deliberately rebuild the demo baseline before automatic resets can resume. Do not delete `demo_cycles`, recapture the baseline automatically, or run `migrate:fresh` to fix availability.

The hosting PHP web runtime was observed as **8.3.36** on its Laravel error page during the 25 October investigation. This is separate from local XAMPP's 8.2.4 runtime.

Before updating, back up the hosting root `.htaccess` outside the web root. Fetch before temporarily restoring Git's tracked `.htaccess`. Restore the missing `storage/framework/cache/.gitignore`, `storage/framework/cache/data/.gitignore`, `storage/framework/sessions/.gitignore` and `storage/framework/views/.gitignore`; their normal ignore rules hide generated files without deleting them. Fast-forward to `origin/main`, then restore the saved hosting `.htaccess` even if the update fails.

For this existing checkout, marking only `.htaccess` with `git update-index --skip-worktree .htaccess` keeps cPanel's status clean while preserving its hosting rules. This is a local Git setting, not a permanent deployment guarantee: a future upstream change to `.htaccess` can still block an update. Repeat the backup/restore procedure if that happens. Do not use `git reset --hard` or `git clean` to resolve hosting differences. A separate checkout outside the document root with a deployment copy that excludes hosting configuration avoids this limitation in a future deployment redesign.
