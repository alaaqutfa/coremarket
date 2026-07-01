<form class="form-horizontal" action="{{ route('payment_method.update') }}" method="POST">
    @csrf
    <input type="hidden" name="payment_method" value="haram">
    <div class="form-group row">
        <input type="hidden" name="types[]" value="HARAM_NAME">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('Haram Name') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="HARAM_NAME"
                value="{{ env('HARAM_NAME') }}" placeholder="{{ translate('Haram Name') }}"
                required>
        </div>
    </div>
    <div class="form-group row">
        <input type="hidden" name="types[]" value="HARAM_PHONE">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('Haram Phone') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="HARAM_PHONE"
                value="{{ env('HARAM_PHONE') }}" placeholder="{{ translate('Haram Phone') }}"
                required>
        </div>
    </div>
    <div class="form-group mb-0 text-right">
        <button type="submit" class="btn btn-sm btn-primary">{{ translate('Save') }}</button>
    </div>
</form>
