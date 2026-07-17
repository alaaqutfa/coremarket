<?php

namespace App\Http\Controllers\Api\V2\Operations;

use App\Http\Controllers\Api\V2\Controller;
use App\Http\Controllers\Api\V2\Operations\Concerns\RespondsWithApiJson;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class OperationsAuthController extends Controller
{
    use RespondsWithApiJson;

    private const POS_ACCESS_PERMISSIONS = [
        'pos.view',
        'pos.sell',
        'cash_shifts.open',
        'cash_shifts.close',
    ];

    public function login(Request $request): JsonResponse
    {
        $this->ensureFeaturesEnabled();

        $data = $request->validate([
            'email_or_phone' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::query()
            ->whereIn('user_type', ['admin', 'staff'])
            ->where(function ($query) use ($data) {
                $query->where('email', $data['email_or_phone'])
                    ->orWhere('phone', $data['email_or_phone']);
            })
            ->first();

        if (! $user || $user->banned || ! Hash::check($data['password'], $user->password)) {
            return $this->error('Invalid POS credentials.', [], 401);
        }

        if (! $user->hasAnyPermission(self::POS_ACCESS_PERMISSIONS)) {
            return $this->error('This user is not allowed to access the POS API.', [], 403);
        }

        $token = $user->createToken($data['device_name'] ?? 'coremarket-pos-api', ['operations:pos'])->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'user_type' => $user->user_type,
            ],
        ]);
    }
}
