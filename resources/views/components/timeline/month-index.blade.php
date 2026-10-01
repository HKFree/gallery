@props(['months', 'url', 'query' => []])

{{-- Jump list: a select on small screens, a sticky list grouped by year on large screens. --}}
<form method="GET" action="{{ $url }}" data-month-jump class="mb-6 flex gap-2 lg:hidden">
    @foreach ($query as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <label for="timeline-from" class="sr-only">Přejít na měsíc</label>
    <select id="timeline-from" name="from" class="min-w-0 flex-1 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm">
        @foreach ($months as $month)
            <option value="{{ $month['month'] }}" @selected(request('from') === $month['month'])>
                {{ $month['label'] }} ({{ $month['count'] }})
            </option>
        @endforeach
    </select>
    <button type="submit" class="rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Přejít</button>
</form>

<nav aria-label="Měsíce" class="hidden lg:sticky lg:top-4 lg:block lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto">
    @foreach ($months->groupBy('year') as $year => $yearMonths)
        <p class="mt-4 mb-1 text-xs font-semibold tracking-wide text-gray-400 first:mt-0">{{ $year }}</p>
        <ul class="space-y-0.5">
            @foreach ($yearMonths as $month)
                <li>
                    <a href="{{ $url }}?{{ http_build_query([...$query, 'from' => $month['month']]) }}" data-month-link="{{ $month['month'] }}"
                       class="flex justify-between rounded px-2 py-1 text-sm text-gray-600 capitalize hover:bg-gray-100 hover:text-gray-900 aria-[current=true]:bg-gray-900 aria-[current=true]:text-white">
                        <span>{{ \Illuminate\Support\Str::before($month['label'], ' ') }}</span>
                        <span class="text-gray-400">{{ $month['count'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endforeach
</nav>
