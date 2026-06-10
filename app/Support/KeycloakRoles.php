<?php

namespace App\Support;

/**
 * Extracts Keycloak realm roles from an access token.
 *
 * Keycloak places realm roles in the access-token JWT under
 * `realm_access.roles`; they are not returned by the userinfo endpoint, so the
 * token payload must be decoded directly.
 */
class KeycloakRoles
{
    /**
     * @return list<string>
     */
    public static function fromAccessToken(?string $jwt): array
    {
        if ($jwt === null || $jwt === '') {
            return [];
        }

        $segments = explode('.', $jwt);

        if (count($segments) < 2) {
            return [];
        }

        $payload = json_decode(self::base64UrlDecode($segments[1]), true);

        if (! is_array($payload)) {
            return [];
        }

        $roles = $payload['realm_access']['roles'] ?? [];

        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_map(strval(...), $roles));
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
