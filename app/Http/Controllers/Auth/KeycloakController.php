<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\KeycloakRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class KeycloakController extends Controller
{
    /**
     * Redirect the user to Keycloak to authenticate.
     */
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('keycloak')
            ->scopes(['profile', 'email', 'roles'])
            ->redirect();
    }

    /**
     * Handle the callback from Keycloak.
     */
    public function callback(Request $request): RedirectResponse
    {
        try {
            $keycloakUser = Socialite::driver('keycloak')->user();
        } catch (Throwable) {
            return redirect()->route('home')->with('error', 'Přihlášení se nezdařilo.');
        }

        $user = User::updateOrCreate(
            ['keycloak_id' => $keycloakUser->getId()],
            [
                'name' => $keycloakUser->getName() ?: $keycloakUser->getNickname() ?: $keycloakUser->getEmail(),
                'email' => $keycloakUser->getEmail(),
                'roles' => KeycloakRoles::fromAccessToken($keycloakUser->token),
            ],
        );

        Auth::login($user, remember: true);

        return redirect()->intended(route('home'));
    }

    /**
     * Log the user out locally and from Keycloak.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $logoutUrl = Socialite::driver('keycloak')
            ->getLogoutUrl(route('home'), config('services.keycloak.client_id'));

        return redirect()->away($logoutUrl);
    }
}
