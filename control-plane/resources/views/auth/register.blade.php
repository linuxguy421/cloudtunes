@extends('layouts.app')

@section('title', 'Create account')

@section('content')
<main class="auth-wrap">
    <section class="panel">
        <p class="muted" style="margin-top:0">YOUR MUSIC. YOUR STATIONS.</p>
        <h1>Create your account.</h1>
        <p class="lede">Join CloudTunes and manage your own radio stations.</p>

        @if ($errors->any())
            <div class="notice" role="alert">
                <strong>We couldn't create your account.</strong>
                <p style="margin:.35rem 0 0">Review the fields below and try again.</p>
            </div>
        @endif

        <form method="POST" action="{{ route('register.store') }}">
            @csrf
            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="120" required autofocus>
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" required>
                @error('email') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
                <p class="muted" style="font-size:.85rem;margin:.35rem 0 0">Use at least 12 characters.</p>
                @error('password') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required>
            </div>
            <button class="button" type="submit" style="width:100%">Create account</button>
        </form>

        <p style="margin-bottom:0;text-align:center">Already have an account? <a href="{{ route('login') }}">Sign in</a>.</p>
        <div class="notice warning">
            <strong>Administrator approval required</strong>
            <p style="margin:.35rem 0 0">New accounts are pending until an administrator approves them. Administrators are assigned manually.</p>
        </div>
    </section>
</main>
@endsection
