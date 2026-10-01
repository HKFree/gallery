// Gallery drag & drop upload and soft-delete, plain vanilla JS (no framework).

import { analysePhotos } from './photo-analysis';

// Whether to analyse photos right after uploading them (remembered per browser; the first
// analysis downloads the models, so it is opt-in).
const ANALYSE_AFTER_UPLOAD = 'gallery.analyseAfterUpload';

const remembered = (key) => {
    try {
        return window.localStorage.getItem(key) === '1';
    } catch {
        return false;
    }
};

const remember = (key, value) => {
    try {
        window.localStorage.setItem(key, value ? '1' : '0');
    } catch {
        // Storage unavailable (private mode): the choice just isn't remembered.
    }
};

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
    const analyseToggle = zone.parentElement.querySelector('[data-analyse-after-upload]');

    if (analyseToggle) {
        analyseToggle.checked = remembered(ANALYSE_AFTER_UPLOAD);
        analyseToggle.addEventListener('change', () => remember(ANALYSE_AFTER_UPLOAD, analyseToggle.checked));
    }

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
    // Returns {error} on failure, or {filename} (as stored) on success.
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
            // Often the capture date; the server's fallback when the image has no EXIF date.
            data.append('client_modified_at', file.lastModified);

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
                    return { error: `${file.name}: ${await errorMessage(response)}` };
                }

                if (index === totalChunks - 1) {
                    return { filename: (await response.json()).filename };
                }
            } catch (error) {
                return { error: `${file.name}: nahrání se nezdařilo.` };
            }
        }

        return { error: `${file.name}: nahrání se nezdařilo.` };
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

        const uploaded = [];
        const errors = [];

        // Upload one file at a time; each is chunked so a large/invalid file can't fail
        // the whole batch and every request stays under post_max_size.
        for (const [index, file] of files.entries()) {
            const { error, filename } = await uploadFile(file, `${index + 1}/${files.length}`);

            if (error) {
                errors.push(error);
            } else {
                uploaded.push(filename);
            }
        }

        if (skipped > 0) {
            errors.push(`${skipped} souborů nejsou obrázky a byly přeskočeny.`);
        }

        if (errors.length > 0) {
            setBusy(false);
            setStatus(`Nahráno ${uploaded.length}/${files.length}, chyb: ${errors.length}.`);
            window.alert('Některé soubory se nepodařilo nahrát:\n\n' + errors.join('\n'));
        }

        if (uploaded.length > 0 && analyseToggle?.checked) {
            try {
                const queue = new URL(zone.dataset.queueUrl, window.location.href);
                uploaded.forEach((filename) => queue.searchParams.append('files[]', filename));
                await analysePhotos({ ...zone.dataset, queueUrl: queue.toString() }, setStatus);
            } catch (error) {
                console.error(error);
                window.alert('Fotky jsou nahrané, ale jejich analýza se nezdařila. Spusťte ji tlačítkem „Analyzovat fotky“.');
            }
        }

        if (uploaded.length > 0) {
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

// Delegated, so delete buttons on tiles appended later (timeline pages) work too.
async function handleDelete(button) {
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
}

document.addEventListener('click', (e) => {
    const button = e.target.closest('[data-delete-url]');
    if (!button) return;

    e.preventDefault();
    handleDelete(button);
});

// Set a photo's view direction from the compass on its tile, and show the new description.
document.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-heading-form]');
    if (!form) return;

    e.preventDefault();

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: new FormData(form, e.submitter),
        });

        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const { description } = await response.json();
        const figure = form.closest('[data-image]');
        const image = figure.querySelector('img');
        let text = figure.querySelector('[data-description]');

        if (description) {
            if (!text) {
                text = document.createElement('p');
                text.dataset.description = '';
                text.className = 'line-clamp-2 px-2 pb-1.5 text-xs leading-snug text-gray-600';
                figure.append(text);
            }
            text.textContent = description;
        } else {
            text?.remove();
        }

        image.alt = description ?? image.dataset.name;
        image.title = description ?? '';
        figure.querySelector('[data-suggestion]')?.remove();
        const details = form.closest('details');
        if (details) details.open = false;
    } catch {
        window.alert('Uložení směru se nezdařilo.');
    }
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-dropzone]').forEach(initDropzone);
});
