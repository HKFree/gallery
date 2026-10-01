@props(['area', 'ap', 'visibility', 'import' => null])

@php
    $parameters = ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex flex-wrap items-center gap-2']) }}>
    <button type="button" data-scene-tagging
            data-queue-url="{{ route('gallery.scene-queue', [...$parameters, ...($import ? ['import' => $import] : [])]) }}"
            data-description-url="{{ route('gallery.description', $parameters) }}"
            @if (config('services.gallery.scene_model_url')) data-model-host="{{ config('services.gallery.scene_model_url') }}" @endif
            title="Model běží ve vašem prohlížeči; poprvé se stáhne asi 90 MB."
            class="cursor-pointer font-medium text-emerald-700 hover:text-emerald-800 disabled:cursor-wait disabled:opacity-60">
        Rozpoznat typ scény
    </button>
    <span data-scene-status class="text-xs text-gray-500"></span>
</span>
