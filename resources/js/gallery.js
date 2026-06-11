// Gallery drag & drop upload and soft-delete, plain vanilla JS (no framework).

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

function initDropzone(zone) {
    const input = zone.querySelector('[data-dropzone-input]');
    const status = zone.querySelector('[data-dropzone-status]');
    const uploadUrl = zone.dataset.uploadUrl;

    const setStatus = (text) => {
        if (status) status.textContent = text;
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

    const upload = async (fileList) => {
        const all = Array.from(fileList);
        const files = all.filter((file) => file.type.startsWith('image/'));
        const skipped = all.length - files.length;

        if (files.length === 0) {
            setStatus(skipped > 0 ? 'Vyberte prosím obrázky (JPEG, PNG, GIF, WebP).' : '');
            return;
        }

        zone.classList.add('pointer-events-none', 'opacity-60');

        let uploaded = 0;
        const errors = [];

        // Upload one file per request so a large/invalid file can't fail the whole batch
        // (and each request stays under post_max_size).
        for (const file of files) {
            setStatus(`Nahrávám ${uploaded + 1}/${files.length}…`);

            const data = new FormData();
            data.append('files[]', file);

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
                    errors.push(`${file.name}: ${await errorMessage(response)}`);
                    continue;
                }

                uploaded++;
            } catch (error) {
                errors.push(`${file.name}: nahrání se nezdařilo.`);
            }
        }

        if (skipped > 0) {
            errors.push(`${skipped} souborů nejsou obrázky a byly přeskočeny.`);
        }

        if (errors.length > 0) {
            zone.classList.remove('pointer-events-none', 'opacity-60');
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
