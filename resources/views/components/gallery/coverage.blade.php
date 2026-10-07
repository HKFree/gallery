@props(['sectors'])

@php
    $missing = array_filter($sectors, fn ($sector) => $sector['state'] === 'missing');
    $stale = array_filter($sectors, fn ($sector) => $sector['state'] === 'stale');
    $withNeighbours = fn ($sector) => $sector['point'].($sector['neighbours'] ? ' (směrem '.implode(', ', array_slice($sector['neighbours'], 0, 2)).')' : '');
@endphp

<section aria-label="Pokrytí směrů" class="mb-6 flex items-center gap-4 rounded-lg border border-gray-200 bg-white p-3">
    <x-gallery.compass-rose :sectors="$sectors" class="shrink-0" />
    <div class="space-y-1 text-sm">
        <p class="font-medium text-gray-900">Výhledy podle směrů</p>
        @if ($missing === [])
            <p class="text-gray-600">Fotky jsou ze všech osmi směrů.</p>
        @else
            <p class="text-gray-600"><span class="font-medium text-gray-800">Chybí:</span> {{ implode(', ', array_map($withNeighbours, $missing)) }}</p>
        @endif
        @if ($stale !== [])
            <p class="text-gray-600">
                <span class="font-medium text-gray-800">Starší než {{ \App\Services\DirectionCoverage::FRESH_YEARS }} roky:</span>
                {{ implode(', ', array_map(fn ($sector) => $sector['point'].' ('.$sector['latest']?->format('Y').')', $stale)) }}
            </p>
        @endif
        <p class="flex flex-wrap gap-x-3 text-xs text-gray-500">
            <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-emerald-500"></span>aktuální</span>
            <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-amber-400"></span>starší</span>
            <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-gray-200"></span>chybí</span>
        </p>
    </div>
</section>
