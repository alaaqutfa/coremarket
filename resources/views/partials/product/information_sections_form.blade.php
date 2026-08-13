@php
    $sectionLang = $lang ?? env('DEFAULT_LANGUAGE', 'en');
    $sectionRows = old('information_sections');
    if ($sectionRows === null && isset($product)) {
        $sectionRows = $product->informationSections->map(function ($section) use ($sectionLang) {
            return [
                'id' => $section->id,
                'title' => $section->getTranslation('title', $sectionLang),
                'content' => $section->getTranslation('content', $sectionLang),
                'sort_order' => $section->sort_order,
                'is_active' => $section->is_active,
            ];
        })->all();
    }
    $sectionRows = $sectionRows ?: [];
@endphp

<div class="card mt-3" id="product-information-sections">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0 h6">{{ translate('Additional Product Information') }}</h5>
        <button type="button" class="btn btn-sm btn-outline-primary" data-add-information-section>{{ translate('Add Section') }}</button>
    </div>
    <div class="card-body">
        <p class="text-muted fs-12">{{ translate('Optional titled information shown below the product description.') }}</p>
        <div data-information-sections>
            @foreach ($sectionRows as $index => $section)
                <div class="border rounded p-3 mb-3" data-information-section>
                    <input type="hidden" name="information_sections[{{ $index }}][id]" value="{{ $section['id'] ?? '' }}">
                    <div class="row gutters-10">
                        <div class="col-md-6 form-group">
                            <label>{{ translate('Title') }}</label>
                            <input class="form-control" name="information_sections[{{ $index }}][title]" value="{{ $section['title'] ?? '' }}" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>{{ translate('Order') }}</label>
                            <input type="number" min="0" class="form-control" name="information_sections[{{ $index }}][sort_order]" value="{{ $section['sort_order'] ?? $index + 1 }}">
                        </div>
                        <div class="col-md-2 form-group">
                            <label>{{ translate('Status') }}</label>
                            <label class="aiz-switch aiz-switch-success d-block mt-2">
                                <input type="hidden" name="information_sections[{{ $index }}][is_active]" value="0">
                                <input value="1" type="checkbox" name="information_sections[{{ $index }}][is_active]" {{ !empty($section['is_active']) ? 'checked' : '' }}>
                                <span></span>
                            </label>
                        </div>
                        <div class="col-md-1 form-group d-flex align-items-end">
                            <button type="button" class="btn btn-sm btn-outline-danger mb-1" data-remove-information-section aria-label="{{ translate('Remove') }}">&times;</button>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label>{{ translate('Content') }}</label>
                        <textarea class="aiz-text-editor" name="information_sections[{{ $index }}][content]" required>{{ $section['content'] ?? '' }}</textarea>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

<template id="product-information-section-template">
    <div class="border rounded p-3 mb-3" data-information-section>
        <input type="hidden" name="information_sections[__INDEX__][id]" value="">
        <div class="row gutters-10">
            <div class="col-md-6 form-group"><label>{{ translate('Title') }}</label><input class="form-control" name="information_sections[__INDEX__][title]" required></div>
            <div class="col-md-3 form-group"><label>{{ translate('Order') }}</label><input type="number" min="0" class="form-control" name="information_sections[__INDEX__][sort_order]" value="__ORDER__"></div>
            <div class="col-md-2 form-group"><label>{{ translate('Status') }}</label><label class="aiz-switch aiz-switch-success d-block mt-2"><input type="hidden" name="information_sections[__INDEX__][is_active]" value="0"><input value="1" type="checkbox" name="information_sections[__INDEX__][is_active]" checked><span></span></label></div>
            <div class="col-md-1 form-group d-flex align-items-end"><button type="button" class="btn btn-sm btn-outline-danger mb-1" data-remove-information-section aria-label="{{ translate('Remove') }}">&times;</button></div>
        </div>
        <div class="form-group mb-0"><label>{{ translate('Content') }}</label><textarea class="aiz-text-editor" name="information_sections[__INDEX__][content]" required></textarea></div>
    </div>
</template>

<script>
    (function () {
        var root = document.getElementById('product-information-sections');
        if (!root) return;
        var container = root.querySelector('[data-information-sections]');
        var nextIndex = {{ count($sectionRows) }};
        function initializeEditor(textarea) {
            $(textarea).summernote({
                toolbar: [["font", ["bold", "underline", "italic", "clear"]], ["para", ["ul", "ol", "paragraph"]], ["style", ["style"]], ["color", ["color"]], ["table", ["table"]], ["insert", ["link", "picture", "video"]], ["view", ["fullscreen", "undo", "redo"]]],
                disableDragAndDrop: true,
                height: 200
            });
        }
        root.querySelector('[data-add-information-section]').addEventListener('click', function () {
            var html = document.getElementById('product-information-section-template').innerHTML
                .replaceAll('__INDEX__', nextIndex)
                .replaceAll('__ORDER__', container.querySelectorAll('[data-information-section]').length + 1);
            var wrapper = document.createElement('div');
            wrapper.innerHTML = html.trim();
            var section = wrapper.firstElementChild;
            container.appendChild(section);
            initializeEditor(section.querySelector('.aiz-text-editor'));
            nextIndex++;
        });
        root.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-information-section]');
            if (!button) return;
            var section = button.closest('[data-information-section]');
            var editor = section.querySelector('.aiz-text-editor');
            if ($(editor).next('.note-editor').length) $(editor).summernote('destroy');
            section.remove();
        });
    })();
</script>
