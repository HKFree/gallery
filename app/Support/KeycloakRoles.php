<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User;

/**
 * Extracts the application's business roles from the Keycloak `groups` (requested via the `groupshkfree` scope)
 *
 * `groupshkfree` is a Keycloak OAuth scope whose group-membership mapper exposes
 * the user's groups. Scope is mapped to userinfo the claim is available
 * directly on the Socialite user's raw payload.
 * Group values arrive as full paths (`/SO`), so a leading slash is stripped to keep matching stable.
 */
class KeycloakRoles
{
    private const CLAIM = 'groups';

    /**
     * @return list<string>
     */
    public static function fromKeycloakUser(User $user): array
    {
        $raw = $user->getRaw();
        //Log::info('Extracting Keycloak roles from user raw data', ['raw' => $raw]);
        $groups = is_array($raw) ? ($raw[self::CLAIM] ?? []) : [];

        return self::normalize($groups);
    }

    /**
     * @param  array<int, mixed>  $groups
     * @return list<string>
     */
    private static function normalize(array $groups): array
    {
        $roles = array_map(
            fn (mixed $group): string => ltrim((string) $group, '/'),
            $groups,
        );

        return array_values(array_unique(array_filter($roles, fn (string $role): bool => $role !== '')));
    }
}
