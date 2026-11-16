<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(): View
    {
        abort_unless(config('cloudtunes.public_registration_enabled', true), 404);

        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('cloudtunes.public_registration_enabled', true), 404);

        // Normalize before validation so email addresses are unique case-insensitively.
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email', ''))),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        return redirect()
            ->route('login')
            ->with('status', 'Your account has been created. An administrator must approve it before you can create stations.');
    }
}
