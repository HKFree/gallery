<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Fake a Keycloak Socialite user with the given groups in the userinfo payload.
 *
 * Keycloak emits group paths (e.g. `/SO`) under the `groups` claim, requested
 * via the `groupshkfree` scope.
 *
 * @param  list<string>  $groups
 */
function fakeKeycloakUser(string $id, string $email, array $groups): SocialiteUser
{
    return (new SocialiteUser)
        ->map([
            'id' => $id,
            'name' => 'Test User',
            'email' => $email,
        ])
        ->setRaw(['groups' => $groups]);
}

it('creates and logs in an admin with the SO role from the groups claim', function () {
    $groups = ['/SO-112', '/VV', '/ZSO', '/SO', '/PMVTEAM', '/SO-12', '/ZSO-122', '/ZSO-120', '/PREDSTAVENSTVO'];

    Socialite::fake('keycloak', fakeKeycloakUser('kc-admin', 'admin@example.com', $groups));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    $this->assertDatabaseHas('users', [
        'keycloak_id' => 'kc-admin',
        'email' => 'admin@example.com',
    ]);

    $user = User::firstWhere('keycloak_id', 'kc-admin');

    expect($user->roles)->toBe(['SO-112', 'VV', 'ZSO', 'SO', 'PMVTEAM', 'SO-12', 'ZSO-122', 'ZSO-120', 'PREDSTAVENSTVO']);
    expect($user->hasRole('SO'))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('logs in an ordinary user with no roles', function () {
    Socialite::fake('keycloak', fakeKeycloakUser('kc-user', 'user@example.com', []));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    $user = User::firstWhere('keycloak_id', 'kc-user');

    expect($user->roles)->toBe([]);
    expect($user->hasRole('SO'))->toBeFalse();
});

it('stores no roles when the groups claim is absent', function () {
    Socialite::fake('keycloak', (new SocialiteUser)
        ->map([
            'id' => 'kc-empty',
            'name' => 'No Groups',
            'email' => 'empty@example.com',
        ])
        ->setRaw(['sub' => 'kc-empty']));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    expect(User::firstWhere('keycloak_id', 'kc-empty')->roles)->toBe([]);
});
