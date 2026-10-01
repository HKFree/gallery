// Photo analysis in the manager's browser, for each photo that still needs it:
// - scene type: CLIP zero-shot classification against fixed prompts, each mapped to a scene key;
// - image embedding: DINOv2, which the server compares to suggest view directions from similar
//   photos of the same AP.
// The library and models (~115 MB, then cached by the browser) load only when a manager starts it.

const SCENE_MODEL = 'Xenova/clip-vit-base-patch32';
const EMBEDDING_MODEL = 'onnx-community/dinov2-small';

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

const post = async (url, fields) => {
    const data = new FormData();
    Object.entries(fields).forEach(([name, value]) => data.append(name, value));

    const response = await fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
        body: data,
    });

    if (!response.ok) throw new Error(`HTTP ${response.status}`);
};

// Float32 values as base64 of their little-endian bytes (what the server unpacks).
const toBase64 = (values) => {
    const bytes = new Uint8Array(Float32Array.from(values).buffer);
    let binary = '';
    bytes.forEach((byte) => (binary += String.fromCharCode(byte)));
    return btoa(binary);
};

async function analyse(button) {
    const status = button.parentElement.querySelector('[data-analysis-status]');
    const setStatus = (text) => {
        if (status) status.textContent = text;
    };

    button.disabled = true;

    try {
        setStatus('Načítám seznam fotek…');
        const queue = await (await fetch(button.dataset.queueUrl, { headers: { Accept: 'application/json' } })).json();

        if (queue.length === 0) {
            setStatus('Všechny fotky už jsou analyzované.');
            return;
        }

        setStatus('Načítám modely (poprvé až 115 MB)…');
        const { pipeline, env } = await import('@huggingface/transformers');

        if (button.dataset.modelHost) {
            env.remoteHost = button.dataset.modelHost;
        }

        const classify = queue.some((photo) => photo.scene)
            ? await pipeline('zero-shot-image-classification', SCENE_MODEL)
            : null;
        const embed = queue.some((photo) => photo.embedding)
            ? await pipeline('image-feature-extraction', EMBEDDING_MODEL, { dtype: 'q8' })
            : null;

        for (const [index, photo] of queue.entries()) {
            setStatus(`Analyzuji ${index + 1} / ${queue.length}…`);

            if (photo.scene) {
                const [best] = await classify(photo.thumb_url, Object.keys(PROMPTS), { hypothesis_template: '{}' });
                await post(button.dataset.descriptionUrl, {
                    filename: photo.filename,
                    scene: PROMPTS[best.label],
                    score: best.score.toFixed(3),
                });
            }

            if (photo.embedding) {
                // DINOv2 returns one vector per image patch; the first (CLS) describes the whole photo.
                const output = await embed(photo.thumb_url);
                const size = output.dims[output.dims.length - 1];
                await post(button.dataset.embeddingUrl, {
                    filename: photo.filename,
                    model: EMBEDDING_MODEL,
                    vector: toBase64(output.data.slice(0, size)),
                });
            }
        }

        setStatus(`Hotovo (${queue.length}). Popisy a návrhy směru uvidíte po obnovení stránky.`);
    } catch (error) {
        console.error(error);
        setStatus('Analýza se nezdařila.');
    } finally {
        button.disabled = false;
    }
}

document.addEventListener('click', (e) => {
    const button = e.target.closest('[data-photo-analysis]');
    if (button) analyse(button);
});
