<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Os testes não dependem do build do Vite (public/build/manifest.json):
        // sem isso, qualquer página que renderiza @vite falharia no CI/local.
        $this->withoutVite();
    }
}
