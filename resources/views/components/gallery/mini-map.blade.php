@props(['map'])

{{-- A small static map: tiles around the photo's origin, the view cone, and APs in view
     (those beyond the map are drawn at its edge, in their direction). --}}
<div class="relative h-[200px] w-[200px] overflow-hidden rounded-md bg-gray-100" data-mini-map>
    @foreach ($map['tiles'] as $tile)
        <img src="{{ $tile['url'] }}" alt="" loading="lazy" referrerpolicy="origin" draggable="false"
             class="absolute h-64 w-64 max-w-none select-none" style="left: {{ $tile['left'] }}px; top: {{ $tile['top'] }}px">
    @endforeach
    <svg viewBox="0 0 200 200" class="absolute inset-0 h-full w-full" aria-hidden="true">
        <polygon points="{{ $map['cone'] }}" class="fill-emerald-500/40 stroke-emerald-700" stroke-width="1.5" />
        @foreach ($map['targets'] as $target)
            <circle cx="{{ $target['x'] }}" cy="{{ $target['y'] }}" r="4" class="fill-amber-500 stroke-white" stroke-width="1.5" />
            <text x="{{ $target['x'] }}" y="{{ $target['y'] - 7 }}" text-anchor="{{ $target['x'] > 140 ? 'end' : ($target['x'] < 60 ? 'start' : 'middle') }}"
                  class="fill-gray-900 stroke-white text-[10px] font-semibold" stroke-width="3" paint-order="stroke">{{ $target['name'] }}{{ $target['inside'] ? '' : ' →' }}</text>
        @endforeach
        <circle cx="{{ $map['center']['x'] }}" cy="{{ $map['center']['y'] }}" r="5" class="fill-gray-900 stroke-white" stroke-width="2" />
    </svg>
    <span class="absolute bottom-0 right-0 bg-white/80 px-1 text-[9px] text-gray-700">
        © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener" class="underline">OpenStreetMap</a>
    </span>
</div>
