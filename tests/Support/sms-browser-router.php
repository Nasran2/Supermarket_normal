<?php

// Run only with the isolated fixture database. Gateway traffic is always mocked.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__.'/../../public'.$path) && ! str_ends_with($path, '.php')) {
    return false;
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'sqlite' || basename(config('database.connections.sqlite.database')) !== 'twinsofte-sms-browser.sqlite') {
    throw new RuntimeException('SMS browser server requires its isolated fixture database.');
}
Http::preventStrayRequests();
Http::fake(['textit.biz/*' => Http::response('OK:browser-mocked')]);
$app->handleRequest(Request::capture());
