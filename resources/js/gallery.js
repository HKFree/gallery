// Gallery drag & drop upload and soft-delete, plain vanilla JS (no framework).

// Slice uploads into 1 MB chunks so each request stays well under PHP's
// upload_max_filesize / post_max_size; the server reassembles them.
const CHUNK_SIZE = 1024 * 1024;

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

function initDropzone(zone) {
    const input = zone.querySelector('[data-dropzone-input]');
    const status = zone.querySelector('[data-dropzone-status]');
    const spinner = zone.querySelector('[data-dropzone-spinner]');
    const uploadUrl = zone.dataset.uploadUrl;

    const setStatus = (text) => {
        if (status) status.textContent = text;
    };

    // Toggle the zone's busy state: disable interaction and show the spinner.
    const setBusy = (busy) => {
        zone.classList.toggle('pointer-events-none', busy);
        zone.classList.toggle('opacity-60', busy);
        spinner?.classList.toggle('hidden', !busy);
    };

    // Pull the human-readable reason out of a failed JSON response.
    const errorMessage = async (response) => {
        if (response.status === 413) return 'soubor je příliš velký.';

        try {
            const body = await response.json();
            if (body.errors) return Object.values(body.errors).flat().join(' ');

            return body.message || `HTTP ${response.status}`;
        } catch {
            return `HTTP ${response.status}`;
        }
    };

    // Upload a single file as a sequence of chunks under one upload id.
    // Returns an error string on failure, or null on success.
    const uploadFile = async (file, label) => {
        const uploadId = crypto.randomUUID();
        const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));

        for (let index = 0; index < totalChunks; index++) {
            const chunk = file.slice(index * CHUNK_SIZE, (index + 1) * CHUNK_SIZE);
            const percent = Math.round(((index + 1) / totalChunks) * 100);
            setStatus(`Nahrávám ${label} (${percent} %)…`);

            const data = new FormData();
            data.append('chunk', chunk, file.name);
            data.append('upload_id', uploadId);
            data.append('chunk_index', index);
            data.append('total_chunks', totalChunks);
            data.append('filename', file.name);

            try {
                const response = await fetch(uploadUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    body: data,
                    redirect: 'error',
                });

                if (!response.ok) {
                    return `${file.name}: ${await errorMessage(response)}`;
                }
            } catch (error) {
                return `${file.name}: nahrání se nezdařilo.`;
            }
        }

        return null;
    };

    const upload = async (fileList) => {
        const all = Array.from(fileList);
        const files = all.filter((file) => file.type.startsWith('image/'));
        const skipped = all.length - files.length;

        if (files.length === 0) {
            setStatus(skipped > 0 ? 'Vyberte prosím obrázky (JPEG, PNG, GIF, WebP).' : '');
            return;
        }

        setBusy(true);

        let uploaded = 0;
        const errors = [];

        // Upload one file at a time; each is chunked so a large/invalid file can't fail
        // the whole batch and every request stays under post_max_size.
        for (const [index, file] of files.entries()) {
            const error = await uploadFile(file, `${index + 1}/${files.length}`);

            if (error) {
                errors.push(error);
            } else {
                uploaded++;
            }
        }

        if (skipped > 0) {
            errors.push(`${skipped} souborů nejsou obrázky a byly přeskočeny.`);
        }

        if (errors.length > 0) {
            setBusy(false);
            setStatus(`Nahráno ${uploaded}/${files.length}, chyb: ${errors.length}.`);
            window.alert('Některé soubory se nepodařilo nahrát:\n\n' + errors.join('\n'));
        }

        if (uploaded > 0) {
            setStatus('Hotovo, načítám…');
            window.location.reload();
        }
    };

    zone.addEventListener('click', () => input?.click());
    input?.addEventListener('change', () => upload(input.files));

    ['dragenter', 'dragover'].forEach((event) =>
        zone.addEventListener(event, (e) => {
            e.preventDefault();
            zone.classList.add('border-emerald-400', 'bg-emerald-50/40');
        })
    );

    ['dragleave', 'drop'].forEach((event) =>
        zone.addEventListener(event, (e) => {
            e.preventDefault();
            zone.classList.remove('border-emerald-400', 'bg-emerald-50/40');
        })
    );

    zone.addEventListener('drop', (e) => {
        if (e.dataTransfer?.files?.length) upload(e.dataTransfer.files);
    });
}

function initDelete(button) {
    button.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!window.confirm('Přesunout obrázek do koše?')) return;

        try {
            const response = await fetch(button.dataset.deleteUrl, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            button.closest('[data-image]')?.remove();
        } catch (error) {
            window.alert('Smazání se nezdařilo.');
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-dropzone]').forEach(initDropzone);
    document.querySelectorAll('[data-delete-url]').forEach(initDelete);
});
