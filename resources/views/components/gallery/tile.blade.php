@props(['image', 'canManage' => false])
<figure data-image class="group relative overflow-hidden rounded-lg border border-gray-200 bg-white">
    <a href="{{ $image['url'] }}" target="_blank" rel="noopener">
        <img src="{{ $image['thumb_url'] }}" alt="{{ $image['name'] }}" loading="lazy"
             class="aspect-square w-full object-cover">
    </a>
    @if ($canManage)
        <button type="button" data-delete-url="{{ $image['delete_url'] }}"
                title="Přesunout do koše"
                class="absolute right-1.5 top-1.5 rounded-md bg-white/90 p-1.5 text-red-600 shadow hover:bg-white pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 pointer-fine:focus-visible:opacity-100">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-7 0v12a1 1 0 001 1h6a1 1 0 001-1V7" />
            </svg>
        </button>
    @endif
    <figcaption class="truncate px-2 py-1 text-xs text-gray-500" title="{{ $image['name'] }}">{{ $image['name'] }}</figcaption>
</figure>
