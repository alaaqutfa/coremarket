<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\CoreMarketDocumentTemplateService;
use Database\Seeders\DocumentTemplateSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithCoreMarketTestSchema;
use Tests\TestCase;

class DocumentTemplateFoundationTest extends TestCase
{
    use InteractsWithCoreMarketTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('coremarket.runtime_snapshot.connection', 'mysql');
        $this->ensureBusinessSettingsTable();
        $this->ensurePermissionTables();
        $this->ensureLegacyUserColumns();
        $this->ensureAdminSupportTables();
    }

    public function test_default_templates_are_seeded_idempotently_and_resolve_by_type(): void
    {
        DB::beginTransaction();
        try {
            $this->seed(DocumentTemplateSeeder::class);
            $firstCount = DocumentTemplate::query()->count();
            $this->seed(DocumentTemplateSeeder::class);

            $this->assertSame(7, $firstCount);
            $this->assertSame($firstCount, DocumentTemplate::query()->count());
            $this->assertSame('purchase_order', app(CoreMarketDocumentTemplateService::class)->defaultTemplate('purchase_order')->template_type);
        } finally {
            DB::rollBack();
        }
    }

    public function test_template_settings_reject_executable_content_invalid_colors_and_unknown_columns(): void
    {
        $service = app(CoreMarketDocumentTemplateService::class);

        foreach ([
            ['footer_text' => '<script>alert(1)</script>'],
            ['footer_text' => '{{ phpinfo() }}'],
            ['primary_color' => 'javascript:red'],
            ['columns' => ['product', 'raw_php']],
        ] as $settings) {
            try {
                $service->validateSettings($settings);
                $this->fail('Unsafe settings were accepted.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }

        $safe = $service->validateSettings(['primary_color' => '#2563EB', 'columns' => ['product', 'sku']]);
        $this->assertSame(['product', 'sku'], $safe['columns']);
    }

    public function test_inactive_template_is_ignored_and_default_can_be_changed(): void
    {
        DB::beginTransaction();
        try {
            $service = app(CoreMarketDocumentTemplateService::class);
            $service->ensureDefaultTemplates();
            $inactive = DocumentTemplate::query()->create([
                'name' => 'Inactive Purchase',
                'code' => 'inactive-purchase-test',
                'template_type' => 'purchase_order',
                'paper_type' => 'a4',
                'is_active' => false,
                'settings' => [],
            ]);
            $this->assertNotSame($inactive->id, $service->resolveTemplate('purchase_order', $inactive->id)->id);

            $active = DocumentTemplate::query()->create([
                'name' => 'Active Purchase',
                'code' => 'active-purchase-test',
                'template_type' => 'purchase_order',
                'paper_type' => 'a4',
                'is_active' => true,
                'settings' => [],
            ]);
            $service->setDefault($active);
            $this->assertSame($active->id, $service->defaultTemplate('purchase_order')->id);
        } finally {
            DB::rollBack();
        }
    }

    public function test_designer_can_open_templates_and_cashier_cannot(): void
    {
        DB::beginTransaction();
        try {
            app(CoreMarketDocumentTemplateService::class)->ensureDefaultTemplates();
            $designer = $this->user('designer-content-test', ['document_templates.view', 'document_templates.preview']);
            $cashier = $this->user('cashier-template-test', []);

            $this->actingAs($designer)->get(route('operations.document-templates.index'))->assertOk();
            $this->actingAs($cashier)->get(route('operations.document-templates.index'))->assertForbidden();
        } finally {
            DB::rollBack();
        }
    }

    public function test_price_and_barcode_label_routes_return_pdf(): void
    {
        DB::beginTransaction();
        try {
            app(CoreMarketDocumentTemplateService::class)->ensureDefaultTemplates();
            $user = $this->user('label-preview-test', ['document_templates.preview']);
            $productId = DB::table('products')->insertGetId([
                'name' => 'Template Label Product',
                'user_id' => 1,
                'category_id' => 1,
                'unit_price' => 12.345,
                'slug' => 'template-label-'.uniqid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (['price_label', 'barcode_label'] as $type) {
                $this->actingAs($user)->post(route('operations.labels.pdf'), [
                    'template_type' => $type,
                    'product_ids' => [$productId],
                ])->assertOk()->assertHeader('content-type', 'application/pdf');
            }
        } finally {
            DB::rollBack();
        }
    }

    private function user(string $roleName, array $permissions): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $user = new User();
        $user->forceFill([
            'name' => $roleName,
            'email' => uniqid($roleName).'@example.test',
            'password' => bcrypt('Temporary123!'),
            'user_type' => 'staff',
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole($role);

        return $user;
    }
}
