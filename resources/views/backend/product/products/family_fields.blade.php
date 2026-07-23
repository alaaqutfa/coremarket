@php
    $selectedFamily = old('product_family_id', isset($product) ? $product->product_family_id : null);
    $selectedSubFamily = old('product_sub_family_id', isset($product) ? $product->product_sub_family_id : null);
@endphp
<div class="form-group row">
    <label class="col-xxl-3 col-from-label fs-13">{{ translate('Family') }}</label>
    <div class="col-xxl-9">
        <select class="form-control aiz-selectpicker @error('product_family_id') is-invalid @enderror"
            name="product_family_id" data-live-search="true">
            <option value="">{{ translate('No operational family') }}</option>
            @foreach ($families as $family)
                <option value="{{ $family->id }}" @selected((string) $selectedFamily === (string) $family->id)>
                    {{ $family->name }}{{ $family->code ? ' (' . $family->code . ')' : '' }}
                </option>
            @endforeach
        </select>
        <small class="text-muted">{{ translate('Operational inventory classification; storefront categories remain unchanged.') }}</small>
    </div>
</div>
<div class="form-group row">
    <label class="col-xxl-3 col-from-label fs-13">{{ translate('Sub Family') }}</label>
    <div class="col-xxl-9">
        <select class="form-control aiz-selectpicker @error('product_sub_family_id') is-invalid @enderror"
            name="product_sub_family_id" data-live-search="true">
            <option value="">{{ translate('No operational sub family') }}</option>
            @foreach ($families as $family)
                @if ($family->children->isNotEmpty())
                    <optgroup label="{{ $family->name }}">
                        @foreach ($family->children as $subFamily)
                            <option value="{{ $subFamily->id }}" @selected((string) $selectedSubFamily === (string) $subFamily->id)>
                                {{ $subFamily->name }}{{ $subFamily->code ? ' (' . $subFamily->code . ')' : '' }}
                            </option>
                        @endforeach
                    </optgroup>
                @endif
            @endforeach
        </select>
        <small class="text-muted">{{ translate('The selected sub family must belong to the selected family.') }}</small>
    </div>
</div>
