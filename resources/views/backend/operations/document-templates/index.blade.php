@extends('backend.layouts.app')

@section('content')
<div class="aiz-titlebar text-left mt-2 mb-3">
    <div class="row align-items-center">
        <div class="col"><h1 class="h3">{{ translate('Document Templates') }}</h1></div>
        @if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('document_templates.manage'))
            <div class="col-auto"><a href="{{ route('operations.document-templates.create') }}" class="btn btn-primary">{{ translate('Create Template') }}</a></div>
        @endif
    </div>
</div>
<div class="card"><div class="card-body">
    <p class="text-muted">Safe visual settings only. Templates cannot contain HTML, PHP, Blade, or JavaScript.</p>
    <div class="table-responsive"><table class="table aiz-table mb-0">
        <thead><tr><th>Name</th><th>Type</th><th>Paper</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
        @foreach($templates as $template)
            <tr>
                <td>{{ $template->name }} @if($template->is_default)<span class="badge badge-success">Default</span>@endif</td>
                <td>{{ \Illuminate\Support\Str::headline($template->template_type) }}</td>
                <td>{{ \Illuminate\Support\Str::headline($template->paper_type) }}</td>
                <td>{{ $template->is_active ? 'Active' : 'Inactive' }}</td>
                <td class="text-right">
                    @if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('document_templates.preview'))
                        <a class="btn btn-soft-info btn-sm" target="_blank" href="{{ route('operations.document-templates.preview', $template) }}">Preview</a>
                    @endif
                    @if(auth()->user()?->user_type === 'admin' || auth()->user()?->can('document_templates.manage'))
                        <a class="btn btn-soft-primary btn-sm" href="{{ route('operations.document-templates.edit', $template) }}">Edit</a>
                        @unless($template->is_default)
                            <form class="d-inline" method="POST" action="{{ route('operations.document-templates.default', $template) }}">@csrf @method('PATCH')<button class="btn btn-soft-success btn-sm">Set Default</button></form>
                        @endunless
                        <form class="d-inline" method="POST" action="{{ route('operations.document-templates.toggle', $template) }}">@csrf @method('PATCH')<button class="btn btn-soft-secondary btn-sm">{{ $template->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div></div>
@endsection
