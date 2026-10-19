const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const modules = process.env.LUFLY_TEST_MODULES;
const dependency = name => require(modules ? path.join(modules, name) : name);
const { PHP, PHPRequestHandler } = dependency('@php-wasm/universal');
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
    const response = await php.run({ code: '<?php require "/app/app.php"; $d=db(); ' + code });
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
    php.mkdir('/app/includes');
    php.mkdir('/app/tools');
    php.mkdir('/app/data');
    php.mkdir('/app/uploads');
    php.mkdir('/app/assets/catalog');
    for (const file of ['app.php', 'config.php', 'index.php', 'login.php', 'tools/export_local_json.php', 'includes/sidebar.php', 'includes/catalog_storage.php', 'data/catalog.json', '.env.example']) {
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
    await check('all PHP entry files are valid and contain no code comments', async () => {
        for (const file of ['app.php', 'config.php', 'index.php', 'login.php', 'tools/export_local_json.php', 'includes/sidebar.php', 'includes/catalog_storage.php']) {
            const code = Buffer.from(fs.readFileSync(path.join(root, file))).toString('base64');
            await evaluate(`foreach(token_get_all(base64_decode('${code}'), TOKEN_PARSE) as $token) if(is_array($token) && in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true)) throw new RuntimeException('Unexpected code comment');`);
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
        const uploaded=await upload(100004);
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
        const image=card.querySelector('.lightbox-trigger');
        image.click();
        assert.ok(document.getElementById('lightbox').classList.contains('active'));
        assert.ok(document.querySelector('.lb-replace'));
        assert.ok(document.querySelector('.lb-delete'));
        assert.equal(document.querySelector('.lb-enhance, #lbEnhanceProfile'),null);
        dom.window.deleteImage();
        await settle();
        assert.equal(card.querySelector('.lightbox-trigger'),null);
        assert.ok(card.querySelector('.no-image'));
        assert.equal(document.getElementById('lightbox').classList.contains('active'),false);
        assert.equal(await evaluate(`echo json_encode(catalogRow($d,'media',['id'=>${uploaded.media_id}]));`),false);
        document.querySelector('.sidebar-cat-btn').click();
        assert.equal(errors.length, 0, errors.map(e => e.message).join('\n'));
        dom.window.close();
    });
    await check('image controls remain usable, removed processing routes return 404 and never mutate records', async () => {
        const before=await evaluate("echo json_encode([catalogRows($d,'products'),catalogRows($d,'media'),catalogRows($d,'settings')]);");
        for(const action of ['enhancement_preview','enhancement_create','enhancement_approve','enhancement_cancel']) {
            assert.equal((await request(action)).httpStatusCode,404);
            assert.equal((await request(action,{product_id:'1',media_id:'1',profile:'ai4',token:'old-token'})).httpStatusCode,404);
        }
        assert.deepEqual(await evaluate("echo json_encode([catalogRows($d,'products'),catalogRows($d,'media'),catalogRows($d,'settings')]);"),before);
        const page=await request('home');
        const document=new JSDOM(page.text).window.document;
        assert.equal(document.querySelector('.lb-enhance, .enhancement-panel, #lbEnhanceProfile'),null);
        assert.doesNotMatch(page.text,/FSRCNN|EDSR|High Resolution|تحسين الصورة|Python/);
        for(const file of ['includes/image_enhancement.php','tools/image_enhancement','phpinfo.php','test_save.php','error_log']) assert.equal(fs.existsSync(path.join(root,file)),false,file);
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
