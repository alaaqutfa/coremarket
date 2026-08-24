@php
    $cardChoiceOptions = $product->choiceOptionsArray();
    $showCardVariants = $product->auction_product == 0
        && $product->digital == 0
        && blank($product->external_link)
        && $product->variant_product
        && $cardChoiceOptions !== [];
@endphp

@if ($showCardVariants)
    <div class="product-card-variants mt-2 text-center" data-product-id="{{ $product->id }}"
        data-variant-price-url="{{ route('products.variant_price') }}">
        @foreach ($cardChoiceOptions as $choice)
            @php
                $attributeId = is_array($choice) ? ($choice['attribute_id'] ?? null) : ($choice->attribute_id ?? null);
                $values = is_array($choice) ? ($choice['values'] ?? []) : ($choice->values ?? []);
            @endphp
            @if ($attributeId && is_array($values) && $values !== [])
                <div class="mb-1" data-card-attribute-id="{{ $attributeId }}">
                    <span class="d-block fs-11 text-secondary mb-1">{{ get_single_attribute_name($attributeId) }}</span>
                    <div class="d-flex flex-wrap justify-content-center" style="gap: 4px;">
                        @foreach ($values as $index => $value)
                            <button type="button"
                                class="btn btn-sm rounded-0 border px-2 py-1 fs-11 product-card-variant-option {{ $index === 0 ? 'btn-primary text-white is-selected' : 'btn-light' }}"
                                data-value="{{ $value }}" aria-pressed="{{ $index === 0 ? 'true' : 'false' }}">
                                {{ $value }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
        <small class="product-card-variant-status d-none text-danger"></small>
    </div>
@endif
