<?php

use App\Models\Product;
use App\Models\User;

// The required bootstrap checks the isolated SQLite path before migrating/seeding.
require __DIR__.'/barcode-browser-bootstrap.php';
User::where('username', 'admin')->firstOrFail()->update(['password' => 'password']);
$product = Product::where('sku', 'BROWSER-MILK')->firstOrFail();
$product->update(['price' => '1250', 'cost' => '850']);
$product->stockLayers()->update(['cost_price' => '850', 'selling_price' => '1250']);
