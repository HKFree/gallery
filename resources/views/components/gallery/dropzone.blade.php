@props(['area', 'ap', 'visibility', 'suggestionCount' => 0])
<div class="mb-6">
    <div data-dropzone
         data-upload-url="{{ route('gallery.upload', ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']]) }}"
         data-queue-url="{{ route('gallery.analysis-queue', ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']]) }}"
         data-description-url="{{ route('gallery.description', ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']]) }}"
         data-embedding-url="{{ route('gallery.embedding', ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']]) }}"
         @if (config('services.gallery.scene_model_url')) data-model-host="{{ config('services.gallery.scene_model_url') }}" @endif
         class="cursor-pointer rounded-lg border-2 border-dashed border-gray-300 bg-white px-6 py-8 text-center transition hover:border-emerald-400 hover:bg-emerald-50/40">
        <input type="file" accept="image/*" multiple hidden data-dropzone-input>
        <p class="text-sm text-gray-600">
            Přetáhněte sem obrázky nebo <span class="font-medium text-emerald-700">klikněte pro výběr</span>
        </p>
        <div class="mt-2 flex items-center justify-center gap-2">
            <svg data-dropzone-spinner class="hidden h-4 w-4 animate-spin text-emerald-600" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p data-dropzone-status class="text-xs text-gray-400"></p>
        </div>
    </div>
    <div class="mt-2 flex flex-wrap items-center justify-end gap-x-4 gap-y-1 text-sm">
        <label class="mr-auto inline-flex cursor-pointer items-center gap-1.5 text-gray-600"
               title="Po nahrání rozpozná typ scény, zakrytí výhledu a podobné fotky pro návrhy směru. Poprvé se stáhne asi 120 MB.">
            <input type="checkbox" data-analyse-after-upload class="rounded border-gray-300 text-emerald-700">
            Po nahrání fotky analyzovat
        </label>
        @if ($suggestionCount > 0)
            <form method="POST" action="{{ route('gallery.suggestions.confirm', ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']]) }}" class="inline">
                @csrf
                <button type="submit" class="cursor-pointer font-medium text-amber-800 hover:text-amber-900">Potvrdit návrhy směru ({{ $suggestionCount }})</button>
            </form>
        @endif
        <x-gallery.photo-analysis :area="$area" :ap="$ap" :visibility="$visibility" />
        <a href="{{ route('confluence.import.create', ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']]) }}"
           class="font-medium text-emerald-700 hover:text-emerald-800">Import z Confluence</a>
    </div>
</div>
