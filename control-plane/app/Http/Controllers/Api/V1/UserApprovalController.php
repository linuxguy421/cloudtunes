<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserApprovalController extends Controller
{
    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->role === 'admin', 403, 'Administrator access is required.');

        $validated = $request->validate([
            'approval_status' => ['required', 'in:approved,rejected'],
        ]);

        $approved = $validated['approval_status'] === 'approved';

        $user->forceFill([
            'approval_status' => $validated['approval_status'],
            'approved_at' => $approved ? now() : null,
            'approved_by' => $approved ? $request->user()->getKey() : null,
        ])->save();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'approval_status' => $user->approval_status,
                'approved_at' => $user->approved_at,
            ],
        ]);
    }
}
