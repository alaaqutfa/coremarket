<form class="form-horizontal" action="{{ route('payment_method.update') }}" method="POST">
    @csrf
    <input type="hidden" name="payment_method" value="mtn">
    <div class="form-group row">
        <input type="hidden" name="types[]" value="MTN_CODE">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('Mtn Code') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="MTN_CODE"
                value="{{ env('MTN_CODE') }}" placeholder="{{ translate('Mtn Code') }}"
                required>
        </div>
    </div>
    <div class="form-group mb-0 text-right">
        <button type="submit" class="btn btn-sm btn-primary">{{ translate('Save') }}</button>
    </div>
</form>
