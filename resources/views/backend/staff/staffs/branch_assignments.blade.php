@php
    $selectedBranchIds = old('branch_ids', isset($staff) ? $staff->user->branches->pluck('id')->all() : []);
    $primaryBranch = isset($staff) ? $staff->user->branches->first(fn ($branch) => (bool) $branch->pivot->is_primary) : null;
    $primaryBranchId = old('primary_branch_id', $primaryBranch?->id);
@endphp
<div class="form-group row">
    <label class="col-sm-3 col-from-label">{{ translate('Branches') }}</label>
    <div class="col-sm-9">
        <select name="branch_ids[]" class="form-control aiz-selectpicker" multiple data-live-search="true">
            @foreach($activeBranches as $branch)
                <option value="{{ $branch->id }}" @selected(in_array($branch->id, $selectedBranchIds))>
                    {{ $branch->name }}{{ $branch->is_default ? ' (Default)' : '' }}
                </option>
            @endforeach
        </select>
        <small class="form-text text-muted">{{ translate('If no branch is selected, the default branch is assigned.') }}</small>
    </div>
</div>
<div class="form-group row">
    <label class="col-sm-3 col-from-label">{{ translate('Primary Branch') }}</label>
    <div class="col-sm-9">
        <select name="primary_branch_id" class="form-control aiz-selectpicker">
            <option value="">{{ translate('Use first selected/default branch') }}</option>
            @foreach($activeBranches as $branch)
                <option value="{{ $branch->id }}" @selected((int) $primaryBranchId === (int) $branch->id)>{{ $branch->name }}</option>
            @endforeach
        </select>
    </div>
</div>
