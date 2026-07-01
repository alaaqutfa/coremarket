@isset($product)
    @php
        $childrenCategoriesArr = array();
        $childrenCategories = get_product_category($product->id);
        foreach ($childrenCategories as $key => $childrenCategory) {
            $childrenCategoriesArr[] = $childrenCategory->category_id;
        }
    @endphp
@endisset

<div class="input-group w-full flex justify-content-start align-items-center">
    <input type="checkbox" name="category_ids[]" id="category_ids[{{ $childCategory->id }}]"
        value="{{ $childCategory->id }}" @if(isset($product))
        @if(in_array($childCategory->id,$childrenCategoriesArr)) checked @endif @endif/>
    <label for="category_ids[{{ $childCategory->id }}]" class="mx-2">
        {{ $childCategory->getTranslation('name') }}
    </label>
</div>
@if ($child_category->childrenCategories)
    @foreach ($child_category->childrenCategories as $childCategory)
        @include('categories.child_category', ['child_category' => $childCategory])
    @endforeach
@endif
