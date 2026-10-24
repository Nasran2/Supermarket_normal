# Separate local and cPanel configuration

- Local XAMPP: keep the project-root `.htaccess` with `RewriteBase "/Super market/"` and the existing redirect. No cPanel PHP handler belongs in that file.
- Hosting shown in the screenshot: use the rewrite/security section from `cpanel-root.htaccess` in the hosting document-root `.htaccess`. It uses `RewriteBase /` and internal rewrites without an external redirect. Preserve the server's current cPanel-generated PHP handler block. Do not upload the local project-root `.htaccess` over it.
- Keep the Laravel `public/.htaccess` and upload `storage/app/public/.htaccess` security rules when deploying. The cPanel template allows `/storage/...` to rewrite into public uploads while private storage remains outside that mapping.
- Preserve the hosting `.env`, database and application key. Local credentials and XAMPP paths do not belong in the hosting environment.

The template matches the domain-root setup in the supplied screenshot. It is prepared locally and has not been deployed or verified on cPanel. The `ea-php83` handler selects the PHP 8.3 family; it does not prove that the installed patch level is 8.3.36 or later. Verify the hosting web runtime separately. The previous audit's PHP 8.2.4 result applies only to local XAMPP.
