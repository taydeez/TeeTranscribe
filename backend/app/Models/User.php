<?php

namespace App\Models;

use App\Infrastructure\Persistence\Eloquent\Models\Folder;
use App\Notifications\EmailVerificationNotification;
use App\Notifications\PasswordResetNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'google_id'])]
#[Hidden(['password', 'remember_token', 'signup_ip', 'signup_location', 'restriction_reason'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'privacy_retention' => 'array',
            'signup_location' => 'array',
            'suspended_until' => 'datetime',
            'must_change_password' => 'boolean',
            'admin_deleted_at' => 'datetime',
        ];
    }

    /** @return HasMany<Folder, $this> */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function isRestricted(): bool
    {
        return $this->admin_deleted_at !== null || $this->account_status === 'blocked'
            || ($this->account_status === 'suspended' && ($this->suspended_until === null || $this->suspended_until->isFuture()));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new EmailVerificationNotification);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null || filled($this->google_id);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetNotification($token));
    }
}
