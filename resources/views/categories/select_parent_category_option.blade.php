<option value="{{ $category->id }}">
    {{ $prefix }}{{ $category->getTranslation('name') }}
</option>

{{-- @if ($category->childrenCategories && $category->childrenCategories->count())
    @foreach ($category->childrenCategories as $child)
        @include('categories.select_parent_category_option', [
            'category' => $child,
            'prefix' => $prefix . '— ',
        ])
    @endforeach
@endif --}}
