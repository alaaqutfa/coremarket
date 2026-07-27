<div class="form-group"><label>{{ translate('Name') }}</label><input name="name" class="form-control" value="{{ old('name', $branch->name) }}" required></div>
<div class="form-group"><label>{{ translate('Code') }}</label><input name="code" class="form-control" value="{{ old('code', $branch->code) }}"></div>
<div class="form-group"><label>{{ translate('Address') }}</label><textarea name="address" class="form-control">{{ old('address', $branch->address) }}</textarea></div>
<div class="form-group"><label>{{ translate('Phone') }}</label><input name="phone" class="form-control" value="{{ old('phone', $branch->phone) }}"></div>
<div class="form-group">
    <label>{{ translate('Manager') }}</label>
    <select name="manager_user_id" class="form-control aiz-selectpicker" data-live-search="true">
        <option value="">{{ translate('Not assigned') }}</option>
        @foreach($managers as $manager)
            <option value="{{ $manager->id }}" @selected((int) old('manager_user_id', $branch->manager_user_id) === (int) $manager->id)>{{ $manager->name }}</option>
        @endforeach
    </select>
</div>
<label class="aiz-switch aiz-switch-success d-block mb-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $branch->is_active))><span class="slider round"></span><span class="ml-2">{{ translate('Active') }}</span></label>
<label class="aiz-switch aiz-switch-success d-block mb-3"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $branch->is_default))><span class="slider round"></span><span class="ml-2">{{ translate('Default branch') }}</span></label>
