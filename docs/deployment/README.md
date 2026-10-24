# Separate local and cPanel configuration

- Local XAMPP: keep the project-root `.htaccess` with `RewriteBase "/Super market/"` and the existing redirect. No cPanel PHP handler belongs in that file.
- Hosting shown in the screenshot: use the rewrite/security section from `cpanel-root.htaccess` in the hosting document-root `.htaccess`. It uses `RewriteBase /` and internal rewrites without an external redirect. Preserve the server's current cPanel-generated PHP handler block. Do not upload the local project-root `.htaccess` over it.
- Keep the Laravel `public/.htaccess` and upload `storage/app/public/.htaccess` security rules when deploying. The cPanel template allows `/storage/...` to rewrite into public uploads while private storage remains outside that mapping.
- Preserve the hosting `.env`, database and application key. Local credentials and XAMPP paths do not belong in the hosting environment.

The template matches the domain-root setup in the supplied screenshot. It is prepared locally and has not been deployed or verified on cPanel. The `ea-php83` handler selects the PHP 8.3 family; it does not prove that the installed patch level is 8.3.36 or later. Verify the hosting web runtime separately. The previous audit's PHP 8.2.4 result applies only to local XAMPP.

## Git updates on the existing hosting checkout

The repository is already at `/home/twinsoft/public_html/shopdemo.twinsofte.com`, so pulling changes updates the application files directly. The root `.cpanel.yml` makes **Deploy HEAD Commit** clear Laravel's generated configuration, route and view caches; it does not copy files, change the hosting PHP handler, run migrations or reset application data. It requires the cPanel PHP 8.3 CLI at `/opt/cpanel/ea-php83/root/usr/bin/php` and must be committed and pushed before cPanel can see it.

Before updating, back up the hosting root `.htaccess` outside the web root. Fetch before temporarily restoring Git's tracked `.htaccess`. Restore the missing `storage/framework/cache/.gitignore`, `storage/framework/cache/data/.gitignore`, `storage/framework/sessions/.gitignore` and `storage/framework/views/.gitignore`; their normal ignore rules hide generated files without deleting them. Fast-forward to `origin/main`, then restore the saved hosting `.htaccess` even if the update fails.

For this existing checkout, marking only `.htaccess` with `git update-index --skip-worktree .htaccess` keeps cPanel's status clean while preserving its hosting rules. This is a local Git setting, not a permanent deployment guarantee: a future upstream change to `.htaccess` can still block an update. Repeat the backup/restore procedure if that happens. Do not use `git reset --hard` or `git clean` to resolve hosting differences. A separate checkout outside the document root with a deployment copy that excludes hosting configuration avoids this limitation in a future deployment redesign.
