<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless(config('cloudtunes.public_registration_enabled', false), 404);

        // Normalize before the unique check so case variants cannot become a database error.
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email', '')))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        // approval_status is set by the database default and is intentionally
        // not mass assignable, so reload it before including it in the response.
        $user->refresh();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'approval_status' => $user->approval_status,
            ],
            'message' => 'Registration received. An administrator must approve your account before you can create stations.',
        ], 201);
    }
}
