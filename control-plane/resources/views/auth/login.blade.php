@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<main class="auth-wrap">
    <section class="panel">
        <p class="muted" style="margin-top:0">YOUR MUSIC. YOUR STATIONS.</p>
        <h1>Welcome back.</h1>
        <p class="lede">Sign in to manage your CloudTunes stations.</p>

        @if (session('status'))
            <div class="notice success" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="notice" role="alert">
                <strong>We couldn't sign you in.</strong>
                <p style="margin:.35rem 0 0">Check your email and password, then try again.</p>
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <div class="field">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:.55rem;font-weight:500">
                    <input style="width:auto" type="checkbox" name="remember" value="1"> Keep me signed in
                </label>
            </div>
            <button class="button" type="submit" style="width:100%">Sign in</button>
        </form>
        <div class="notice warning" style="margin-bottom:0">
            <strong>Invite-only access</strong>
            <p style="margin:.35rem 0 0">CloudTunes accounts are provisioned by an administrator. Contact your administrator if you need access.</p>
        </div>
    </section>
</main>
@endsection
