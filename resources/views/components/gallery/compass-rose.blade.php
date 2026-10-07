@props(['sectors', 'size' => 'h-24 w-24', 'labels' => true])

@php
    $center = 50;
    $radius = $labels ? 38 : 48;
    $point = fn (float $degrees, float $r): string => round($center + $r * sin(deg2rad($degrees)), 2).' '.round($center - $r * cos(deg2rad($degrees)), 2);
    $fill = ['fresh' => 'fill-emerald-500', 'stale' => 'fill-amber-400', 'missing' => 'fill-gray-200'];
@endphp

<svg viewBox="0 0 100 100" {{ $attributes->merge(['class' => $size]) }} role="img"
     aria-label="Pokrytí směrů: {{ collect($sectors)->map(fn ($s) => $s['point'].' '.($s['state'] === 'missing' ? 'chybí' : $s['count']))->implode(', ') }}">
    @foreach ($sectors as $sector)
        <path d="M {{ $center }} {{ $center }} L {{ $point($sector['heading'] - 22.5, $radius) }} A {{ $radius }} {{ $radius }} 0 0 1 {{ $point($sector['heading'] + 22.5, $radius) }} Z"
              class="{{ $fill[$sector['state']] }} stroke-white" stroke-width="1.5">
            <title>{{ $sector['point'] }}: {{ $sector['state'] === 'missing' ? 'chybí' : $sector['count'].' '.($sector['count'] === 1 ? 'fotka' : ($sector['count'] < 5 ? 'fotky' : 'fotek')).', nejnovější '.($sector['latest']?->format('Y') ?? '?') }}</title>
        </path>
    @endforeach
    @if ($labels)
        @foreach (['S' => 0, 'V' => 90, 'J' => 180, 'Z' => 270] as $label => $degrees)
            <text x="{{ round($center + 46 * sin(deg2rad($degrees)), 2) }}" y="{{ round($center - 46 * cos(deg2rad($degrees)) + 3, 2) }}"
                  text-anchor="middle" class="fill-gray-500 text-[9px] font-semibold">{{ $label }}</text>
        @endforeach
    @endif
</svg>
