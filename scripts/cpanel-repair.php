<?php

// Hosting-only repair. It does not bootstrap Laravel or touch the database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$path = $root.'/.htaccess';
$rules = file_get_contents($path);
if ($rules === false || ! preg_match('~^\h*RewriteBase\h+/\h*$~m', $rules)) {
    fwrite(STDERR, "Expected the cPanel domain-root RewriteBase /. Local XAMPP configuration was not changed.\n");
    exit(1);
}

$legacy = 'RewriteRule ^(?!public(?:/|$))(.*)$ public/$1 [L]';
$marker = '# cPanel: resolve Laravel routes directly';
$updated = $rules;
if (! str_contains($rules, $marker) && ! str_contains($rules, 'public/index.php [L]')) {
    $updated = preg_replace_callback('~^([ \t]*)'.preg_quote($legacy, '~').'[ \t]*$~m', function ($match) use ($marker, $legacy) {
        $lines = [$marker, 'RewriteCond %{DOCUMENT_ROOT}/public/$1 !-f', 'RewriteCond %{DOCUMENT_ROOT}/public/$1 !-d', 'RewriteRule ^(?!public(?:/|$))(.*)$ public/index.php [L]', $legacy];

        return $match[1].implode("\n".$match[1], $lines);
    }, $rules, 1, $count);
    if ($count !== 1) {
        fwrite(STDERR, "The hosting rewrite differs from the supplied configuration; no files changed.\n");
        exit(1);
    }
}

$envPath = $root.'/.env';
$env = is_file($envPath) ? file_get_contents($envPath) : false;
$safeEnv = $env === false ? false : preg_replace('/^APP_DEBUG\h*=.*$/m', 'APP_DEBUG=false', $env, 1, $debugCount);
if ($safeEnv !== false && ! $debugCount) {
    $safeEnv = rtrim($safeEnv)."\nAPP_DEBUG=false\n";
}
if ($updated === $rules && ($env === false || $safeEnv === $env)) {
    fwrite(STDOUT, "Hosting routing and debug settings already repaired.\n");
    exit;
}

umask(0077);
$backupRoot = getenv('SUPERMARKET_CPANEL_BACKUP_ROOT') ?: getenv('HOME').'/cpanel-backups';
$backup = $backupRoot.'/routing-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
if (! mkdir($backup, 0700, true)) {
    throw new RuntimeException('Cannot create the private hosting backup.');
}
foreach ([$path => $updated, $envPath => $safeEnv] as $file => $content) {
    if ($content === false || file_get_contents($file) === $content) {
        continue;
    }
    if (! copy($file, $backup.'/'.basename($file))) {
        throw new RuntimeException('Cannot back up the hosting configuration.');
    }
    $mode = fileperms($file) & 0777;
    $temporary = tempnam(dirname($file), '.cpanel-repair-');
    if ($temporary === false || file_put_contents($temporary, $content) !== strlen($content) || ! chmod($temporary, $mode) || ! rename($temporary, $file)) {
        if ($temporary !== false && is_file($temporary)) {
            unlink($temporary);
        }
        throw new RuntimeException('Cannot safely save the hosting configuration.');
    }
}
fwrite(STDOUT, "Hosting routing repaired; PHP handler preserved; debug disabled when .env is present. Backup: ".$backup."\n");
