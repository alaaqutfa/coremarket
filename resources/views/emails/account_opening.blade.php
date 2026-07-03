<p>{{ translate('Hi! An account has been created on').' '.coremarketStoreName() }}</p>
<p>{{ translate('Your Account Type is') }}: {{ ucfirst($user_type) }}</p>
<p>{{ translate('Your Password is') }}: {{ $password }}</p>
<a class="btn btn-primary btn-md" href="{{ env('APP_URL') }}">{{ translate('Go to the website') }}</a>
