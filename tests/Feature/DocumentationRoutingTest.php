<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocumentationRoutingTest extends TestCase
{
    public function test_documentation_urls_reach_laravel_instead_of_a_public_directory(): void
    {
        foreach (['/documentation', '/documentation/make-a-sale'] as $path) {
            // Artisan's PHP server serves existing public paths before Laravel.
            // Apache's default rewrite rules also exclude public directories.
            $this->assertFalse(file_exists(public_path($path)), "$path must remain an application route.");
            $this->get($path)->assertRedirect(route('login'));
        }
    }
}
