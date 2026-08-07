@extends('auth.layouts.authentication')

@section('content')
    @include('auth.'.get_setting('authentication_layout_select').'.forgot_password')
@endsection
