# Security audit — 25 October 2026

## Fixes

- Protected code and MySQL backups are outside the web root at `/Users/mohamednasran/.codex/security-backups/supermarket-20261025-001202/` (owner access only). Test logs are retained there.
- Restricted default administrator creation to automated test fixtures. Real installations use `php artisan pos:admin`.
- Rotated the one active account using the seeded `password` credential; cleared its remember token. Replacement credentials are in the private backup directory. Verified login, authenticated profile and logout over HTTP. No remaining active account matches that default. Other passwords and business records were preserved.
- Disabled debug in the local environment and sample environment; restricted local environment file access to its owner and Apache's daemon account.
- Require at least ten characters for new or changed passwords; existing passwords remain valid.
- Added no-store, nosniff, same-origin framing and referrer response headers; preserve stricter receipt referrer policy.
- Deny HTTP access to private project directories, operational files, dotfiles and executable uploads. Added a static-only Apache handler in public upload storage.
- Moved four HR preview documents into `docs/previews`, outside the public directory.
- Escape spreadsheet formulas in customer ledger CSV exports and use a customer-ID filename rather than untrusted customer names.

## Verification

- HTTP runtime probe: **PHP 8.2.4**, **apache2handler**, loaded configuration `/Applications/XAMPP/xamppfiles/etc/php.ini`. Temporary probe removed immediately after use. CLI is a separate **PHP 8.3.30** installation.
- Composer installed and locked audits: zero advisories, zero abandoned packages; no dependency updates warranted. Composer manifest validates. Laravel 12.69.3.
- Baseline: 514 passed, 48 failed. Final: 519 passed, the same 48 failures; failure names compared. All five new security tests pass (23 assertions). The full suite is not green.
- Live login 200; missing CSRF token 419; private paths, dotfiles and uploaded PHP 403; removed preview 404. Response headers verified over HTTP. Syntax and diff checks pass.
- Reviewed route/FormRequest/service authorization, SQL construction, Blade/DOM output escaping, session authentication, file validation, PDF engine settings, SMS endpoints, public receipt tokens and secret storage. No additional SQL injection, XSS or application shell execution vulnerability was confirmed in the reviewed paths. This does not establish exhaustive absence of vulnerabilities. There is no separate API route file.

## Remaining risks and required hosting work

**PHP2Shell remains unresolved on this server.** The PHP engine handles requests before Laravel can protect them. Application changes do not fix CVE-2026-109618 or CVE-2026-109619.

Replace XAMPP's Apache PHP module `/Applications/XAMPP/xamppfiles/modules/libphp.so` and matching PHP runtime/extensions with a supported, compatible **PHP 8.3.36+** package (or PHP 8.4.27+ / 8.5.12+ after compatibility checks). Restart Apache so all workers use the patched engine. Updating Homebrew CLI alone is insufficient; update the separate CLI 8.3.30 installation as well. Recheck PHP_VERSION and PHP_SAPI through the actual HTTP endpoint after restart. No engine upgrade or post-upgrade verification was performed.

Official advisory: https://www.php.net/archive/2026.php — 21 October security advisory; no complete mitigation replaces upgrading.

Production deployment must use the `public/` document root, `APP_ENV=production`, `APP_DEBUG=false`, HTTPS and `SESSION_SECURE_COOKIE=true`. The inspected local URL is HTTP; secure-only cookies were not forced on it. Apache access rules were verified locally; other web servers require equivalent private-path and upload execution restrictions. Remote production hosting was not accessible or verified. Resolve the 48 pre-existing test failures separately before claiming a fully passing release.
