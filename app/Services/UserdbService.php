<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the network's Areas and their APs from the hkfree Userdb API.
 *
 * The remote response is an object keyed by area id; each area carries a
 * `jmeno` (name) and an `aps` map. Results are cached for five minutes.
 */
class UserdbService
{
    private const CACHE_KEY = 'userdb.areas';

    private const CACHE_TTL_MINUTES = 5;

    /**
     * The raw, cached Userdb response.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function raw(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function (): array {
                Log::info('Userdb areas request made');

                return Http::withBasicAuth(
                    (string) config('services.userdb.username'),
                    (string) config('services.userdb.password'),
                )->get((string) config('services.userdb.areas_url'))
                    ->throw()
                    ->json();
            },
        );
    }

    /**
     * All areas, normalized and sorted by name.
     *
     * @return Collection<int, array{id: int, name: string, aps: Collection<int, array{id: int, name: string, active: bool}>}>
     */
    public function areas(): Collection
    {
        return collect($this->raw())
            ->map(fn (array $area): array => $this->normalizeArea($area))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * A single area by id, or null when unknown.
     *
     * @return array{id: int, name: string, aps: Collection<int, array{id: int, name: string, active: bool}>}|null
     */
    public function findArea(int $areaId): ?array
    {
        return $this->areas()->firstWhere('id', $areaId);
    }

    /**
     * A single AP within an area, augmented with its parent area, or null.
     *
     * @return array{id: int, name: string, active: bool, area: array{id: int, name: string}}|null
     */
    public function findAp(int $areaId, int $apId): ?array
    {
        $area = $this->findArea($areaId);

        if ($area === null) {
            return null;
        }

        $ap = $area['aps']->firstWhere('id', $apId);

        if ($ap === null) {
            return null;
        }

        return [
            ...$ap,
            'area' => ['id' => $area['id'], 'name' => $area['name']],
        ];
    }

    /**
     * The home-page tree. An area with exactly one AP whose name equals the
     * area name is collapsed into a single direct link.
     *
     * @return Collection<int, array{id: int, name: string, aps: Collection<int, array{id: int, name: string, active: bool}>, collapsed: bool, link_ap: array{id: int, name: string, active: bool}|null}>
     */
    public function homeTree(): Collection
    {
        return $this->areas()->map(function (array $area): array {
            $aps = $area['aps'];
            $collapsed = $aps->count() === 1 && $aps->first()['name'] === $area['name'];

            return [
                ...$area,
                'collapsed' => $collapsed,
                'link_ap' => $collapsed ? $aps->first() : null,
            ];
        });
    }

    /**
     * Normalize a raw area node into a stable shape.
     *
     * @param  array<string, mixed>  $area
     * @return array{id: int, name: string, aps: Collection<int, array{id: int, name: string, active: bool}>}
     */
    private function normalizeArea(array $area): array
    {
        $aps = collect($area['aps'] ?? [])
            ->map(fn (array $ap): array => [
                'id' => (int) $ap['id'],
                'name' => (string) $ap['jmeno'],
                'active' => (bool) ($ap['aktivni'] ?? false),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            'id' => (int) $area['id'],
            'name' => (string) $area['jmeno'],
            'aps' => $aps,
        ];
    }
}
