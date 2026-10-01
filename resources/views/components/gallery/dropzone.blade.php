@props(['area', 'ap', 'visibility'])
<div class="mb-6">
    <div data-dropzone
         data-upload-url="{{ route('gallery.upload', ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']]) }}"
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
    <p class="mt-2 text-right text-sm">
        <a href="{{ route('confluence.import.create', ['visibility' => $visibility, 'area' => $area['id'], 'ap' => $ap['id']]) }}"
           class="font-medium text-emerald-700 hover:text-emerald-800">Import z Confluence</a>
    </p>
</div>
