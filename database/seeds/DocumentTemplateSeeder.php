<?php

namespace Database\Seeders;

use App\Services\CoreMarketDocumentTemplateService;
use Illuminate\Database\Seeder;

class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        app(CoreMarketDocumentTemplateService::class)->ensureDefaultTemplates();
    }
}
