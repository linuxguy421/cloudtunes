@extends('layouts.app')

@section('title', 'Stations')

@section('content')
<main class="container">
    <p class="muted" style="margin:0 0 .5rem">YOUR BROADCASTS</p>
    <h1>Stations</h1>
    <p class="lede">Create and review your station records. These pages do not start or restart any live audio services.</p>

    @if (session('status'))
        <div class="notice success" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->has('station'))
        <div class="notice warning" role="alert">{{ $errors->first('station') }}</div>
    @endif

    <div class="stack" style="grid-template-columns:minmax(0,1.4fr) minmax(280px,1fr)">
        <section class="panel">
            <h2>{{ auth()->user()->role === 'admin' ? 'All stations' : 'Your stations' }} ({{ $stations->count() }})</h2>
            @if ($stations->isEmpty())
                <div class="empty">
                    <strong>No stations created yet.</strong>
                    <p style="margin-bottom:0">Once created, a station appears here with its assigned mount path and playback mode.</p>
                </div>
            @else
                <div class="station-list">
                    @foreach ($stations as $station)
                        <article class="station">
                            <div>
                                <h3>{{ $station->name }}</h3>
                                @if (auth()->user()->role === 'admin')
                                    <p>Owner: {{ $station->owner?->name ?? 'Unknown' }} ({{ $station->owner?->email ?? '—' }})</p>
                                @endif
                                <p>Mount: <code>{{ $station->mount_path }}</code></p>
                                <p>Mode: {{ ucfirst($station->playback_mode) }} · Created {{ $station->created_at?->format('M j, Y') }}</p>
                                <div class="row" style="margin-top:.65rem">
                                    <a class="button secondary" href="{{ route('stations.edit', $station) }}">Edit</a>
                                    @if (! in_array($station->status, ['running', 'provisioning'], true))
                                        <form method="POST" action="{{ route('stations.destroy', $station) }}" onsubmit="return confirm('Archive this station? Its configuration and history will be retained.');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button secondary" type="submit">Archive</button>
                                        </form>
                                    @else
                                        <span class="muted">Stop before archiving</span>
                                    @endif
                                </div>
                            </div>
                            <span class="badge {{ $station->status === 'running' ? 'good' : '' }}">{{ ucfirst($station->status) }}</span>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <aside class="panel">
            <h2>Create a station</h2>
            @if ($canCreateStation)
                <p>Choose a name. CloudTunes will generate a unique station slug and mount path.</p>
                <form method="POST" action="{{ route('stations.store') }}">
                    @csrf
                    <div class="field">
                        <label for="name">Station name</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" maxlength="120" required placeholder="e.g. Late Night Synthwave">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <button class="button" type="submit">Create station</button>
                </form>
            @else
                <div class="notice warning">
                    <strong>Approval required</strong>
                    <p style="margin:.35rem 0 0">An administrator must approve your account before you can create stations.</p>
                </div>
            @endif
            <p class="muted" style="font-size:.85rem;margin-bottom:0">New stations use random playback and start in draft/stopped state. Stream startup and quota enforcement are not implemented in this phase.</p>
        </aside>
    </div>
</main>
@endsection
