// Photo analysis in the manager's browser, for each photo that still needs it:
// - scene type: CLIP zero-shot classification against fixed prompts, each mapped to a scene key;
// - image embedding: DINOv2, which the server compares to suggest view directions from similar
//   photos of the same AP;
// - obstruction: SegFormer (ADE20K) segmentation, measuring how much of the view near trees or
//   other obstacles block.
// The library and models (~120 MB, then cached by the browser) load only when analysis starts.

const SCENE_MODEL = 'Xenova/clip-vit-base-patch32';
const EMBEDDING_MODEL = 'onnx-community/dinov2-small';
const SEGMENTATION_MODEL = 'Xenova/segformer-b0-finetuned-ade-512-512';

const PROMPTS = {
    'a view over rooftops of a town from a high building': 'zastavba',
    'a view of block of flats, a housing estate': 'sidliste',
    'a view of fields and countryside from a height': 'krajina',
    'a view of a forest from a height': 'les',
    'radio antennas and dishes mounted on a mast or roof': 'anteny',
    'an open electrical or network cabinet with cables': 'technika',
    'a close-up of a house roof': 'strecha',
};

const TREES = new Set(['tree', 'plant', 'palm', 'flower']);
const OBSTRUCTIONS = new Set([...TREES, 'wall', 'fence', 'railing', 'pole', 'column']);

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

// Share of the view's width blocked by near obstacles. Per column, the skyline is the first
// non-sky pixel from the top; most columns reach down to the far view, so a low percentile of
// skylines is the photo's horizon. A column is blocked when an obstacle rises well above it: a
// distant tree line stays at the horizon, a tree in front of the camera sticks up into the sky.
function measureObstruction(segments) {
    const { width, height } = segments[0].mask;
    const labels = new Array(width * height).fill('');

    for (const segment of segments) {
        segment.mask.data.forEach((value, index) => {
            if (value > 0) labels[index] = segment.label;
        });
    }

    const sky = labels.filter((label) => label === 'sky').length;
    if (sky / labels.length < 0.1) return { kind: 'unknown' };

    const skylines = [];
    for (let x = 0; x < width; x++) {
        let y = 0;
        while (y < height && labels[y * width + x] === 'sky') y++;
        skylines.push(y);
    }

    const horizon = [...skylines].sort((a, b) => a - b)[Math.floor(width * 0.75)];
    let blocked = 0;
    let trees = 0;

    skylines.forEach((y, x) => {
        const label = labels[y * width + x];
        if (y < height && horizon - y > 0.08 * height && OBSTRUCTIONS.has(label)) {
            blocked++;
            if (TREES.has(label)) trees++;
        }
    });

    return { kind: trees >= blocked / 2 ? 'trees' : 'other', share: blocked / width };
}

/**
 * Analyse the photos the queue URL lists. Returns how many were analysed.
 *
 * @param {{queueUrl: string, descriptionUrl: string, embeddingUrl: string, modelHost?: string}} urls
 * @param {(text: string) => void} setStatus
 */
export async function analysePhotos(urls, setStatus) {
    setStatus('Načítám seznam fotek…');
    const queue = await (await fetch(urls.queueUrl, { headers: { Accept: 'application/json' } })).json();

    if (queue.length === 0) return 0;

    setStatus('Načítám modely (poprvé až 120 MB)…');
    const { pipeline, env } = await import('@huggingface/transformers');

    if (urls.modelHost) env.remoteHost = urls.modelHost;

    const load = (needed, ...args) => (queue.some((photo) => photo[needed]) ? pipeline(...args) : null);
    const classify = await load('scene', 'zero-shot-image-classification', SCENE_MODEL);
    const embed = await load('embedding', 'image-feature-extraction', EMBEDDING_MODEL, { dtype: 'q8' });
    const segment = await load('obstruction', 'image-segmentation', SEGMENTATION_MODEL, { dtype: 'q8' });

    for (const [index, photo] of queue.entries()) {
        setStatus(`Analyzuji ${index + 1} / ${queue.length}…`);
        const facts = {};

        if (photo.scene) {
            const [best] = await classify(photo.thumb_url, Object.keys(PROMPTS), { hypothesis_template: '{}' });
            facts.scene = PROMPTS[best.label];
            facts.score = best.score.toFixed(3);
        }

        if (photo.obstruction) {
            const { kind, share } = measureObstruction(await segment(photo.thumb_url));
            facts.obstruction_kind = kind;
            if (share !== undefined) facts.obstruction = share.toFixed(3);
        }

        if (Object.keys(facts).length > 0) {
            await post(urls.descriptionUrl, { filename: photo.filename, ...facts });
        }

        if (photo.embedding) {
            // DINOv2 returns one vector per image patch; the first (CLS) describes the whole photo.
            const output = await embed(photo.thumb_url);
            const size = output.dims[output.dims.length - 1];
            await post(urls.embeddingUrl, {
                filename: photo.filename,
                model: EMBEDDING_MODEL,
                vector: toBase64(output.data.slice(0, size)),
            });
        }
    }

    return queue.length;
}

document.addEventListener('click', async (e) => {
    const button = e.target.closest('[data-photo-analysis]');
    if (!button) return;

    const status = button.parentElement.querySelector('[data-analysis-status]');
    const setStatus = (text) => {
        if (status) status.textContent = text;
    };

    button.disabled = true;

    try {
        const count = await analysePhotos(button.dataset, setStatus);
        setStatus(count === 0
            ? 'Všechny fotky už jsou analyzované.'
            : `Hotovo (${count}). Popisy a návrhy směru uvidíte po obnovení stránky.`);
    } catch (error) {
        console.error(error);
        setStatus('Analýza se nezdařila.');
    } finally {
        button.disabled = false;
    }
});
