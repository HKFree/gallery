<?php

namespace App\Services;

use App\Models\GalleryImage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pages through indexed gallery images newest first and groups them into month sections.
 *
 * Works on any {@see GalleryImage} query, so one AP's timeline and the network-wide timeline
 * share the same pagination, month index and grouping.
 */
class Timeline
{
    public const PER_PAGE = 60;

    /**
     * Months that have images, newest first, with their image counts.
     *
     * @param  Builder<GalleryImage>  $query
     * @return Collection<int, array{month: string, year: string, label: string, count: int}>
     */
    public function months(Builder $query): Collection
    {
        return $query->clone()
            ->select('sort_month')
            ->selectRaw('count(*) as images')
            ->groupBy('sort_month')
            ->orderByDesc('sort_month')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'month' => $row->sort_month,
                'year' => substr($row->sort_month, 0, 4),
                'label' => $this->label($row->sort_month),
                'count' => (int) $row->images,
            ]);
    }

    /**
     * One cursor page of images, newest first, optionally starting at a month (`Y-m`) and
     * going back from there.
     *
     * @param  Builder<GalleryImage>  $query
     * @return CursorPaginator<int, GalleryImage>
     */
    public function page(Builder $query, ?string $from = null): CursorPaginator
    {
        return $query->clone()
            ->when($this->isMonth($from), fn (Builder $query) => $query->where('sort_month', '<=', $from))
            ->orderByDesc('sort_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Group a page's images into month sections; each section's count covers the whole month,
     * so a month split across pages shows the same heading on both.
     *
     * @param  CursorPaginator<int, GalleryImage>  $page
     * @param  Collection<int, array{month: string, year: string, label: string, count: int}>  $months
     * @param  callable(GalleryImage): array<string, mixed>  $tile
     * @return list<array{month: string, label: string, count: int, images: list<array<string, mixed>>}>
     */
    public function sections(CursorPaginator $page, Collection $months, callable $tile): array
    {
        $counts = $months->pluck('count', 'month');

        return collect($page->items())
            ->groupBy('sort_month')
            ->map(fn (Collection $images, string $month): array => [
                'month' => $month,
                'label' => $this->label($month),
                'count' => $counts[$month] ?? $images->count(),
                'images' => $images->map($tile)->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether the value is a `Y-m` month, as used by the `from` parameter.
     */
    public function isMonth(?string $value): bool
    {
        return $value !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    /**
     * Czech month heading, e.g. "Květen 2024".
     */
    private function label(string $month): string
    {
        return Str::ucfirst(CarbonImmutable::createFromFormat('!Y-m', $month)->locale('cs')->isoFormat('MMMM YYYY'));
    }
}
