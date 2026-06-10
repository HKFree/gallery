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

    const upload = async (fileList) => {
        const files = Array.from(fileList).filter((file) => file.type.startsWith('image/'));
        if (files.length === 0) return;

        const data = new FormData();
        files.forEach((file) => data.append('files[]', file));

        setStatus(`Nahrávám ${files.length} obrázků…`);
        zone.classList.add('pointer-events-none', 'opacity-60');

        try {
            const response = await fetch(uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                body: data,
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            setStatus('Hotovo, načítám…');
            window.location.reload();
        } catch (error) {
            zone.classList.remove('pointer-events-none', 'opacity-60');
            setStatus('Nahrání se nezdařilo. Zkuste to znovu.');
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
