<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'keycloak_id', 'roles'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'roles' => 'array',
        ];
    }

    /**
     * Determine whether the user carries the given Keycloak realm role.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles ?? [], strict: true);
    }

    /**
     * Determine whether the user carries any of the given Keycloak realm roles.
     *
     * @param  list<string>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return array_intersect($roles, $this->roles ?? []) !== [];
    }
}
