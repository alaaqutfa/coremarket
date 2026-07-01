<form class="form-horizontal" action="{{ route('payment_method.update') }}" method="POST">
    @csrf
    <input type="hidden" name="payment_method" value="united">
    <div class="form-group row">
        <input type="hidden" name="types[]" value="UNITED_NAME">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('United Name') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="UNITED_NAME"
                value="{{ env('UNITED_NAME') }}" placeholder="{{ translate('United Name') }}"
                required>
        </div>
    </div>
    <div class="form-group row">
        <input type="hidden" name="types[]" value="UNITED_PHONE">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('United Phone') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="UNITED_PHONE"
                value="{{ env('UNITED_PHONE') }}" placeholder="{{ translate('United Phone') }}"
                required>
        </div>
    </div>
    <div class="form-group mb-0 text-right">
        <button type="submit" class="btn btn-sm btn-primary">{{ translate('Save') }}</button>
    </div>
</form>
