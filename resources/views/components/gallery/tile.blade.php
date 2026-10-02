@props(['image', 'canManage' => false])
<figure data-image class="group relative rounded-lg border border-gray-200 bg-white">
    <div class="relative">
        <a href="{{ $image['url'] }}" target="_blank" rel="noopener">
            <img src="{{ $image['thumb_url'] }}" data-name="{{ $image['name'] }}" alt="{{ $image['description'] ?? $image['name'] }}" loading="lazy"
                 @isset($image['description']) title="{{ $image['description'] }}" @endisset
                 class="aspect-square w-full rounded-t-lg object-cover">
        </a>
        @isset($image['map'])
            <details data-map class="absolute bottom-1.5 right-1.5 z-10">
                <summary title="Zobrazit na mapě" class="block w-fit cursor-pointer list-none rounded-md bg-white/90 p-1.5 text-gray-700 shadow hover:bg-white [&::-webkit-details-marker]:hidden">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linejoin="round" d="M12 21s-6-5.3-6-10a6 6 0 1112 0c0 4.7-6 10-6 10z" />
                        <circle cx="12" cy="11" r="2" />
                    </svg>
                    <span class="sr-only">Zobrazit na mapě</span>
                </summary>
                <div class="fixed inset-x-0 bottom-4 z-30 mx-auto w-fit rounded-md bg-white p-1 shadow-lg sm:absolute sm:inset-x-auto sm:bottom-9 sm:right-0">
                    <x-gallery.mini-map :map="$image['map']" />
                </div>
            </details>
        @endisset
    </div>
    @if ($canManage)
        <details data-heading class="absolute left-1.5 top-1.5 z-10 pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 pointer-fine:focus-within:opacity-100 open:opacity-100">
            <summary title="Nastavit směr pohledu" class="block w-fit cursor-pointer list-none rounded-md bg-white/90 p-1.5 text-gray-700 shadow hover:bg-white [&::-webkit-details-marker]:hidden">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" />
                    <path stroke-linejoin="round" d="M15.5 8.5l-2 5-5 2 2-5 5-2z" />
                </svg>
                <span class="sr-only">Nastavit směr pohledu</span>
            </summary>
            <form method="POST" action="{{ $image['description_url'] }}" data-heading-form
                  class="mt-1 grid w-28 grid-cols-3 gap-0.5 rounded-md bg-white p-1 text-xs font-medium shadow-lg">
                @csrf
                <input type="hidden" name="filename" value="{{ $image['name'] }}">
                @foreach ([['SZ', 315], ['S', 0], ['SV', 45], ['Z', 270], null, ['V', 90], ['JZ', 225], ['J', 180], ['JV', 135]] as $direction)
                    @if ($direction)
                        <button type="submit" name="heading" value="{{ $direction[1] }}" class="rounded py-1 text-gray-700 hover:bg-emerald-50 hover:text-emerald-800">{{ $direction[0] }}</button>
                    @else
                        <button type="submit" name="heading" value="" title="Smazat směr" class="rounded py-1 text-gray-400 hover:bg-gray-100">×</button>
                    @endif
                @endforeach
            </form>
        </details>
        <button type="button" data-delete-url="{{ $image['delete_url'] }}"
                title="Přesunout do koše"
                class="absolute right-1.5 top-1.5 rounded-md bg-white/90 p-1.5 text-red-600 shadow hover:bg-white pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 pointer-fine:focus-visible:opacity-100">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-7 0v12a1 1 0 001 1h6a1 1 0 001-1V7" />
            </svg>
        </button>
    @endif
    @isset($image['caption'])
        <figcaption class="flex items-center gap-1 px-2 py-1 text-xs text-gray-500" title="{{ $image['name'] }}">
            @if ($image['locked'] ?? false)
                <x-icon.lock class="h-3 w-3 shrink-0 text-gray-400" />
            @endif
            <a href="{{ $image['caption_url'] }}" class="truncate hover:text-emerald-700">{{ $image['caption'] }}</a>
        </figcaption>
    @else
        <figcaption class="truncate px-2 py-1 text-xs text-gray-500" title="{{ $image['name'] }}">{{ $image['name'] }}</figcaption>
    @endisset
    @if ($canManage && ($image['suggestion'] ?? null))
        @php($suggested = $image['suggestion']['heading'])
        <form method="POST" action="{{ $image['description_url'] }}" data-heading-form data-suggestion
              title="Podle podobné fotky {{ $image['suggestion']['from'] }}"
              class="flex flex-wrap items-center gap-x-2 px-2 pb-1.5 text-xs text-amber-800">
            @csrf
            <input type="hidden" name="filename" value="{{ $image['name'] }}">
            <input type="hidden" name="source" value="similarity">
            <span>Návrh: {{ \App\Support\Compass::point($suggested) }} ({{ $suggested }}°)</span>
            <button type="submit" name="heading" value="{{ $suggested }}" class="cursor-pointer font-medium underline hover:text-amber-900">Potvrdit</button>
        </form>
    @endif
    @isset($image['description'])
        <p data-description class="line-clamp-3 px-2 pb-1.5 text-xs leading-snug text-gray-600">{{ $image['description'] }}</p>
    @endisset
</figure>
