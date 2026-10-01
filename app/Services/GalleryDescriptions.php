<?php

namespace App\Services;

use App\Enums\Scene;
use App\Models\GalleryImage;
use App\Models\GalleryImageDescription;
use App\Models\User;
use App\Support\Compass;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Descriptions of gallery photos, composed from stored facts when a page is rendered:
 *
 * > Výhled z AP Brno na S (0°) — směrem AP Brno-Sever (2,2 km). Výhled na zástavbu (rozpoznáno automaticky).
 *
 * The heading comes from EXIF (`GPSImgDirection`), the file name, or a manager. The origin is
 * the photo's EXIF GPS position, else the AP's own coordinates from Userdb. Other APs within
 * ±{@see self::VIEW_HALF_ANGLE}° of the heading are named. A photo with neither a heading nor
 * a confident scene type gets no description.
 */
class GalleryDescriptions
{
    /** APs within this many degrees either side of the heading count as "in view". */
    private const VIEW_HALF_ANGLE = 25;

    /** APs farther than this are not named. */
    private const MAX_TARGET_KM = 15.0;

    private const MAX_TARGETS = 3;

    /** An EXIF position within this distance of the AP counts as taken from the AP. */
    private const AT_AP_KM = 0.5;

    public function __construct(
        private readonly GalleryStorage $storage,
        private readonly UserdbService $userdb,
    ) {}

    /**
     * Record what a stored photo says about itself: EXIF GPS position and compass heading, or a
     * direction in its file name. Existing facts (e.g. set by a manager) are left alone.
     */
    public function capture(string $visibility, int $areaId, int $apId, string $filename): void
    {
        $key = $this->key($visibility, $areaId, $apId, $filename);

        if (GalleryImageDescription::query()->where($key)->exists()) {
            return;
        }

        $facts = $this->factsFromFile($this->storage->absolutePath($visibility, $areaId, $apId, $filename), $filename);

        if (array_filter($facts, fn ($value) => $value !== null) !== []) {
            GalleryImageDescription::create([...$key, ...$facts]);
        }
    }

    /**
     * Drop a photo's facts (when it is trashed), so a new photo reusing the name starts clean.
     */
    public function forget(string $visibility, int $areaId, int $apId, string $filename): void
    {
        GalleryImageDescription::query()->where($this->key($visibility, $areaId, $apId, $filename))->delete();
    }

    /**
     * Set (or, with null, clear) the view direction by hand.
     */
    public function setHeading(string $visibility, int $areaId, int $apId, string $filename, ?int $heading, User $user): GalleryImageDescription
    {
        return GalleryImageDescription::updateOrCreate($this->key($visibility, $areaId, $apId, $filename), [
            'heading' => $heading,
            'heading_source' => $heading === null ? null : 'manual',
            'edited_by' => $user->id,
        ]);
    }

    /**
     * Store a scene type recognised in the browser.
     */
    public function setScene(string $visibility, int $areaId, int $apId, string $filename, Scene $scene, float $score): GalleryImageDescription
    {
        return GalleryImageDescription::updateOrCreate($this->key($visibility, $areaId, $apId, $filename), [
            'scene' => $scene,
            'scene_score' => $score,
        ]);
    }

    /**
     * Facts of one gallery's photos, keyed by file name.
     *
     * @param  list<string>  $filenames
     * @return Collection<string, GalleryImageDescription>
     */
    public function facts(string $visibility, int $areaId, int $apId, array $filenames): Collection
    {
        return GalleryImageDescription::query()
            ->where(['visibility' => $visibility, 'area_id' => $areaId, 'ap_id' => $apId])
            ->whereIn('filename', $filenames)
            ->get()
            ->keyBy('filename');
    }

    /**
     * Descriptions of one gallery's photos, keyed by file name; photos without one are left out.
     *
     * @param  list<string>  $filenames
     * @return array<string, string>
     */
    public function texts(string $visibility, int $areaId, int $apId, array $filenames): array
    {
        $aps = $this->userdb->aps();
        $ap = $aps->get($apId);
        $facts = $this->facts($visibility, $areaId, $apId, $filenames);

        if ($ap === null || $facts->isEmpty()) {
            return [];
        }

        return $facts->map(fn (GalleryImageDescription $fact): ?string => $this->compose($fact, $ap, $aps))->filter()->all();
    }

    /**
     * Descriptions of indexed photos from any galleries, keyed by "visibility/area/ap/filename".
     *
     * @param  iterable<GalleryImage>  $images
     * @return array<string, string>
     */
    public function textsForImages(iterable $images): array
    {
        $images = collect($images);

        if ($images->isEmpty()) {
            return [];
        }

        $aps = $this->userdb->aps();

        return GalleryImageDescription::query()
            ->whereIn('filename', $images->pluck('filename')->unique()->all())
            ->get()
            ->toBase()
            ->keyBy(fn (GalleryImageDescription $fact): string => "{$fact->visibility}/{$fact->area_id}/{$fact->ap_id}/{$fact->filename}")
            ->only($images->map(fn (GalleryImage $image): string => "{$image->visibility}/{$image->area_id}/{$image->ap_id}/{$image->filename}")->all())
            ->map(fn (GalleryImageDescription $fact): ?string => $aps->has($fact->ap_id) ? $this->compose($fact, $aps[$fact->ap_id], $aps) : null)
            ->filter()
            ->all();
    }

    /**
     * The description sentence for a photo's facts, or null when there is nothing to say.
     *
     * @param  array{id: int, name: string, lat: float|null, lon: float|null}  $ap
     * @param  Collection<int, array{id: int, name: string, lat: float|null, lon: float|null}>  $aps
     */
    public function compose(GalleryImageDescription $facts, array $ap, Collection $aps): ?string
    {
        $scene = $facts->scene !== null && $facts->scene_score >= Scene::MIN_SCORE
            ? "{$facts->scene->label()} (rozpoznáno automaticky)."
            : null;

        if ($facts->heading === null) {
            return $scene;
        }

        $hasOwnOrigin = $facts->origin_lat !== null && $facts->origin_lon !== null;
        [$lat, $lon] = $hasOwnOrigin ? [$facts->origin_lat, $facts->origin_lon] : [$ap['lat'], $ap['lon']];

        $fromAp = ! $hasOwnOrigin
            || ($ap['lat'] !== null && Compass::distanceKm($lat, $lon, $ap['lat'], $ap['lon']) <= self::AT_AP_KM);

        $text = ($fromAp ? 'Výhled z '.$this->apName($ap['name']) : 'Výhled')
            .' na '.Compass::point($facts->heading)." ({$facts->heading}°)";

        if ($lat !== null && $lon !== null) {
            // A photo taken elsewhere may well look at its own AP.
            $targets = $this->apsInView($lat, $lon, $facts->heading, $fromAp ? $ap['id'] : null, $aps);

            if ($targets !== []) {
                $text .= ' — směrem '.implode(', ', $targets);
            }
        }

        return $text.'.'.($scene === null ? '' : " {$scene}");
    }

    /**
     * Other APs within the view cone, nearest first, as "AP Name (2,2 km)".
     *
     * @param  Collection<int, array{id: int, name: string, lat: float|null, lon: float|null}>  $aps
     * @return list<string>
     */
    private function apsInView(float $lat, float $lon, int $heading, ?int $ownApId, Collection $aps): array
    {
        return $aps
            ->reject(fn (array $ap): bool => $ap['id'] === $ownApId || $ap['lat'] === null || $ap['lon'] === null)
            ->map(fn (array $ap): array => [
                'name' => $ap['name'],
                'km' => Compass::distanceKm($lat, $lon, $ap['lat'], $ap['lon']),
                'angle' => Compass::angleBetween(Compass::bearing($lat, $lon, $ap['lat'], $ap['lon']), $heading),
            ])
            ->filter(fn (array $ap): bool => $ap['km'] > 0.05 && $ap['km'] <= self::MAX_TARGET_KM && $ap['angle'] <= self::VIEW_HALF_ANGLE)
            ->sortBy('km')
            ->take(self::MAX_TARGETS)
            ->map(fn (array $ap): string => $this->apName($ap['name']).' ('.Number::format($ap['km'], precision: 1, locale: 'cs').' km)')
            ->values()
            ->all();
    }

    /**
     * "AP Brno", but "Plotiště AP" as is.
     */
    private function apName(string $name): string
    {
        return preg_match('/\bAP\b/u', $name) === 1 ? $name : "AP {$name}";
    }

    /**
     * EXIF GPS position and compass heading, or a heading from the file name.
     *
     * @return array{origin_lat: float|null, origin_lon: float|null, heading: int|null, heading_source: string|null}
     */
    private function factsFromFile(string $path, string $filename): array
    {
        try {
            $gps = @exif_read_data($path, 'GPS');
        } catch (\Throwable) {
            $gps = false;
        }

        $gps = is_array($gps) ? $gps : [];
        $lat = $this->coordinate($gps['GPSLatitude'] ?? null, $gps['GPSLatitudeRef'] ?? 'N');
        $lon = $this->coordinate($gps['GPSLongitude'] ?? null, $gps['GPSLongitudeRef'] ?? 'E');
        $exifHeading = $this->rational($gps['GPSImgDirection'] ?? null);
        $filenameHeading = Compass::headingFromFilename($filename);

        $heading = match (true) {
            $exifHeading !== null && $exifHeading >= 0 && $exifHeading < 360 => [(int) round($exifHeading) % 360, 'exif'],
            $filenameHeading !== null => [$filenameHeading, 'filename'],
            default => [null, null],
        };

        return [
            'origin_lat' => $lat !== null && $lon !== null ? $lat : null,
            'origin_lon' => $lat !== null && $lon !== null ? $lon : null,
            'heading' => $heading[0],
            'heading_source' => $heading[1],
        ];
    }

    /**
     * An EXIF GPS coordinate (degrees, minutes, seconds as rationals) in signed decimal degrees.
     */
    private function coordinate(mixed $parts, mixed $reference): ?float
    {
        if (! is_array($parts) || count($parts) !== 3) {
            return null;
        }

        [$degrees, $minutes, $seconds] = array_map(fn ($part) => $this->rational($part), $parts);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $value = $degrees + $minutes / 60 + $seconds / 3600;
        $value = in_array($reference, ['S', 'W'], true) ? -$value : $value;

        return $value === 0.0 || abs($value) > 180 ? null : round($value, 6);
    }

    /**
     * An EXIF rational ("12345/100") as a number.
     */
    private function rational(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (! is_string($value) || preg_match('#^(\d+)/(\d+)$#', $value, $matches) !== 1 || (int) $matches[2] === 0) {
            return null;
        }

        return (int) $matches[1] / (int) $matches[2];
    }

    /**
     * @return array{visibility: string, area_id: int, ap_id: int, filename: string}
     */
    private function key(string $visibility, int $areaId, int $apId, string $filename): array
    {
        return ['visibility' => $visibility, 'area_id' => $areaId, 'ap_id' => $apId, 'filename' => basename($filename)];
    }
}
