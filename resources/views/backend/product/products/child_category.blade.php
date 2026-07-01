@isset($product)
    @php
        $childrenCategoriesArr = [];
        $childrenCategories = get_product_category($product->id);
        foreach ($childrenCategories as $key => $childrenCategory) {
            $childrenCategoriesArr[] = $childrenCategory->category_id;
        }
    @endphp
@endisset

@if ($childCategory->parent_id > 0)
<div class="parent_category_{{ $parent_category }} childCategory">
    <div class="input-group w-100 d-flex justify-content-start align-items-center mx-4">
        <input type="checkbox" name="category_ids[]" id="category_ids_{{ $childCategory->id }}"
            value="{{ $childCategory->id }}"
            @if (isset($product)) @if (in_array($childCategory->id, $childrenCategoriesArr)) checked @endif @endif/>
        <label for="category_ids_{{ $childCategory->id }}" class="mb-0 mx-2">
            {{ $childCategory->getTranslation('name') }}
        </label>
    </div>
</div>
@endif
@if ($child_category->childrenCategories)
    @foreach ($child_category->childrenCategories as $childCategory)
        @include('backend.product.products.child_category', ['child_category' => $childCategory])
    @endforeach
@endif
