@props(['sections', 'nextUrl' => null, 'canManage' => false])

@foreach ($sections as $section)
    <section data-month="{{ $section['month'] }}" class="mb-8">
        <h2 data-month-heading class="sticky top-0 z-10 -mx-2 mb-3 flex items-baseline gap-2 bg-gray-50/95 px-2 py-2 text-lg font-semibold backdrop-blur">
            {{ $section['label'] }}
            <span class="text-sm font-normal text-gray-400">{{ $section['count'] }}</span>
        </h2>
        <div data-month-images class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
            @foreach ($section['images'] as $image)
                <x-gallery.tile :image="$image" :can-manage="$canManage" />
            @endforeach
        </div>
    </section>
@endforeach

@if ($nextUrl)
    <div data-timeline-more class="py-6 text-center">
        <a data-timeline-next href="{{ $nextUrl }}"
           class="inline-flex rounded-md border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:border-gray-300 hover:shadow-sm">
            Starší
        </a>
    </div>
@endif
