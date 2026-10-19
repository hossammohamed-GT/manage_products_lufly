/**
 * Isolated PHP 8.3 + SQLite request tests. All writes happen in WebAssembly memory.
 * Install optional test dependencies outside the repository:
 * npm install --prefix /tmp/lufly-tests @php-wasm/node @php-wasm/universal jsdom@26
 * LUFLY_TEST_MODULES=/tmp/lufly-tests/node_modules node tests/test_app_integration.cjs
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const modules = process.env.LUFLY_TEST_MODULES;
const dependency = name => require(modules ? path.join(modules, name) : name);
const { PHP, PHPRequestHandler, setPhpIniEntries } = dependency('@php-wasm/universal');
const { loadNodeRuntime } = dependency('@php-wasm/node');
const { JSDOM, VirtualConsole } = dependency('jsdom');
const root = path.resolve(__dirname, '..');
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=', 'base64');
const phpVersion = process.env.LUFLY_TEST_PHP_VERSION || '8.3';
let php, server, csrf, passed = 0;
async function check(label, fn) {
    await fn();
    console.log('PASS ' + label);
    passed++;
}
async function request(action = 'home', body) {
    const response = await server.request({
        url: '/index.php?action=' + action,
        ...(body ? { method: 'POST', body: { csrf, ...body } } : {}),
    });
    assert.equal(response.errors, '', response.errors);
    assert.equal(response.exitCode, 0, response.text);
    return response;
}
async function post(action, body = {}, ok = true) {
    const response = await request(action, body);
    assert.equal(response.httpStatusCode, ok ? 200 : 400, response.text);
    assert.equal(response.json.ok, ok, response.text);
    return response.json;
}
async function evaluate(code) {
    const response = await php.run({ code: '<?php require "/app/app.php"; require_once "/app/includes/image_enhancement.php"; $d=db(); ' + code });
    assert.equal(response.errors, '', response.errors);
    assert.equal(response.exitCode, 0, response.text);
    return response.text ? JSON.parse(response.text) : undefined;
}
async function product(id, code = 'TEST-' + id) {
    await evaluate(`run($d, 'INSERT INTO products (id,code,description,notes,source_extra,created_at,updated_at) VALUES (?,?,?,?,?,?,?)', [${id}, '${code}', 'Test product', '', '{}', date('c'), date('c')]);`);
}
async function upload(id, op = 'add', mediaId = 0, bytes = png) {
    return post('media_action', { product_id: String(id), op, media_id: String(mediaId), file: new File([bytes], 'image.png', { type: 'image/png' }) });
}
async function main() {
    const runtimePackage = '@php-wasm/node-' + phpVersion.replace('.', '-');
    const wasmRoot = path.dirname(require.resolve(modules ? path.join(modules, runtimePackage) : runtimePackage));
    php = new PHP(await loadNodeRuntime(phpVersion, { emscriptenOptions: {
        processId: 1,
        locateFile: file => path.isAbsolute(file) ? file : path.join(wasmRoot, 'asyncify', file),
    } }));
    await setPhpIniEntries(php, {error_log:'/tmp/lufly-test-errors.log'});
    php.mkdir('/app/includes');
    php.mkdir('/app/tools/image_enhancement');
    php.mkdir('/app/tools');
    php.mkdir('/app/data');
    php.mkdir('/app/uploads');
    php.mkdir('/app/assets/catalog');
    for (const file of ['app.php', 'config.php', 'index.php', 'login.php', 'tools/export_local_json.php', 'tools/image_enhancement/setup_config.php', 'includes/sidebar.php', 'includes/catalog_storage.php', 'includes/image_enhancement.php', 'data/catalog.json', '.env.example']) {
        php.writeFile('/app/' + file, fs.readFileSync(path.join(root, file)));
    }
    php.writeFile('/app/config.local.php', `<?php return ['storage_mode'=>'database','driver'=>'sqlite','sqlite_path'=>'/app/data/test.sqlite','app_password'=>'integration-test-only'];`);
    server = new PHPRequestHandler({ php, documentRoot: '/app', absoluteUrl: 'http://lufly.test' });

    await check('authentication rejects anonymous requests and incorrect password', async () => {
        assert.equal((await request()).httpStatusCode, 302);
        const response = await server.request({ url: '/login.php', method: 'POST', body: { password: 'wrong' } });
        assert.match(response.text, /كلمة المرور غير صحيحة/);
        assert.equal((await request()).httpStatusCode, 302);
    });
    await check('login, clean home render, automatic install and CSRF metadata', async () => {
        const login = await server.request({ url: '/login.php', method: 'POST', body: { password: 'integration-test-only' } });
        assert.equal(login.httpStatusCode, 302);
        assert.equal(login.errors, '');
        const response = await request();
        assert.equal(response.httpStatusCode, 200);
        assert.ok(response.text.trimStart().startsWith('<!DOCTYPE html>'));
        csrf = response.text.match(/<meta name="csrf" content="([a-f0-9]+)"/)[1];
        assert.equal(csrf.length, 64);
        assert.equal(await evaluate("echo json_encode((int)$d->query('SELECT COUNT(*) FROM products')->fetchColumn());"), 715);
    });
    await check('all PHP entry files pass syntax parsing', async () => {
        for (const file of ['app.php', 'config.php', 'index.php', 'login.php', 'tools/export_local_json.php', 'tools/image_enhancement/setup_config.php', 'includes/sidebar.php', 'includes/catalog_storage.php', 'includes/image_enhancement.php']) {
            const code = Buffer.from(fs.readFileSync(path.join(root, file))).toString('base64');
            await evaluate(`token_get_all(base64_decode('${code}'), TOKEN_PARSE);`);
        }
    });
    await product(100001);
    await product(100002);
    await check('search and group pages render without undefined functions', async () => {
        for (const action of ['search&q=TEST-100001', 'group&prefix=TEST']) {
            const response = await request(action);
            assert.match(response.text, /data-id="100001"/);
            assert.doesNotMatch(response.text, /class="error-box"/);
        }
        const missing = await request('search&q=THIS-CODE-DOES-NOT-EXIST');
        assert.match(missing.text, /لا توجد منتجات/);
    });
    await check('pagination clamps out-of-range page numbers', async () => {
        const response = await request('home&page=999999');
        assert.match(response.text, /data-id="100002"/);
        assert.doesNotMatch(response.text, /class="error-box"/);
    });
    await check('missing or wrong CSRF cannot delete products', async () => {
        await post('delete', { id: '100001', csrf: '' }, false);
        await post('delete', { id: '100001', csrf: 'wrong' }, false);
        assert.equal(await evaluate("echo json_encode((int)run($d,'SELECT COUNT(*) FROM products WHERE id=?',[100001])->fetchColumn());"), 1);
    });
    await check('inline edits persist, allow clearing text and validate input', async () => {
        await post('inline_save', { id: '100001', field: 'description', value: 'Updated description' });
        await post('inline_save', { id: '100001', field: 'description', value: '' });
        assert.equal(await evaluate("echo json_encode(run($d,'SELECT description FROM products WHERE id=?',[100001])->fetchColumn());"), '');
        await post('inline_save', { id: '100001', field: 'status', value: 'invalid' }, false);
        await post('inline_save', { id: '100001', field: 'code', value: 'x'.repeat(191) }, false);
        await post('inline_save', { id: '100001', field: 'id', value: '100002' }, false);
        await post('inline_save', { id: '999999', field: 'description', value: 'invalid' }, false);
    });
    await check('single and bulk verification persist; invalid IDs are rejected', async () => {
        assert.equal((await post('toggle_verify', { id: '100001' })).verified, 1);
        assert.equal((await post('toggle_verify', { id: '100001' })).verified, 0);
        assert.equal((await post('bulk_verify', { ids: '[100001,100001,100002]' })).count, 2);
        await post('toggle_verify', { id: '999999' }, false);
        for (const ids of ['[]', 'null', '["100001"]', '[-1]', '[100001,999999]']) {
            await post('bulk_verify', { ids }, false);
        }
    });
    let media;
    await check('actual multipart image upload and replacement succeed', async () => {
        media = await upload(100001);
        assert.ok(media.media_id > 0);
        assert.ok(php.fileExists('/app/' + media.path));
        const replaced = await upload(100001, 'replace', media.media_id);
        assert.equal(replaced.media_id, media.media_id);
        assert.ok(php.fileExists('/app/' + replaced.path));
        assert.equal(php.fileExists('/app/' + media.path), false);
        media = replaced;
    });
    await check('image ownership, missing files and invalid images are rejected', async () => {
        await post('media_action', { product_id: '100002', op: 'delete', media_id: String(media.media_id) }, false);
        await post('media_action', { product_id: '100001', op: 'replace', media_id: '0' }, false);
        await post('media_action', { product_id: '999999', op: 'add' }, false);
        await post('media_action', { product_id: '100001', op: 'add' }, false);
        await post('media_action', { product_id: '100001', op: 'add', file: new File(['not an image'], 'bad.png') }, false);
        assert.ok(php.fileExists('/app/' + media.path));
    });
    await check('failed media inserts clean up the uploaded file', async () => {
        const before = await evaluate("echo json_encode(glob('/app/uploads/*'));" );
        await evaluate("$d->exec(\"CREATE TRIGGER prevent_media BEFORE INSERT ON media BEGIN SELECT RAISE(ABORT, 'test rollback'); END\");");
        await post('media_action', { product_id: '100001', op: 'add', file: new File([png], 'image.png') }, false);
        await evaluate("$d->exec('DROP TRIGGER prevent_media');");
        const after = await evaluate("echo json_encode(glob('/app/uploads/*'));" );
        assert.deepEqual(after, before);
    });
    await check('archive hides products, blocks edits, and restore brings them back', async () => {
        await post('archive', { id: '100001' });
        const home = await request();
        assert.doesNotMatch(home.text, /data-id="100001"/);
        const archive = await request('archive_list');
        assert.match(archive.text, /restoreProduct\(100001\)/);
        await post('inline_save', { id: '100001', field: 'description', value: 'Blocked' }, false);
        await post('toggle_verify', { id: '100001' }, false);
        await post('media_action', { product_id: '100001', op: 'delete', media_id: String(media.media_id) }, false);
        await post('restore', { id: '100001' });
        assert.match((await request('group&prefix=TEST')).text, /data-id="100001"/);
    });
    await check('deleting an image removes its database row and file', async () => {
        await post('media_action', { product_id: '100001', op: 'delete', media_id: String(media.media_id) });
        assert.equal(php.fileExists('/app/' + media.path), false);
        assert.equal(await evaluate(`echo json_encode((int)run($d,'SELECT COUNT(*) FROM media WHERE id=?',[${media.media_id}])->fetchColumn());`), 0);
    });
    await check('shared files survive product deletion until the last reference is deleted', async () => {
        const shared = await upload(100001);
        await evaluate(`run($d,'INSERT INTO media (product_id,path,original_name,kind) VALUES (?,?,?,?)',[100002,'${shared.path}','shared.png','photo']);`);
        await post('delete', { id: '100001' });
        assert.ok(php.fileExists('/app/' + shared.path));
        assert.equal(await evaluate("echo json_encode((int)run($d,'SELECT COUNT(*) FROM media WHERE product_id=?',[100001])->fetchColumn());"), 0);
        await post('delete', { id: '100002' });
        assert.equal(php.fileExists('/app/' + shared.path), false);
        await post('delete', { id: '100002' }, false);
        await post('archive', { id: '0' }, false);
        await post('restore', { id: '999999' }, false);
    });
    await check('failed database write rolls back deletion and preserves the image', async () => {
        await product(100003);
        const image = await upload(100003);
        await evaluate("$d->exec(\"CREATE TRIGGER prevent_delete BEFORE DELETE ON products WHEN OLD.id=100003 BEGIN SELECT RAISE(ABORT, 'test rollback'); END\");");
        await post('delete', { id: '100003' }, false);
        assert.ok(php.fileExists('/app/' + image.path));
        assert.equal(await evaluate("echo json_encode((int)run($d,'SELECT COUNT(*) FROM media WHERE product_id=?',[100003])->fetchColumn());"), 1);
        await evaluate("$d->exec('DROP TRIGGER prevent_delete');");
        await post('delete', { id: '100003' });
        assert.equal(php.fileExists('/app/' + image.path), false);
    });
    await check('shared files also survive replacement of another product image', async () => {
        await product(100005);
        await product(100006);
        const image = await upload(100005);
        await evaluate(`run($d,'INSERT INTO media (product_id,path,original_name,kind) VALUES (?,?,?,?)',[100006,'${image.path}','shared.png','photo']);`);
        const replaced = await upload(100005, 'replace', image.media_id);
        assert.ok(php.fileExists('/app/' + image.path));
        await post('archive', { id: '100005' });
        await post('delete', { id: '100005' });
        assert.equal(php.fileExists('/app/' + replaced.path), false);
        assert.ok(php.fileExists('/app/' + image.path));
        await post('delete', { id: '100006' });
        assert.equal(php.fileExists('/app/' + image.path), false);
    });
    await check('full product saves create, update, reject stale versions and remove attachments', async () => {
        const id = await evaluate("$_POST=['code'=>'SAVE-TEST','description'=>'Saved','status'=>'Running']; echo json_encode(saveProduct($d));");
        const image = await upload(id);
        await evaluate(`$_POST=['id'=>${id},'version'=>1,'code'=>'SAVE-UPDATED','description'=>'Saved','status'=>'Running','remove_media'=>[${image.media_id}=>1]]; saveProduct($d);`);
        assert.equal(php.fileExists('/app/' + image.path), false);
        const stale = await evaluate(`$_POST=['id'=>${id},'version'=>1,'code'=>'STALE','description'=>'Stale','status'=>'Running']; try { saveProduct($d); echo json_encode(false); } catch (RuntimeException $e) { echo json_encode(true); }`);
        assert.equal(stale, true);
        assert.equal(await evaluate(`echo json_encode(run($d,'SELECT code FROM products WHERE id=?',[${id}])->fetchColumn());`), 'SAVE-UPDATED');
        await post('delete', { id: String(id) });
    });
    await check('settings save valid values and reject unsafe input; reinstall cannot erase data', async () => {
        assert.match((await request('settings')).text, /name="primary_color"/);
        const response = await request('save_settings', { site_title: 'Test Catalog', primary_color: '#112233', secondary_color: '#445566' });
        assert.equal(response.httpStatusCode, 302);
        const home = await request();
        assert.match(home.text, /Test Catalog/);
        assert.match(home.text, /--primary: #112233/);
        const invalid = await request('save_settings', { site_title: 'Unsafe', primary_color: 'red;display:none', secondary_color: '#445566' });
        assert.match(invalid.text, /class="error-box"/);
        assert.equal(await evaluate("echo json_encode(setting($d,'site_title'));"), 'Test Catalog');
        assert.equal((await request('save_settings')).httpStatusCode, 405);
        const before = await evaluate("echo json_encode((int)$d->query('SELECT COUNT(*) FROM products')->fetchColumn());");
        assert.equal((await request('reinstall')).httpStatusCode, 400);
        assert.equal(await evaluate("echo json_encode((int)$d->query('SELECT COUNT(*) FROM products')->fetchColumn());"), before);
    });
    await check('file cleanup never deletes files outside allowed image directories', async () => {
        php.writeFile('/app/keep.txt', 'keep');
        await evaluate("deleteMediaFile($d, 'uploads/../keep.txt'); deleteMediaFile($d, '../app/keep.txt');");
        assert.ok(php.fileExists('/app/keep.txt'));
    });
    await check('rendered browser scripts initialize without errors and support selection/cancel', async () => {
        await product(100004);
        const response = await request('group&prefix=TEST');
        const errors = [];
        const pending = [];
        const console = new VirtualConsole();
        console.on('jsdomError', error => errors.push(error));
        const dom = new JSDOM(response.text, {
            url: 'http://lufly.test/index.php', runScripts: 'dangerously', virtualConsole: console,
            beforeParse(window) {
                window.confirm = () => true;
                window.fetch = (url, options) => {
                    const response = server.request({ url: '/index.php' + url, method: options.method, body: Object.fromEntries(options.body) });
                    pending.push(response);
                    return response.then(result => ({ json: () => Promise.resolve(result.json) }));
                };
            },
        });
        const document = dom.window.document;
        assert.equal(errors.length, 0, errors.map(e => e.message).join('\n'));
        const card = document.querySelector('.product-card');
        card.querySelector('.product-card-body').click();
        assert.equal(document.getElementById('selectedCount').textContent, '1');
        dom.window.clearSelection();
        assert.equal(card.style.outline, '');
        assert.equal(document.getElementById('bulkVerifyBar').classList.contains('active'), false);
        const field = card.querySelector('.inline-field');
        field.click();
        assert.ok(field.querySelector('.inline-edit-form').classList.contains('active'));
        field.querySelector('.btn-cancel').click();
        assert.equal(field.querySelector('.inline-edit-form').classList.contains('active'), false);
        const settle = async () => { await Promise.all(pending); await new Promise(resolve => setImmediate(resolve)); };
        field.click();
        field.querySelector('input').value = 'TEST-CHANGED';
        field.querySelector('.btn-save').click();
        await settle();
        assert.match(card.querySelector('.code-badge').textContent, /TEST-CHANGED/);
        assert.equal(field.querySelector('.inline-edit-form').classList.contains('active'), false);
        const status = card.querySelectorAll('.inline-field')[2];
        status.click();
        status.querySelector('select').value = 'Aging';
        status.querySelector('.btn-save').click();
        await settle();
        assert.equal(card.querySelector('.card-carousel .status-badge').textContent, 'قديم');
        assert.ok(card.querySelector('.card-carousel .status-badge').classList.contains('Aging'));
        card.querySelector('.product-card-body').click();
        dom.window.bulkVerifySelected();
        await settle();
        assert.equal(card.dataset.verified, '1');
        assert.equal(card.style.outline, '');
        assert.equal(document.getElementById('bulkVerifyBar').classList.contains('active'), false);
        document.querySelector('.sidebar-cat-btn').click();
        assert.equal(errors.length, 0, errors.map(e => e.message).join('\n'));
        dom.window.close();
    });
    // Use a real, decodable GD fixture with texture and transparency, not just a PNG header.
    const texture = Buffer.from(await evaluate("$im=imagecreatetruecolor(40,32); imagealphablending($im,false); imagesavealpha($im,true); imagefill($im,0,0,imagecolorallocatealpha($im,0,0,0,127)); imagefilledrectangle($im,5,5,30,26,imagecolorallocate($im,100,120,140)); imageline($im,5,10,30,10,imagecolorallocate($im,190,190,190)); ob_start(); imagepng($im); $png=ob_get_clean(); imagedestroy($im); echo json_encode(base64_encode($png));"), 'base64');
    await product(100010);
    await product(100011);
    let enhancedMedia = await upload(100010, 'add', 0, texture);
    const enhancementFields = () => ({ product_id: '100010', media_id: String(enhancedMedia.media_id) });
    const dbMedia = () => evaluate(`echo json_encode(run($d,'SELECT * FROM media WHERE id=?',[${enhancedMedia.media_id}])->fetch());`);
    await check('enhancement previews leave the original unchanged and cancellation deletes only temporary files', async () => {
        const before = await dbMedia();
        const preview = await post('enhancement_create', enhancementFields());
        assert.deepEqual(await dbMedia(), before);
        assert.ok(php.fileExists('/app/' + enhancedMedia.path));
        const original = await request('enhancement_preview&token=' + preview.token + '&original=1');
        const improved = await request('enhancement_preview&token=' + preview.token);
        assert.equal(original.httpStatusCode, 200);
        assert.equal(improved.httpStatusCode, 200);
        assert.equal(improved.headers['content-type'][0], 'image/png');
        assert.match(improved.headers['cache-control'][0], /no-store/);
        assert.deepEqual(Buffer.from(original.bytes), texture);
        const pixels = await evaluate(`$a=imagecreatefromstring(base64_decode('${texture.toString('base64')}')); $b=imagecreatefromstring(base64_decode('${Buffer.from(improved.bytes).toString('base64')}')); echo json_encode([imagesx($b),imagesy($b),imagecolorsforindex($b,imagecolorat($b,0,0))['alpha'],imagecolorat($a,10,10)!==imagecolorat($b,10,10)]);`);
        assert.deepEqual(pixels, [40,32,127,true]);
        await post('enhancement_cancel', { token: preview.token });
        assert.equal((await request('enhancement_preview&token=' + preview.token)).httpStatusCode, 404);
        await post('enhancement_approve', { token: preview.token }, false);
        assert.deepEqual(await dbMedia(), before);
    });
    await check('whiteware mode protects white pixels and rejects unavailable AI instead of pretending to run it', async () => {
        await post('enhancement_create', {...enhancementFields(), profile:'invalid'}, false);
        const unavailable = await post('enhancement_create', {...enhancementFields(), profile:'ai'}, false);
        assert.match(unavailable.error,/Python/);
        await post('enhancement_create', {...enhancementFields(), profile:'ai4'}, false);
        const before = await dbMedia();
        const preview = await post('enhancement_create', {...enhancementFields(), profile:'whiteware'});
        assert.equal(preview.engine,'Whiteware Local');
        assert.equal(preview.scale,1);
        assert.equal(preview.width,40);
        assert.deepEqual(await dbMedia(),before);
        await post('enhancement_cancel',{token:preview.token});
        const whites = await evaluate("$im=imagecreatetruecolor(48,32); imagefill($im,0,0,imagecolorallocate($im,255,255,255)); imagefilledrectangle($im,8,8,38,24,imagecolorallocate($im,220,220,220)); enhanceWhitewareLocal($im); $bg=imagecolorat($im,0,0); $fg=imagecolorat($im,20,16); echo json_encode([$bg & 0xffffff,($fg >> 16) & 255,($fg >> 8) & 255,$fg & 255]);");
        assert.equal(whites[0],0xffffff);
        assert.ok(whites[1]<220 && whites[1]>195);
        assert.equal(whites[1],whites[2]);assert.equal(whites[2],whites[3]);
    });
    if (process.env.LUFLY_TEST_PYTHON) await check('PHP worker bridge runs real neural inference, previews and approves a 2x result', async () => {
        const state=await require('./ai_bridge.cjs')(php);
        const config=php.readFileAsText('/app/config.local.php');
        php.writeFile('/app/config.local.php',config.replace("'driver'=>'sqlite'","'enhancement_python'=>'/test-python','driver'=>'sqlite'"));
        try {
            const before=await dbMedia();
            state.fail=true;
            await post('enhancement_create',{...enhancementFields(),profile:'ai'},false);
            assert.deepEqual(await dbMedia(),before);
            assert.match(php.readFileAsText('/tmp/lufly-test-errors.log'),/LUFLY enhancement worker failed/);
            state.fail=false;
            const preview=await post('enhancement_create',{...enhancementFields(),profile:'ai'});
            assert.equal(preview.engine,'FSRCNN ×2 + Whiteware');
            assert.equal(preview.width,80);assert.equal(preview.height,64);assert.equal(preview.scale,2);
            assert.deepEqual(await dbMedia(),before);
            const output=await request('enhancement_preview&token='+preview.token);
            assert.equal(output.headers['content-type'][0],'image/png');
            enhancedMedia=await post('enhancement_approve',{token:preview.token});
            assert.equal(state.runs,2);
            if (process.env.LUFLY_TEST_X4_MODEL) await check('real EDSR x4 validates scale, downloads losslessly, cancels and approves without metadata loss',async()=>{
                const fields={...enhancementFields(),profile:'ai4'};
                const missing=await post('enhancement_create',fields,false);
                assert.match(missing.error,/EDSR/);
                php.writeFile('/app/config.local.php',config.replace("'driver'=>'sqlite'","'enhancement_python'=>'/test-python','enhancement_x4_model'=>'/test-x4-model','driver'=>'sqlite'"));
                const original=await dbMedia();
                assert.equal(await evaluate("$lock=enhancementDirectory().'/edsr-x4.lock';fclose(fopen($lock,'c'));touch($lock,time()-1200);cleanupEnhancements();echo json_encode(is_file($lock));"),true);
                const paths=await evaluate("fclose(fopen(enhancementDirectory().'/edsr-x4.lock','c')); echo json_encode(glob(enhancementDirectory().'/*')); ");
                state.wrongScale=true;
                await post('enhancement_create',fields,false);
                state.wrongScale=false;
                assert.deepEqual(await dbMedia(),original);
                assert.deepEqual(await evaluate("echo json_encode(glob(enhancementDirectory().'/*'));"),paths);
                const preview=await post('enhancement_create',fields);
                assert.equal(preview.profile,'ai4');assert.equal(preview.scale,4);
                assert.equal(preview.width,320);assert.equal(preview.height,256);
                assert.match(preview.engine,/EDSR/);
                const download=await server.request({url:'/index.php'+preview.download_url});
                assert.equal(download.httpStatusCode,200);
                assert.match(download.headers['content-disposition'][0],/attachment/);
                const pixels=await evaluate(`echo json_encode(array_slice(getimagesizefromstring(base64_decode('${Buffer.from(download.bytes).toString('base64')}')),0,2));`);
                assert.deepEqual(pixels,[320,256]);
                assert.deepEqual(await dbMedia(),original);
                await post('enhancement_cancel',{token:preview.token});
                assert.equal((await server.request({url:'/index.php'+preview.download_url})).httpStatusCode,404);
                const second=await post('enhancement_create',fields);
                enhancedMedia=await post('enhancement_approve',{token:second.token});
                const after=await dbMedia();
                for(const key of ['id','product_id','original_name','kind','caption','sort_order','previewable']) assert.equal(after[key],original[key]);
                assert.deepEqual(await evaluate(`echo json_encode(array_slice(getimagesize(__DIR__.'/${enhancedMedia.path}'),0,2));`.replace("__DIR__", "'/app'")),[320,256]);
            });
        } finally { php.writeFile('/app/config.local.php',config); }
    });
    await check('explicit enhancement approval replaces only the chosen image and preserves shared files and metadata', async () => {
        const originalPath = enhancedMedia.path;
        await evaluate(`run($d,'INSERT INTO media (product_id,path,original_name,kind) VALUES (?,?,?,?)',[100011,'${originalPath}','shared.png','drawing']); run($d,'UPDATE media SET kind=?,caption=?,sort_order=? WHERE id=?',['drawing','Keep caption',17,${enhancedMedia.media_id}]);`);
        const before = await dbMedia();
        const preview = await post('enhancement_create', enhancementFields());
        const result = await post('enhancement_approve', { token: preview.token });
        const after = await dbMedia();
        assert.equal(result.media_id, enhancedMedia.media_id);
        assert.notEqual(after.path, before.path);
        assert.notEqual(after.sha256, before.sha256);
        for (const field of ['kind','caption','sort_order','original_name','previewable']) assert.equal(after[field], before[field]);
        assert.ok(php.fileExists('/app/' + originalPath));
        assert.ok(php.fileExists('/app/' + result.path));
        await post('enhancement_approve', { token: preview.token }, false);
        assert.equal((await request('enhancement_preview&token=' + preview.token)).httpStatusCode, 404);
        await post('delete', { id: '100011' });
        assert.equal(php.fileExists('/app/' + originalPath), false);
        enhancedMedia = result;
    });
    await check('stale enhancement approvals never overwrite a replacement made after preview', async () => {
        const preview = await post('enhancement_create', enhancementFields());
        enhancedMedia = await upload(100010, 'replace', enhancedMedia.media_id, texture);
        const before = await dbMedia();
        await post('enhancement_approve', { token: preview.token }, false);
        assert.deepEqual(await dbMedia(), before);
        const snapshot = await request('enhancement_preview&token=' + preview.token + '&original=1');
        assert.equal(snapshot.httpStatusCode, 200);
        await post('enhancement_cancel', { token: preview.token });
    });
    await check('failed enhancement database writes preserve the original and remove the new upload', async () => {
        const preview = await post('enhancement_create', enhancementFields());
        const before = await dbMedia();
        const files = await evaluate("echo json_encode(glob('/app/uploads/*'));" );
        await evaluate("$d->exec(\"CREATE TRIGGER prevent_enhance BEFORE UPDATE ON media BEGIN SELECT RAISE(ABORT, 'test rollback'); END\");");
        await post('enhancement_approve', { token: preview.token }, false);
        await evaluate("$d->exec('DROP TRIGGER prevent_enhance');");
        assert.deepEqual(await dbMedia(), before);
        assert.deepEqual(await evaluate("echo json_encode(glob('/app/uploads/*'));"), files);
        await post('enhancement_cancel', { token: preview.token });
    });
    await check('enhancement endpoints validate CSRF, ownership, archive state, tokens and resource limits', async () => {
        await post('enhancement_create', { ...enhancementFields(), csrf: '' }, false);
        await post('enhancement_create', { ...enhancementFields(), product_id: '100004' }, false);
        await post('enhancement_approve', { token: '../../index.php' }, false);
        const preview = await post('enhancement_create', enhancementFields());
        await post('enhancement_cancel', { token: preview.token, csrf: '' }, false);
        await post('enhancement_approve', { token: preview.token, csrf: '' }, false);
        await post('archive', { id: '100010' });
        await post('enhancement_approve', { token: preview.token }, false);
        await post('enhancement_create', enhancementFields(), false);
        await post('restore', { id: '100010' });
        await post('enhancement_cancel', { token: preview.token });
        const huge = Buffer.from(texture);
        huge.writeUInt32BE(4000,16); huge.writeUInt32BE(4000,20);
        const oversized = await upload(100010,'add',0,huge);
        await post('enhancement_create', { product_id:'100010', media_id:String(oversized.media_id) }, false);
        await post('media_action', { product_id:'100010', op:'delete', media_id:String(oversized.media_id) });
    });
    await check('previews cannot be accessed or approved from another authenticated session', async () => {
        const preview = await post('enhancement_create', enhancementFields());
        const other = new PHPRequestHandler({ php, documentRoot:'/app', absoluteUrl:'http://lufly.test' });
        await other.request({url:'/login.php',method:'POST',body:{password:'integration-test-only'}});
        const page = await other.request({url:'/index.php'});
        const token = page.text.match(/<meta name="csrf" content="([a-f0-9]+)"/)[1];
        assert.equal((await other.request({url:'/index.php?action=enhancement_preview&token='+preview.token})).httpStatusCode,404);
        const approved = await other.request({url:'/index.php?action=enhancement_approve',method:'POST',body:{csrf:token,token:preview.token}});
        assert.equal(approved.httpStatusCode,400);
        await post('enhancement_cancel', { token: preview.token });
    });
    await check('JPEG EXIF orientation and static WebP are supported; GIF and animated WebP are rejected', async () => {
        await product(100012);
        const jpeg = Buffer.from(await evaluate("$im=imagecreatetruecolor(40,32); ob_start(); imagejpeg($im); $bytes=ob_get_clean(); imagedestroy($im); echo json_encode(base64_encode($bytes));"), 'base64');
        const exif = Buffer.from('ffe1002245786966000049492a0008000000010012010300010000000600000000000000','hex');
        const oriented = Buffer.concat([jpeg.subarray(0,2), exif, jpeg.subarray(2)]);
        const photo = await upload(100012,'add',0,oriented);
        const preview = await post('enhancement_create',{product_id:'100012',media_id:String(photo.media_id)});
        const output = await request('enhancement_preview&token='+preview.token);
        const dimensions = await evaluate(`echo json_encode(array_slice(getimagesizefromstring(base64_decode('${Buffer.from(output.bytes).toString('base64')}')),0,2));`);
        assert.deepEqual(dimensions,[32,40]);
        await post('enhancement_cancel',{token:preview.token});
        const webp = Buffer.from(await evaluate("$im=imagecreatetruecolor(40,32); ob_start(); imagewebp($im); $bytes=ob_get_clean(); imagedestroy($im); echo json_encode(base64_encode($bytes));"),'base64');
        const still = await upload(100012,'add',0,webp);
        const stillPreview = await post('enhancement_create',{product_id:'100012',media_id:String(still.media_id)});
        await post('enhancement_cancel',{token:stillPreview.token});
        const animated = Buffer.concat([webp,Buffer.from('ANIM'),Buffer.alloc(4)]);
        animated.writeUInt32LE(animated.length-8,4);
        const animation = await upload(100012,'add',0,animated);
        await post('enhancement_create',{product_id:'100012',media_id:String(animation.media_id)},false);
        const gif = await upload(100012,'add',0,Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7','base64'));
        await post('enhancement_create',{product_id:'100012',media_id:String(gif.media_id)},false);
        await post('delete',{id:'100012'});
    });
    await check('expired previews and excess previews are discarded without changing media', async () => {
        const first = await post('enhancement_create', enhancementFields());
        for (let i=0;i<3;i++) await post('enhancement_create', enhancementFields());
        assert.equal((await request('enhancement_preview&token='+first.token)).httpStatusCode,404);
        const preview = await post('enhancement_create', enhancementFields());
        const before = await dbMedia();
        php.writeFile('/app/expire-test.php', '<?php require "app.php"; foreach ($_SESSION["enhancements"] as &$preview) $preview["expires"]=time()-1;');
        await server.request({url:'/expire-test.php'});
        await post('enhancement_approve', {token:preview.token}, false);
        assert.deepEqual(await dbMedia(), before);
        assert.equal((await request('enhancement_preview&token='+preview.token)).httpStatusCode,404);
    });
    await check('browser enhancement comparison waits for approval, supports cancel and updates the card after saving', async () => {
        const response = await request('group&prefix=TEST');
        const errors = [], pending = [];
        const virtualConsole = new VirtualConsole();
        virtualConsole.on('jsdomError', error=>errors.push(error));
        const dom = new JSDOM(response.text, {url:'http://lufly.test/index.php',runScripts:'dangerously',virtualConsole,
            beforeParse(window) {
                window.confirm=()=>true;
                window.fetch=(url,options)=>{
                    const response=server.request({url:'/index.php'+url,method:options.method,body:Object.fromEntries(options.body)});
                    pending.push(response);
                    return response.then(result=>({json:()=>Promise.resolve(result.json)}));
                };
            }
        });
        const win=dom.window, doc=win.document;
        const settle=async()=>{
            let count = -1;
            while (count !== pending.length) {
                count = pending.length;
                await Promise.all(pending);
                await new Promise(resolve=>setImmediate(resolve));
            }
        };
        const trigger=doc.querySelector('.product-card[data-id="100010"] .lightbox-trigger');
        const original=trigger.src;
        trigger.click();
        assert.match(doc.querySelector('.lb-enhance').textContent,/تحسين الصورة/);
        win.enhanceImage();
        await settle();
        assert.equal(doc.getElementById('lbEnhancement').hidden,false);
        assert.equal(doc.getElementById('enhanceApprove').disabled,true);
        doc.getElementById('enhanceActualSize').click();
        assert.ok(doc.querySelector('.enhancement-compare').classList.contains('actual-size'));
        assert.equal(doc.getElementById('enhanceDownload').hidden,true);
        assert.equal(trigger.src,original);
        win.cancelEnhancement();
        await settle();
        assert.equal(trigger.src,original);
        assert.equal(doc.getElementById('lbEnhancement').hidden,true);
        // Closing while generation is pending must discard the late result.
        win.enhanceImage();
        win.closeLightbox();
        await settle();
        assert.equal(doc.getElementById('lbEnhancement').hidden,true);
        assert.equal(trigger.src,original);
        trigger.click();
        // A failed preview load must never enable the approval button.
        win.enhanceImage();
        await settle();
        doc.getElementById('enhanceBefore').dispatchEvent(new win.Event('load'));
        doc.getElementById('enhanceAfter').dispatchEvent(new win.Event('error'));
        assert.equal(doc.getElementById('enhanceApprove').disabled,true);
        win.cancelEnhancement();
        await settle();
        // A generation/network failure restores normal lightbox controls.
        const fetch = win.fetch;
        win.fetch = () => Promise.reject(new Error('Network unavailable'));
        win.enhanceImage();
        await settle();
        assert.equal(doc.getElementById('lbEnhancement').hidden,true);
        assert.equal(trigger.src,original);
        win.fetch = fetch;
        win.enhanceImage();
        await settle();
        doc.getElementById('enhanceBefore').dispatchEvent(new win.Event('load'));
        doc.getElementById('enhanceAfter').dispatchEvent(new win.Event('load'));
        assert.equal(doc.getElementById('enhanceApprove').disabled,false);
        assert.match(doc.getElementById('enhanceDownload').href,/download=1/);
        assert.equal(doc.getElementById('enhanceDownload').hidden,false);
        doc.getElementById('enhanceApprove').click();
        await settle();
        assert.notEqual(trigger.src,original);
        assert.equal(doc.getElementById('lbImg').src,trigger.src);
        assert.equal(doc.getElementById('lbEnhancement').hidden,true);
        assert.equal(errors.length,0,errors.map(e=>e.message).join('\n'));
        dom.window.close();
    });
    await check('SQL snapshots export to a new guarded file without changing SQL or overwriting existing files', async () => {
        const result = await evaluate("$rows=['products'=>catalogRows($d,'products'),'media'=>catalogRows($d,'media'),'settings'=>catalogRows($d,'settings')]; file_put_contents('/app/export-test.json',json_encode($rows)); $f=new JsonCatalogStore('/app/data/exported.json.php','/app/export-test.json',true); $same=catalogRows($f,'products')===$rows['products'] && catalogRows($f,'media')===$rows['media'] && setting($f,'site_title')===setting($d,'site_title'); try{new JsonCatalogStore('/app/data/exported.json.php','/app/export-test.json',true);$refused=false;}catch(RuntimeException $e){$refused=true;}unlink('/app/export-test.json');echo json_encode([$same,$refused,$d instanceof PDO]);");
        assert.deepEqual(result,[true,true,true]);
        assert.equal((await server.request({url:'/tools/export_local_json.php'})).httpStatusCode,404);
    });
    await check('database connection failures render a setup error rather than crashing', async () => {
        const config = php.readFileAsText('/app/config.local.php');
        php.writeFile('/app/config.local.php', config.replace('/app/data/test.sqlite', '/missing-directory/test.sqlite'));
        const response = await request();
        assert.match(response.text, /تعذر الاتصال بقاعدة البيانات/);
        php.writeFile('/app/config.local.php', config);
        assert.equal((await request()).httpStatusCode, 200);
    });
    await check('logout revokes the session', async () => {
        assert.equal((await request('logout')).httpStatusCode, 302);
        assert.equal((await request()).httpStatusCode, 302);
    });
    console.log(`\n${passed} integration checks passed (PHP ${phpVersion} + SQLite, isolated memory filesystem).`);
}
main().then(() => { php.exit(); process.exit(0); }).catch(error => {
    console.error(error);
    if (php) php.exit();
    process.exit(1);
});
