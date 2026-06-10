<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Build a minimal JWT whose payload carries the given realm roles.
 *
 * @param  list<string>  $roles
 */
function fakeAccessToken(array $roles): string
{
    $payload = rtrim(strtr(base64_encode((string) json_encode([
        'realm_access' => ['roles' => $roles],
    ])), '+/', '-_'), '=');

    return "header.{$payload}.signature";
}

it('creates and logs in a user with the SO role from the access token', function () {
    Socialite::fake('keycloak', (new SocialiteUser)->map([
        'id' => 'kc-123',
        'name' => 'Spravce Oblasti',
        'email' => 'so@example.com',
    ])->setToken(fakeAccessToken(['SO', 'offline_access'])));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    $this->assertDatabaseHas('users', [
        'keycloak_id' => 'kc-123',
        'email' => 'so@example.com',
    ]);

    $user = User::firstWhere('keycloak_id', 'kc-123');

    expect($user->hasRole('SO'))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

it('logs in a non-SO user without the manage role', function () {
    Socialite::fake('keycloak', (new SocialiteUser)->map([
        'id' => 'kc-999',
        'name' => 'Bezny Uzivatel',
        'email' => 'user@example.com',
    ])->setToken(fakeAccessToken(['offline_access'])));

    $this->get(route('auth.callback'))->assertRedirect(route('home'));

    expect(User::firstWhere('keycloak_id', 'kc-999')->hasRole('SO'))->toBeFalse();
});
