<x-mail::message :message="$message ?? null">
<h1>{{ __('beta.welcome_subject') }}</h1>

<p>{{ __('beta.welcome_intro') }}</p>

<x-mail::panel>
<p>{{ __('Email address') }}<br /><strong>{{ $user->email }}</strong></p>
<p>{{ __('Password') }}<br /><code>{{ $password }}</code></p>
</x-mail::panel>

<p>{{ __('beta.limits_summary') }}</p>

<x-mail::button :url="route('login')">{{ __('Log in') }}</x-mail::button>

<x-slot:subcopy>
<p>{{ __('beta.password_reset_hint') }}<br /><a href="{{ route('password.request') }}">{{ __('Reset password') }}</a></p>
<p>{{ __('Log in') }}: <a href="{{ route('login') }}">{{ route('login') }}</a></p>
</x-slot:subcopy>
</x-mail::message>
