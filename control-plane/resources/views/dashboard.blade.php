@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<main class="container">
    <p class="muted" style="margin:0 0 .5rem">YOUR CONTROL ROOM</p>
    <h1>Welcome, {{ $user->name }}.</h1>
    <p class="lede">Manage your stations and keep an eye on your radio setup.</p>

    @if ($user->role !== 'admin' && $user->approval_status === 'pending')
        <div class="notice warning" role="status">
            <strong>Your account is awaiting approval.</strong>
            <p style="margin:.35rem 0 0">You can sign in, but station creation is unavailable until an administrator approves your account.</p>
        </div>
    @elseif ($user->role !== 'admin' && $user->approval_status === 'rejected')
        <div class="notice" role="alert">
            <strong>This account is not approved for station management.</strong>
            <p style="margin:.35rem 0 0">Contact an administrator if you believe this is incorrect.</p>
        </div>
    @elseif ($user->approval_status === 'approved' || $user->role === 'admin')
        <div class="notice success" role="status">
            <strong>Your account is approved.</strong>
            <p style="margin:.35rem 0 0">You can create stations. Creating a station does not start an audio stream yet.</p>
        </div>
    @endif

    <section class="grid" aria-label="Account overview">
        <article class="panel">
            <p style="margin:0">Your stations</p>
            <div class="stat">{{ $stationCount }}</div>
            <span class="muted">Drafts and configured stations</span>
        </article>
        <article class="panel">
            <p style="margin:0">Station quota</p>
            <div class="stat">{{ $stationLimit ?? '—' }}</div>
            <span class="muted">Concurrent-running limit; enforcement is not yet implemented</span>
        </article>
        <article class="panel">
            <p style="margin:0">Account</p>
            <div style="margin:.6rem 0"><span class="badge {{ $user->approval_status === 'approved' || $user->role === 'admin' ? 'good' : 'warn' }}">{{ ucfirst($user->approval_status ?? 'unknown') }}</span></div>
            <span class="muted">{{ $user->role === 'admin' ? 'Administrator' : 'Listener account' }}</span>
        </article>
    </section>

    <section class="panel">
        <div class="row" style="margin-bottom:1rem">
            <div>
                <h2 style="margin-bottom:.2rem">Recently created stations</h2>
                <p style="margin:0">Station records are ready to configure; runtime playback is not connected yet.</p>
            </div>
            <a class="button secondary" href="{{ route('stations.index') }}">View all stations</a>
        </div>
        @if ($stations->isEmpty())
            <div class="empty">
                <h2>No stations yet</h2>
                <p>Create your first station to get started. You can name it now; playback controls will arrive with runtime integration.</p>
                @if ($user->approval_status === 'approved' || $user->role === 'admin')
                    <a class="button" href="{{ route('stations.index') }}">Create a station</a>
                @endif
            </div>
        @else
            <div class="station-list">
                @foreach ($stations as $station)
                    <article class="station">
                        <div>
                            <h3>{{ $station->name }}</h3>
                            <p>{{ $station->mount_path }} · {{ ucfirst($station->playback_mode) }} playback</p>
                        </div>
                        <span class="badge">{{ ucfirst($station->status) }}</span>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</main>
@endsection
