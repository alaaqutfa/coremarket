<form class="form-horizontal" action="{{ route('payment_method.update') }}" method="POST">
    @csrf
    <input type="hidden" name="payment_method" value="syriatel">
    <div class="form-group row">
        <input type="hidden" name="types[]" value="SYRIATEL_CODE">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('Syriatel Code') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="SYRIATEL_CODE"
                value="{{ env('SYRIATEL_CODE') }}" placeholder="{{ translate('Syriatel Code') }}"
                required>
        </div>
    </div>
    <div class="form-group mb-0 text-right">
        <button type="submit" class="btn btn-sm btn-primary">{{ translate('Save') }}</button>
    </div>
</form>
