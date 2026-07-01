<form class="form-horizontal" action="{{ route('payment_method.update') }}" method="POST">
    @csrf
    <input type="hidden" name="payment_method" value="alfouad">
    <div class="form-group row">
        <input type="hidden" name="types[]" value="ALFOUAD_NAME">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('Alfouad Name') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="ALFOUAD_NAME"
                value="{{ env('ALFOUAD_NAME') }}" placeholder="{{ translate('Alfouad Name') }}"
                required>
        </div>
    </div>
    <div class="form-group row">
        <input type="hidden" name="types[]" value="ALFOUAD_PHONE">
        <div class="col-md-4">
            <label class="col-from-label">{{ translate('Alfouad Phone') }}</label>
        </div>
        <div class="col-md-8">
            <input type="text" class="form-control" name="ALFOUAD_PHONE"
                value="{{ env('ALFOUAD_PHONE') }}" placeholder="{{ translate('Alfouad Phone') }}"
                required>
        </div>
    </div>
    <div class="form-group mb-0 text-right">
        <button type="submit" class="btn btn-sm btn-primary">{{ translate('Save') }}</button>
    </div>
</form>
