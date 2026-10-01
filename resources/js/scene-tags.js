// Scene recognition in the manager's browser: CLIP zero-shot classification of the gallery's
// thumbnails against fixed prompts, each mapped to a scene key the server knows. The library
// and the model (~90 MB, then cached by the browser) load only when a manager starts it.

const MODEL = 'Xenova/clip-vit-base-patch32';

const PROMPTS = {
    'a view over rooftops of a town from a high building': 'zastavba',
    'a view of block of flats, a housing estate': 'sidliste',
    'a view of fields and countryside from a height': 'krajina',
    'a view of a forest from a height': 'les',
    'radio antennas and dishes mounted on a mast or roof': 'anteny',
    'an open electrical or network cabinet with cables': 'technika',
    'a close-up of a house roof': 'strecha',
};

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

async function tagScenes(button) {
    const status = button.parentElement.querySelector('[data-scene-status]');
    const setStatus = (text) => {
        if (status) status.textContent = text;
    };

    button.disabled = true;

    try {
        setStatus('Načítám seznam fotek…');
        const queue = await (await fetch(button.dataset.queueUrl, { headers: { Accept: 'application/json' } })).json();

        if (queue.length === 0) {
            setStatus('Všechny fotky už typ scény mají.');
            return;
        }

        setStatus('Načítám model (poprvé až 90 MB)…');
        const { pipeline, env } = await import('@huggingface/transformers');

        if (button.dataset.modelHost) {
            env.remoteHost = button.dataset.modelHost;
        }

        const classify = await pipeline('zero-shot-image-classification', MODEL);
        let done = 0;

        for (const item of queue) {
            setStatus(`Rozpoznávám ${done + 1} / ${queue.length}…`);

            const [best] = await classify(item.thumb_url, Object.keys(PROMPTS), { hypothesis_template: '{}' });
            const data = new FormData();
            data.append('filename', item.filename);
            data.append('scene', PROMPTS[best.label]);
            data.append('score', best.score.toFixed(3));

            const response = await fetch(button.dataset.descriptionUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
                body: data,
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            done++;
        }

        setStatus(`Hotovo (${done}). Popisy uvidíte po obnovení stránky.`);
    } catch (error) {
        console.error(error);
        setStatus('Rozpoznání se nezdařilo.');
    } finally {
        button.disabled = false;
    }
}

document.addEventListener('click', (e) => {
    const button = e.target.closest('[data-scene-tagging]');
    if (button) tagScenes(button);
});
