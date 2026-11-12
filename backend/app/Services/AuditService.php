<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\StaffActivity;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuditService
{
    public function terminalId(): ?int
    {
        $device = request()?->header('X-Device-Id');

        return $device ? Terminal::where('device_id', $device)->value('id') : null;
    }

    public function record(?User $user, string $action, ?object $entity = null, ?array $before = null, ?array $after = null, ?string $reason = null, ?int $authorizedBy = null): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'terminal_id' => $this->terminalId(),
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity->id ?? null,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'authorized_by' => $authorizedBy,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    public function activity(?User $user, string $activity, ?string $description = null): void
    {
        if ($user) {
            StaffActivity::create([
                'user_id' => $user->id,
                'terminal_id' => $this->terminalId(),
                'activity' => $activity,
                'description' => $description,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Returns the id of whoever authorised a sensitive action: the user when they
     * hold the permission, otherwise the owner whose PIN was entered at the counter.
     */
    public function authorize(User $user, string $permission, ?string $pin): int
    {
        if ($user->hasPermission($permission)) {
            return $user->id;
        }
        if ($pin) {
            $limiter = 'pin:'.$user->id;
            if (RateLimiter::tooManyAttempts($limiter, 5)) {
                throw new BusinessException('Too many wrong PINs. Wait a minute and try again.', 'pin_locked', 429);
            }
            $approvers = User::with('role.permissions')->whereNotNull('pin')->where('is_active', true)->get();
            foreach ($approvers as $approver) {
                if ($approver->hasPermission($permission) && Hash::check($pin, $approver->pin)) {
                    RateLimiter::clear($limiter);

                    return $approver->id;
                }
            }
            RateLimiter::hit($limiter, 60);
        }

        throw new BusinessException('This needs the owner\'s approval. Enter the owner PIN.', 'authorization_required', 403);
    }
}
