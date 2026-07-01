<form class="form-horizontal" action="{{ route('payment_method.update') }}" method="POST">
    @csrf
    <input type="hidden" name="payment_method" value="kadmous">
    <div class="form-group row">
        <input type="hidden" name="types[]" value="kADMOUS_NAME">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('Kadmous Name') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="kADMOUS_NAME"
                value="{{ env('kADMOUS_NAME') }}" placeholder="{{ translate('Kadmous Name') }}"
                required>
        </div>
    </div>
    <div class="form-group row">
        <input type="hidden" name="types[]" value="kADMOUS_PHONE">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('Kadmous Phone') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="kADMOUS_PHONE"
                value="{{ env('kADMOUS_PHONE') }}" placeholder="{{ translate('Kadmous Phone') }}"
                required>
        </div>
    </div>
    <div class="form-group mb-0 text-right">
        <button type="submit" class="btn btn-sm btn-primary">{{ translate('Save') }}</button>
    </div>
</form>
