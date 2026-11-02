@extends('layouts.app')

@section('title', 'Edit ' . $station->name)

@section('content')
<main class="container">
    <p class="muted" style="margin:0 0 .5rem"><a href="{{ route('stations.index') }}">Stations</a> / Edit</p>
    <h1>Edit station</h1>
    <section class="panel" style="max-width:640px">
        <form method="POST" action="{{ route('stations.update', $station) }}">
            @csrf
            @method('PUT')
            <div class="field">
                <label for="name">Station name</label>
                <input id="name" name="name" type="text" value="{{ old('name', $station->name) }}" maxlength="120" required>
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label>Station slug</label>
                <input type="text" value="{{ $station->slug }}" disabled>
                <p class="muted">The slug and mount path remain stable so existing player URLs are not broken.</p>
            </div>
            <div class="field">
                <label>Icecast mount</label>
                <input type="text" value="{{ $station->mount_path }}" disabled>
            </div>
            <div class="row">
                <button class="button" type="submit">Save changes</button>
                <a class="button secondary" href="{{ route('stations.index') }}">Cancel</a>
            </div>
        </form>
    </section>
</main>
@endsection
