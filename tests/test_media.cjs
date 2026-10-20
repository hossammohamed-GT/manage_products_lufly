// Run with: node --test tests/test_media.cjs (no dependencies required).
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../index.php'), 'utf8');
const script = source.split('// ═══ LIGHTBOX ═══')[1].split('// ═══ VERIFY SYSTEM ═══')[0];

function setup(ids = [41, 42]) {
    const listeners = {};
    const elements = {};
    const element = () => ({
        style: {}, dataset: {}, innerHTML: '',
        classList: { add() {}, remove() {} },
        addEventListener() {},
    });
    for (const id of ['lightbox', 'lbImg', 'lbCounter', 'lbActions', 'lbReplaceFile', 'toast']) {
        elements[id] = element();
    }
    const images = ids.map((id, idx) => ({
        src: `assets/${id}.png`, dataset: { mediaId: String(id), idx: String(idx), product: '7' },
        remove() { images.splice(images.indexOf(this), 1); },
        closest(selector) { return selector === '.product-card' ? card : this; },
    }));
    const container = element();
    const count = element();
    let controls = [element(), element(), element()];
    controls.forEach(el => { el.remove = () => { controls = controls.filter(item => item !== el); }; });
    const dots = controls[2];
    const card = {
        querySelector(selector) {
            if (selector === '.card-carousel-imgs') return container;
            if (selector === '.media-count') return count;
            if (selector === '.carousel-dots') return controls.includes(dots) ? dots : null;
            const id = selector.match(/data-media-id="(\d+)"/)[1];
            return images.find(img => img.dataset.mediaId === id);
        },
        querySelectorAll(selector) { return selector === '.lightbox-trigger' ? images : [...controls]; },
    };
    const requests = [];
    let reloads = 0;
    let response = { ok: true };
    const context = {
        document: {
            body: { style: {} },
            getElementById: id => elements[id],
            querySelector: selector => selector.includes('data-id="7"') ? card : null,
            addEventListener: (type, fn) => { listeners[type] = fn; },
        },
        FormData, Blob, csrfToken: 'token', confirm: () => true,
        alert: msg => assert.fail(msg), setTimeout: () => {},
        location: { reload() { reloads++; } },
        fetch: (url, options) => {
            requests.push(options.body);
            return Promise.resolve({ json: () => Promise.resolve(response) });
        },
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(script, context);
    return {
        context, images, elements, container, count, requests,
        get reloads() { return reloads; },
        set response(value) { response = value; },
        open(idx = 0) { listeners.click({ target: images[idx] }); },
    };
}
const finishRequest = () => new Promise(resolve => setImmediate(resolve));

test('rendered images expose their database ID', () => {
    assert.match(source, /data-media-id="'\.\(int\)\$m\['id'\]/);
});

test('delete sends the selected ID and reindexes the card for reopening', async () => {
    const app = setup([41, 42, 43]);
    app.open(1);
    app.context.deleteImage();
    assert.equal(app.requests[0].get('media_id'), '42');
    assert.equal(app.requests[0].get('product_id'), '7');
    await finishRequest();
    assert.deepEqual(app.images.map(img => img.dataset.mediaId), ['41', '43']);
    assert.deepEqual(app.images.map(img => img.dataset.idx), [0, 1]);
    assert.match(app.count.innerHTML, /2$/);
    app.open(1);
    app.context.deleteImage();
    assert.equal(app.requests[1].get('media_id'), '43');
    await finishRequest();
});

test('replacement uses the selected ID even if navigation changes during upload', async () => {
    const app = setup();
    app.open(1);
    app.response = { ok: true, media_id: 42, path: 'uploads/new.png' };
    app.elements.lbReplaceFile.onchange.call({ files: [new Blob(['image'])] });
    assert.equal(app.requests[0].get('media_id'), '42');
    assert.equal(app.requests[0].get('op'), 'replace');
    app.context.lbNav(-1);
    await finishRequest();
    assert.equal(app.images[0].src, 'assets/41.png');
    assert.match(app.images[1].src, /^uploads\/new.png\?t=/);
    assert.equal(app.elements.lbImg.src, 'assets/41.png');
    app.open(1);
    assert.equal(app.elements.lbImg.src, app.images[1].src);
});

test('deleting the last image creates the empty placeholder', async () => {
    const app = setup([41]);
    app.open();
    app.context.deleteImage();
    await finishRequest();
    assert.equal(app.images.length, 0);
    assert.match(app.container.innerHTML, /no-image/);
    assert.match(app.count.innerHTML, /0$/);
    assert.equal(app.context.document.body.style.overflow, '');
});

test('adding a new image does not require a media ID', async () => {
    const app = setup([]);
    app.context.mediaAction('add', 0, 7, new Blob(['image']));
    assert.equal(app.requests[0].get('op'), 'add');
    assert.equal(app.requests[0].has('media_id'), false);
    await finishRequest();
    assert.equal(app.reloads, 1);
});

test('failed delete or replacement leaves the image unchanged', async () => {
    const app = setup();
    app.open();
    app.response = { ok: false, error: 'Upload failed' };
    app.context.deleteImage();
    await finishRequest();
    assert.equal(app.images.length, 2);
    app.context.mediaAction('replace', 41, 7, new Blob(['image']));
    await finishRequest();
    assert.equal(app.images[0].src, 'assets/41.png');
});
