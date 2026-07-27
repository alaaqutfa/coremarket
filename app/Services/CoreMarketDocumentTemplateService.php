<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CoreMarketDocumentTemplateService
{
    public const TYPES = [
        'purchase_order',
        'purchase_receipt',
        'supplier_statement',
        'pos_receipt',
        'price_label',
        'barcode_label',
        'sales_invoice',
        'customer_statement',
        'delivery_note',
        'packing_slip',
    ];

    public const PAPER_TYPES = ['a4', 'receipt_80mm', 'receipt_58mm', 'label', 'custom'];

    private const SETTING_KEYS = [
        'logo_enabled',
        'logo_position',
        'primary_color',
        'secondary_color',
        'font_size',
        'show_store_name',
        'show_supplier_info',
        'show_customer_info',
        'show_barcode',
        'show_sku',
        'show_tax',
        'show_discount',
        'show_family',
        'show_footer',
        'footer_text',
        'columns',
        'label_grid',
    ];

    private const COLUMN_KEYS = [
        'product',
        'variant',
        'sku',
        'barcode',
        'family',
        'quantity',
        'unit_cost',
        'unit_price',
        'regular_price',
        'sale_price',
        'tax',
        'discount',
        'line_total',
        'date',
        'entry_type',
        'reference',
        'description',
        'debit',
        'credit',
        'running_balance',
    ];

    public function defaultTemplate(string $type): DocumentTemplate
    {
        $this->assertType($type);
        if (! Schema::hasTable('document_templates')) {
            return $this->fallbackTemplate($type);
        }

        return DocumentTemplate::query()->ofType($type)->active()->where('is_default', true)->first()
            ?? DocumentTemplate::query()->ofType($type)->active()->oldest('id')->first()
            ?? $this->fallbackTemplate($type);
    }

    public function activeTemplates(string $type): Collection
    {
        $this->assertType($type);
        if (! Schema::hasTable('document_templates')) {
            return collect([$this->fallbackTemplate($type)]);
        }

        return DocumentTemplate::query()
            ->ofType($type)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function resolveTemplate(string $type, ?int $templateId = null): DocumentTemplate
    {
        $this->assertType($type);
        if ($templateId && Schema::hasTable('document_templates')) {
            $template = DocumentTemplate::query()->ofType($type)->active()->find($templateId);
            if ($template) {
                return $template;
            }
        }

        return $this->defaultTemplate($type);
    }

    public function validateSettings(array $settings): array
    {
        $unknown = array_diff(array_keys($settings), self::SETTING_KEYS);
        if ($unknown !== []) {
            throw new DomainException('Unsupported template settings: '.implode(', ', $unknown).'.');
        }

        $normalized = $this->baseSettings();
        foreach ([
            'logo_enabled',
            'show_store_name',
            'show_supplier_info',
            'show_customer_info',
            'show_barcode',
            'show_sku',
            'show_tax',
            'show_discount',
            'show_family',
            'show_footer',
        ] as $key) {
            if (array_key_exists($key, $settings)) {
                $normalized[$key] = filter_var($settings[$key], FILTER_VALIDATE_BOOL);
            }
        }

        if (isset($settings['logo_position'])) {
            if (! in_array($settings['logo_position'], ['left', 'center', 'right'], true)) {
                throw new DomainException('Logo position is invalid.');
            }
            $normalized['logo_position'] = $settings['logo_position'];
        }

        foreach (['primary_color', 'secondary_color'] as $key) {
            if (isset($settings[$key])) {
                if (! preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $settings[$key])) {
                    throw new DomainException('Template colors must use six-digit hexadecimal values.');
                }
                $normalized[$key] = strtoupper((string) $settings[$key]);
            }
        }

        if (isset($settings['font_size'])) {
            if (! is_numeric($settings['font_size']) || (float) $settings['font_size'] < 7 || (float) $settings['font_size'] > 24) {
                throw new DomainException('Template font size must be between 7 and 24.');
            }
            $normalized['font_size'] = (float) $settings['font_size'];
        }

        if (isset($settings['footer_text'])) {
            $footer = trim((string) $settings['footer_text']);
            if (strlen($footer) > 500 || strip_tags($footer) !== $footer || $this->containsExecutableSyntax($footer)) {
                throw new DomainException('Footer text must be plain text without HTML or executable code.');
            }
            $normalized['footer_text'] = $footer;
        }

        if (isset($settings['columns'])) {
            if (! is_array($settings['columns'])) {
                throw new DomainException('Template columns must be an array.');
            }
            $columns = array_values(array_unique(array_map('strval', $settings['columns'])));
            if (array_diff($columns, self::COLUMN_KEYS) !== []) {
                throw new DomainException('Template contains unsupported columns.');
            }
            $normalized['columns'] = $columns;
        }

        if (isset($settings['label_grid'])) {
            $normalized['label_grid'] = $this->validateLabelGrid($settings['label_grid']);
        }

        return $normalized;
    }

    public function templateSettingsSnapshot(DocumentTemplate $template): array
    {
        $settings = $this->validateSettings((array) $template->settings);

        return [
            'id' => $template->exists ? $template->id : null,
            'name' => $template->name,
            'code' => $template->code,
            'template_type' => $template->template_type,
            'paper_type' => $template->paper_type,
            'width_mm' => $this->number($template->width_mm),
            'height_mm' => $this->number($template->height_mm),
            'margins_mm' => [
                'top' => $this->number($template->margin_top_mm) ?? 10.0,
                'right' => $this->number($template->margin_right_mm) ?? 10.0,
                'bottom' => $this->number($template->margin_bottom_mm) ?? 10.0,
                'left' => $this->number($template->margin_left_mm) ?? 10.0,
            ],
            'settings' => $settings,
            'is_fallback' => ! $template->exists,
        ];
    }

    public function ensureDefaultTemplates(): Collection
    {
        if (! Schema::hasTable('document_templates')) {
            return collect();
        }

        return DB::transaction(function () {
            return collect($this->presets())->map(function (array $preset) {
                $template = DocumentTemplate::query()->firstOrCreate(
                    ['code' => $preset['code']],
                    $preset
                );

                if (! DocumentTemplate::query()->ofType($preset['template_type'])->where('is_default', true)->exists()) {
                    $template->forceFill(['is_default' => true, 'is_active' => true])->save();
                }

                return $template;
            });
        });
    }

    public function setDefault(DocumentTemplate $template): void
    {
        if (! $template->is_active) {
            throw new DomainException('Only an active template can be set as default.');
        }

        DB::transaction(function () use ($template) {
            DocumentTemplate::query()
                ->where('template_type', $template->template_type)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);
            $template->forceFill(['is_default' => true])->save();
        });
    }

    public function paperConfig(array $snapshot): array
    {
        if ($snapshot['paper_type'] === 'a4') {
            return ['format' => 'A4'];
        }

        $width = $snapshot['width_mm'] ?? match ($snapshot['paper_type']) {
            'receipt_58mm' => 58,
            'receipt_80mm' => 80,
            default => 50,
        };
        $height = $snapshot['height_mm'] ?? 100;

        return ['format' => [$width, $height]];
    }

    public function renderPreviewData(string $type): array
    {
        $this->assertType($type);

        return [
            'document_number' => 'PREVIEW-0001',
            'store_name' => coremarketStoreName(),
            'party_name' => in_array($type, ['supplier_statement', 'purchase_order', 'purchase_receipt'], true)
                ? 'Sample Supplier'
                : 'Sample Customer',
            'items' => [
                ['name' => 'Sample Product', 'sku' => 'SAMPLE-001', 'barcode' => '123456789012', 'quantity' => 2, 'price' => 12.50],
            ],
            'total' => 25.00,
        ];
    }

    public function typeOptions(): array
    {
        return array_combine(self::TYPES, array_map(fn (string $type) => Str::headline($type), self::TYPES));
    }

    public function paperOptions(): array
    {
        return array_combine(self::PAPER_TYPES, array_map(fn (string $type) => Str::headline($type), self::PAPER_TYPES));
    }

    public function allowedColumns(): array
    {
        return self::COLUMN_KEYS;
    }

    private function fallbackTemplate(string $type): DocumentTemplate
    {
        $preset = collect($this->presets())->firstWhere('template_type', $type);

        return new DocumentTemplate($preset ?: [
            'name' => 'Safe Default',
            'template_type' => $type,
            'paper_type' => 'a4',
            'is_default' => true,
            'is_active' => true,
            'settings' => $this->baseSettings(),
        ]);
    }

    private function presets(): array
    {
        $a4Columns = ['product', 'sku', 'barcode', 'quantity', 'unit_cost', 'regular_price', 'sale_price', 'tax', 'discount', 'line_total'];
        $salesColumns = ['product', 'sku', 'barcode', 'quantity', 'unit_price', 'tax', 'discount', 'line_total'];
        $statementColumns = ['date', 'entry_type', 'reference', 'description', 'debit', 'credit', 'running_balance'];
        $deliveryColumns = ['product', 'sku', 'barcode', 'quantity'];

        return [
            $this->preset('Default Purchase Order A4', 'default-purchase-order-a4', 'purchase_order', 'a4', $a4Columns),
            $this->preset('Default Purchase Receipt A4', 'default-purchase-receipt-a4', 'purchase_receipt', 'a4', $a4Columns),
            $this->preset('Default Supplier Statement A4', 'default-supplier-statement-a4', 'supplier_statement', 'a4', $statementColumns),
            $this->preset('Default POS Receipt 80mm', 'default-pos-receipt-80mm', 'pos_receipt', 'receipt_80mm', ['product', 'quantity', 'regular_price', 'discount', 'line_total'], 80, 200),
            $this->preset('Default POS Receipt 58mm', 'default-pos-receipt-58mm', 'pos_receipt', 'receipt_58mm', ['product', 'quantity', 'regular_price', 'line_total'], 58, 200, false),
            $this->preset('Default Price Label', 'default-price-label', 'price_label', 'label', ['product', 'sku', 'regular_price', 'sale_price'], 50, 30),
            $this->preset('Default Barcode Label', 'default-barcode-label', 'barcode_label', 'label', ['product', 'sku', 'barcode'], 50, 30),
            $this->preset('Default Sales Invoice A4', 'default-sales-invoice-a4', 'sales_invoice', 'a4', $salesColumns),
            $this->preset('Default Customer Statement A4', 'default-customer-statement-a4', 'customer_statement', 'a4', $statementColumns),
            $this->preset('Default Delivery Note A4', 'default-delivery-note-a4', 'delivery_note', 'a4', $deliveryColumns),
            $this->preset('Default Packing Slip A4', 'default-packing-slip-a4', 'packing_slip', 'a4', $deliveryColumns),
        ];
    }

    private function preset(
        string $name,
        string $code,
        string $type,
        string $paper,
        array $columns,
        ?float $width = null,
        ?float $height = null,
        bool $default = true
    ): array {
        return [
            'name' => $name,
            'code' => $code,
            'template_type' => $type,
            'paper_type' => $paper,
            'width_mm' => $width,
            'height_mm' => $height,
            'margin_top_mm' => $paper === 'a4' ? 10 : 3,
            'margin_right_mm' => $paper === 'a4' ? 10 : 3,
            'margin_bottom_mm' => $paper === 'a4' ? 10 : 3,
            'margin_left_mm' => $paper === 'a4' ? 10 : 3,
            'is_default' => $default,
            'is_active' => true,
            'settings' => array_merge($this->baseSettings(), ['columns' => $columns]),
        ];
    }

    private function baseSettings(): array
    {
        return [
            'logo_enabled' => true,
            'logo_position' => 'left',
            'primary_color' => '#2563EB',
            'secondary_color' => '#64748B',
            'font_size' => 10,
            'show_store_name' => true,
            'show_supplier_info' => true,
            'show_customer_info' => true,
            'show_barcode' => true,
            'show_sku' => true,
            'show_tax' => true,
            'show_discount' => true,
            'show_family' => false,
            'show_footer' => true,
            'footer_text' => 'Generated by CoreMarket.',
            'columns' => [],
            'label_grid' => ['columns' => 3, 'rows' => 8, 'gap_mm' => 2],
        ];
    }

    private function validateLabelGrid(mixed $grid): array
    {
        if (! is_array($grid) || array_diff(array_keys($grid), ['columns', 'rows', 'gap_mm']) !== []) {
            throw new DomainException('Label grid settings are invalid.');
        }

        $columns = filter_var($grid['columns'] ?? null, FILTER_VALIDATE_INT);
        $rows = filter_var($grid['rows'] ?? null, FILTER_VALIDATE_INT);
        $gap = $grid['gap_mm'] ?? null;
        if ($columns === false || $rows === false || $columns < 1 || $columns > 10 || $rows < 1 || $rows > 30 || ! is_numeric($gap) || $gap < 0 || $gap > 20) {
            throw new DomainException('Label grid values are outside the allowed range.');
        }

        return ['columns' => $columns, 'rows' => $rows, 'gap_mm' => (float) $gap];
    }

    private function containsExecutableSyntax(string $value): bool
    {
        return preg_match('/<\?|<script|javascript:|on\w+\s*=|\{\{|\{!!/i', $value) === 1;
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new DomainException('Unsupported document template type.');
        }
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
