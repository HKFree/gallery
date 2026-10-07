@props(['months', 'sections', 'nextUrl' => null, 'newestUrl' => null, 'url', 'query' => [], 'canManage' => false])

@if ($months->isEmpty())
    <p class="rounded-lg border border-gray-200 bg-white px-4 py-8 text-center text-sm text-gray-500">
        Zatím zde nejsou žádné obrázky.
    </p>
@else
    <div class="lg:grid lg:grid-cols-[minmax(0,1fr)_11rem] lg:gap-8">
        <aside class="lg:order-last">
            <x-timeline.month-index :months="$months" :url="$url" :query="$query" />
        </aside>

        <div data-timeline>
            @if ($newestUrl)
                <p class="mb-6 text-center">
                    <a href="{{ $newestUrl }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">Novější</a>
                </p>
            @endif

            <x-timeline.page :sections="$sections" :next-url="$nextUrl" :can-manage="$canManage" />
        </div>
    </div>
@endif
