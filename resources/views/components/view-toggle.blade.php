@props(['mode', 'gridUrl', 'timelineUrl'])

<nav aria-label="Zobrazení" {{ $attributes->merge(['class' => 'inline-flex rounded-lg border border-gray-200 bg-white p-1 text-sm']) }}>
    @foreach (['grid' => ['Mřížka', $gridUrl], 'timeline' => ['Časová osa', $timelineUrl]] as $key => [$label, $url])
        <a href="{{ $url }}"
           @if ($mode === $key) aria-current="page" @endif
           @class([
               'rounded-md px-3 py-1.5 font-medium',
               'bg-gray-900 text-white' => $mode === $key,
               'text-gray-600 hover:bg-gray-100' => $mode !== $key,
           ])>{{ $label }}</a>
    @endforeach
</nav>
