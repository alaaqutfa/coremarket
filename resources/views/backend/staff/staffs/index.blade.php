@extends('backend.layouts.app')

@section('content')

<div class="aiz-titlebar text-left mt-2 mb-3">
	<div class="row align-items-center">
		<div class="col-md-6">
			<h1 class="h3">{{translate('All Staffs')}}</h1>
		</div>
        @can('add_staff')
            <div class="col-md-6 text-md-right">
                <a href="{{ route('staffs.create') }}" class="btn btn-circle btn-info">
                    <span>{{translate('Add New Staffs')}}</span>
                </a>
            </div>
        @endcan
	</div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0 h6">{{translate('Staffs')}}</h5>
    </div>
    <div class="card-body">
        <table class="table aiz-table mb-0">
            <thead>
                <tr>
                    <th data-breakpoints="lg" width="10%">#</th>
                    <th>{{translate('Name')}}</th>
                    <th data-breakpoints="lg">{{translate('Email')}}</th>
                    <th data-breakpoints="lg">{{translate('Phone')}}</th>
                    <th data-breakpoints="lg">{{translate('Role')}}</th>
                    <th data-breakpoints="lg">{{translate('Branches')}}</th>
                    <th data-breakpoints="lg">{{translate('Status')}}</th>
                    <th width="10%" class="text-right">{{translate('Options')}}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($staffs as $key => $staff)
                    @if($staff->user != null)
                        <tr>
                            <td>{{ ($key+1) + ($staffs->currentPage() - 1)*$staffs->perPage() }}</td>
                            <td>{{$staff->user->name}}</td>
                            <td>{{$staff->user->email}}</td>
                            <td>{{$staff->user->phone}}</td>
                            <td>
								@if ($staff->role != null)
									{{ $staff->role->getTranslation('name') }}
								@endif
							</td>
                            <td>{{ $staff->user->branches->pluck('name')->join(', ') ?: translate('Default branch') }}</td>
                            <td>{{ $staff->user->banned ? translate('Suspended') : translate('Active') }}</td>
                            <td class="text-right">
                                @can('edit_staff')
                                    <a class="btn btn-soft-primary btn-icon btn-circle btn-sm" href="{{route('staffs.edit', encrypt($staff->id))}}" title="{{ translate('Edit') }}">
                                        <i class="las la-edit"></i>
                                    </a>
                                @endcan
                                @can('edit_staff')
                                    @if (auth()->id() !== $staff->user_id)
                                        <form action="{{ route('staffs.suspend', $staff) }}" method="POST" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-soft-warning btn-icon btn-circle btn-sm" title="{{ $staff->user->banned ? translate('Activate') : translate('Suspend') }}">
                                                <i class="las {{ $staff->user->banned ? 'la-unlock' : 'la-user-slash' }}"></i>
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                                @if ($canHardDeleteStaff && auth()->user()->can('delete_staff'))
                                    <a href="#" class="btn btn-soft-danger btn-icon btn-circle btn-sm confirm-delete" data-href="{{route('staffs.destroy', $staff->id)}}" title="{{ translate('Delete') }}">
                                        <i class="las la-trash"></i>
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
        <div class="aiz-pagination">
            {{ $staffs->appends(request()->input())->links() }}
        </div>
    </div>
</div>

@endsection

@section('modal')
    @include('modals.delete_modal')
@endsection
