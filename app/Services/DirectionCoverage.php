<?php

namespace App\Services;

use App\Models\GalleryImage;
use App\Models\GalleryImageDescription;
use App\Support\ApName;
use App\Support\Compass;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Which of the 8 compass directions an AP's gallery has views of, how recent they are, and
 * which neighbouring APs lie in the directions that are missing.
 *
 * Only photos with a known direction count (EXIF, file name, set by a manager or a confirmed
 * suggestion), and only photos still in the gallery (present in the timeline index).
 */
class DirectionCoverage
{
    /** A direction whose newest photo is older than this many years counts as outdated. */
    public const FRESH_YEARS = 3;

    /** Neighbouring APs farther than this are not listed for a direction. */
    private const NEIGHBOUR_KM = 15.0;

    public function __construct(
        private readonly UserdbService $userdb,
    ) {}

    /**
     * The 8 directions of one gallery, clockwise from north.
     *
     * @return list<array{point: string, heading: int, count: int, latest: CarbonImmutable|null, state: string, neighbours: list<string>}>
     */
    public function forGallery(string $visibility, int $areaId, int $apId): array
    {
        $aps = $this->userdb->aps();

        return $this->sectors($this->views($visibility, $areaId, $apId)->get("{$areaId}/{$apId}", collect()), $aps->get($apId), $aps);
    }

    /**
     * Coverage of every AP in Userdb, fewest covered directions first.
     *
     * @return Collection<int, array{ap: array{id: int, name: string, area: array{id: int, name: string}}, covered: int, fresh: int, sectors: list<array{point: string, heading: int, count: int, latest: CarbonImmutable|null, state: string, neighbours: list<string>}>}>
     */
    public function overview(string $visibility = 'pub'): Collection
    {
        $aps = $this->userdb->aps();
        $views = $this->views($visibility);

        return $aps
            ->map(function (array $ap) use ($views, $aps): array {
                $sectors = $this->sectors($views->get("{$ap['area']['id']}/{$ap['id']}", collect()), $ap, $aps);

                return [
                    'ap' => $ap,
                    'covered' => count(array_filter($sectors, fn (array $sector): bool => $sector['state'] !== 'missing')),
                    'fresh' => count(array_filter($sectors, fn (array $sector): bool => $sector['state'] === 'fresh')),
                    'sectors' => $sectors,
                ];
            })
            ->sortBy([['covered', 'asc'], ['fresh', 'asc'], [fn (array $a, array $b): int => strnatcasecmp($a['ap']['name'], $b['ap']['name'])]])
            ->values();
    }

    /**
     * Headings and dates of indexed photos with a known direction, grouped by "area/ap".
     *
     * @return Collection<string, Collection<int, array{heading: int, date: CarbonImmutable|null}>>
     */
    private function views(string $visibility, ?int $areaId = null, ?int $apId = null): Collection
    {
        $scope = fn ($query) => $query->where('visibility', $visibility)
            ->when($areaId !== null, fn ($query) => $query->where(['area_id' => $areaId, 'ap_id' => $apId]));

        $dates = GalleryImage::query()->tap($scope)->get(['area_id', 'ap_id', 'filename', 'sort_at'])
            ->mapWithKeys(fn (GalleryImage $image): array => ["{$image->area_id}/{$image->ap_id}/{$image->filename}" => CarbonImmutable::instance($image->sort_at)]);

        return GalleryImageDescription::query()->tap($scope)->whereNotNull('heading')->get(['area_id', 'ap_id', 'filename', 'heading'])
            ->filter(fn (GalleryImageDescription $description): bool => $dates->has("{$description->area_id}/{$description->ap_id}/{$description->filename}"))
            ->groupBy(fn (GalleryImageDescription $description): string => "{$description->area_id}/{$description->ap_id}")
            ->map(fn (Collection $descriptions): Collection => $descriptions->map(fn (GalleryImageDescription $description): array => [
                'heading' => $description->heading,
                'date' => $dates["{$description->area_id}/{$description->ap_id}/{$description->filename}"],
            ])->values());
    }

    /**
     * @param  Collection<int, array{heading: int, date: CarbonImmutable|null}>  $views
     * @param  array{id: int, lat: float|null, lon: float|null}|null  $ap
     * @param  Collection<int, array{id: int, name: string, lat: float|null, lon: float|null}>  $aps
     * @return list<array{point: string, heading: int, count: int, latest: CarbonImmutable|null, state: string, neighbours: list<string>}>
     */
    private function sectors(Collection $views, ?array $ap, Collection $aps): array
    {
        $freshSince = CarbonImmutable::now()->subYears(self::FRESH_YEARS);
        $neighbours = $this->neighboursBySector($ap, $aps);

        return array_map(function (int $index) use ($views, $freshSince, $neighbours): array {
            $inSector = $views->filter(fn (array $view): bool => $this->sector($view['heading']) === $index);
            $latest = $inSector->pluck('date')->filter()->max();

            return [
                'point' => Compass::point($index * 45),
                'heading' => $index * 45,
                'count' => $inSector->count(),
                'latest' => $latest,
                'state' => $inSector->isEmpty() ? 'missing' : ($latest !== null && $latest->greaterThanOrEqualTo($freshSince) ? 'fresh' : 'stale'),
                'neighbours' => $neighbours[$index] ?? [],
            ];
        }, range(0, 7));
    }

    /**
     * Other APs within reach, nearest first, as "AP Name (3,1 km)", per sector index.
     *
     * @param  array{id: int, lat: float|null, lon: float|null}|null  $ap
     * @param  Collection<int, array{id: int, name: string, lat: float|null, lon: float|null}>  $aps
     * @return array<int, list<string>>
     */
    private function neighboursBySector(?array $ap, Collection $aps): array
    {
        if ($ap === null || $ap['lat'] === null || $ap['lon'] === null) {
            return [];
        }

        return $aps
            ->reject(fn (array $other): bool => $other['id'] === $ap['id'] || $other['lat'] === null || $other['lon'] === null)
            ->map(fn (array $other): array => [
                'name' => $other['name'],
                'km' => Compass::distanceKm($ap['lat'], $ap['lon'], $other['lat'], $other['lon']),
                'sector' => $this->sector((int) round(Compass::bearing($ap['lat'], $ap['lon'], $other['lat'], $other['lon']))),
            ])
            ->filter(fn (array $other): bool => $other['km'] > 0.05 && $other['km'] <= self::NEIGHBOUR_KM)
            ->sortBy('km')
            ->groupBy('sector')
            ->map(fn (Collection $others): array => $others
                ->map(fn (array $other): string => ApName::label($other['name']).' ('.Number::format($other['km'], precision: 1, locale: 'cs').' km)')
                ->values()
                ->all())
            ->all();
    }

    /**
     * Index (0 = north, clockwise) of the 45° sector a heading falls into.
     */
    private function sector(int $heading): int
    {
        return (int) round(($heading % 360) / 45) % 8;
    }
}
