<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\Terminal;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private AuditService $audit, private SettingsService $settings) {}

    public function login(LoginRequest $request)
    {
        $user = User::with('role.permissions')->where('username', $request->username)->first();
        if (! $user || ! $user->is_active || ! Hash::check($request->password, $user->password)) {
            throw new BusinessException('Wrong username or password.', 'invalid_credentials', 401);
        }
        $terminal = $request->device_id ? $this->registerTerminal($request->device_id, $request->device_name) : null;
        $token = $user->createToken($terminal->code ?? 'web')->plainTextToken;
        $this->audit->activity($user, 'auth.login', $terminal?->code);
        $this->audit->record($user, 'auth.login');

        return $this->ok(['token' => $token, 'user' => $this->userData($user), 'terminal' => $terminal?->only('id', 'code', 'name')]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('role.permissions');

        return $this->ok(['user' => $this->userData($user), 'settings' => $this->settings->all()]);
    }

    public function logout(Request $request)
    {
        $this->audit->activity($request->user(), 'auth.logout');
        $request->user()->currentAccessToken()->delete();

        return $this->ok();
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role?->name,
            'permissions' => $user->permissionNames(),
        ];
    }

    /** Each device gets a short permanent code, which keeps its offline order numbers unique. */
    private function registerTerminal(string $deviceId, ?string $name): Terminal
    {
        return DB::transaction(function () use ($deviceId, $name) {
            $terminal = Terminal::where('device_id', $deviceId)->lockForUpdate()->first();
            if ($terminal) {
                $terminal->update(['last_seen_at' => now(), 'name' => $name ?? $terminal->name]);

                return $terminal;
            }
            do {
                $code = 'T'.strtoupper(Str::random(3));
            } while (Terminal::where('code', $code)->exists());

            return Terminal::create(['device_id' => $deviceId, 'code' => $code, 'name' => $name, 'last_seen_at' => now()]);
        });
    }
}
