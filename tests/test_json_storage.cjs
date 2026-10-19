/** Optional dependencies and runtime setup: see TESTING.md. No live data is used. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const modules = process.env.LUFLY_TEST_MODULES;
const dependency = name => require(modules ? path.join(modules, name) : name);
const { PHP, PHPRequestHandler } = dependency('@php-wasm/universal');
const { loadNodeRuntime } = dependency('@php-wasm/node');
const root = path.resolve(__dirname, '..');
const version = process.env.LUFLY_TEST_PHP_VERSION || '8.3';
let php, server, csrf, passed = 0;
async function check(label, fn) { await fn(); console.log('PASS ' + label); passed++; }
async function request(action = 'home', body) {
    const fields = body ? {csrf,...body} : null;
    const upload = fields && Object.values(fields).some(value=>value instanceof File);
    const response = await server.request({url:'/index.php?action='+action,...(fields ? {method:'POST',...(upload ? {body:fields} : {headers:{'content-type':'application/x-www-form-urlencoded'},body:new TextEncoder().encode(new URLSearchParams(fields).toString())})} : {})});
    assert.equal(response.errors,'',response.errors); assert.equal(response.exitCode,0,response.text);
    return response;
}
async function post(action, body, ok=true) {
    const response=await request(action,body);
    assert.equal(response.httpStatusCode,ok ? 200 : 400,response.text);
    assert.equal(response.json.ok,ok,response.text);return response.json;
}
async function evaluate(code) {
    const response = await php.run({code:'<?php require "/app/app.php"; require_once "/app/includes/image_enhancement.php"; $d=db(); '+code});
    assert.equal(response.errors,'',response.errors);assert.equal(response.exitCode,0,response.text);
    return response.text ? response.json : undefined;
}
const product = id=>evaluate(`catalogInsert($d,'products',['id'=>${id},'code'=>'FILE-${id}','description'=>'File product']);`);
const record = id=>evaluate(`echo json_encode(catalogRow($d,'products',['id'=>${id}]));`);
const snapshot=()=>Buffer.from(php.readFileAsBuffer('/app/data/local/catalog.json.php'));
async function main() {
    const runtime='@php-wasm/node-'+version.replace('.','-');
    const wasm=path.dirname(require.resolve(modules ? path.join(modules,runtime) : runtime));
    php=new PHP(await loadNodeRuntime(version,{emscriptenOptions:{processId:1,locateFile:file=>path.isAbsolute(file)?file:path.join(wasm,'asyncify',file)}}));
    for (const dir of ['includes','data','assets/catalog','uploads']) php.mkdir('/app/'+dir);
    for (const file of ['app.php','config.php','index.php','login.php','includes/sidebar.php','includes/catalog_storage.php','includes/image_enhancement.php','data/catalog.json','.env.example']) php.writeFile('/app/'+file,fs.readFileSync(path.join(root,file)));
    php.writeFile('/app/config.local.php',"<?php return ['storage_mode'=>'files','driver'=>'mysql','host'=>'unreachable.invalid','sqlite_path'=>'/app/data/old.sqlite','app_password'=>'test-files'];");
    php.writeFile('/app/data/old.sqlite','Existing database must never be opened, rewritten or removed');
    server=new PHPRequestHandler({php,documentRoot:'/app',absoluteUrl:'http://lufly.test'});
    await check('storage selection uses server/peer loopback, supports overrides, and ignores spoofed Host',async()=>{
        const result=await evaluate(`$base=['driver'=>'mysql','storage_mode'=>'auto']; echo json_encode([
            catalogDriver($base,['REMOTE_ADDR'=>'127.0.0.1','SERVER_ADDR'=>'127.0.0.1','SERVER_NAME'=>'localhost'],'apache2handler'),
            catalogDriver($base,['REMOTE_ADDR'=>'::1','SERVER_ADDR'=>'::1','SERVER_NAME'=>'::1'],'apache2handler'),
            catalogDriver($base,['HTTP_HOST'=>'localhost','REMOTE_ADDR'=>'8.8.8.8','SERVER_ADDR'=>'10.0.0.2'],'fpm-fcgi'),
            catalogDriver($base,[],'cli'),
            catalogDriver($base,['REMOTE_ADDR'=>'127.0.0.1','SERVER_ADDR'=>'127.0.0.1','SERVER_NAME'=>'catalog.example'],'fpm-fcgi'),
            catalogDriver(array_replace($base,['storage_mode'=>'database']),['REMOTE_ADDR'=>'127.0.0.1','SERVER_ADDR'=>'127.0.0.1','SERVER_NAME'=>'localhost'],'apache2handler'),
            catalogDriver(array_replace($base,['storage_mode'=>'files']),[],'fpm-fcgi')]);`);
        assert.deepEqual(result,['json','json','mysql','json','mysql','mysql','json']);
    });
    await check('files mode boots from catalog without MySQL/SQLite and keeps the seed immutable',async()=>{
        assert.equal(await evaluate("echo json_encode($d instanceof JsonCatalogStore);"),true);
        assert.equal(await evaluate("echo json_encode(count(catalogRows($d,'products')));"),715);
        assert.equal(await evaluate("echo json_encode(count(catalogRows($d,'media')));"),1465);
        assert.deepEqual(Buffer.from(php.readFileAsBuffer('/app/data/catalog.json')),fs.readFileSync(path.join(root,'data/catalog.json')));
        assert.equal(php.readFileAsText('/app/data/old.sqlite'),'Existing database must never be opened, rewritten or removed');
        assert.equal(php.fileExists('/app/data/lufly.sqlite'),false);
    });
    await check('normal authentication and CSRF remain active in file mode',async()=>{
        assert.equal((await request()).httpStatusCode,302);
        const login=await server.request({url:'/login.php',method:'POST',body:{password:'test-files'}});assert.equal(login.httpStatusCode,302);
        const page=await request();csrf=page.text.match(/<meta name="csrf" content="([a-f0-9]+)"/)[1];
        assert.match(page.text,/وضع الملفات المحلي/);
        await post('delete',{id:'1',csrf:''},false);assert.ok(await record(1));
    });
    await product(100001);await product(100002);
    await check('edits persist across requests and backups contain the previous valid snapshot',async()=>{
        const before=snapshot();await post('inline_save',{id:'100001',field:'description',value:'وصف محفوظ بالملفات'});
        assert.equal((await record(100001)).description,'وصف محفوظ بالملفات');
        assert.deepEqual(Buffer.from(php.readFileAsBuffer('/app/data/local/catalog.json.php.backup.php')),before);
        const page=await request('search&q='+encodeURIComponent('وصف محفوظ بالملفات'));assert.match(page.text,/data-id="100001"/);
        assert.match((await request('group&prefix=FILE')).text,/data-id="100001"/);
    });
    await check('verification, bulk validation, archive/restore and settings work without SQL',async()=>{
        assert.equal((await post('toggle_verify',{id:'100001'})).verified,1);
        assert.equal((await post('bulk_verify',{ids:'[100001,100002]'})).count,2);
        const before=snapshot();await post('bulk_verify',{ids:'[100001,999999]'},false);assert.deepEqual(snapshot(),before);
        await post('archive',{id:'100001'});assert.match((await request('archive_list')).text,/restoreProduct\(100001\)/);
        await post('inline_save',{id:'100001',field:'description',value:'Blocked'},false);
        await post('restore',{id:'100001'});assert.equal((await record(100001)).archived,0);
        assert.equal((await request('save_settings',{site_title:'Files Catalog',primary_color:'#112233',secondary_color:'#334455'})).httpStatusCode,302);
        assert.match((await request()).text,/Files Catalog/);
    });
    let media;
    const png=Buffer.from(await evaluate("$im=imagecreatetruecolor(40,32); imagefill($im,0,0,imagecolorallocate($im,220,220,220)); imageline($im,1,20,35,20,imagecolorallocate($im,100,100,100)); ob_start();imagepng($im);$b=ob_get_clean();imagedestroy($im);echo json_encode(base64_encode($b));"),'base64');
    const upload=(id,op='add',mediaId=0)=>post('media_action',{product_id:String(id),op,media_id:String(mediaId),file:new File([png],'product.png')});
    await check('uploads and replacements use isolated local paths and persisted media IDs',async()=>{
        media=await upload(100001);assert.match(media.path,/^uploads\/local\//);const old=media.path;
        media=await upload(100001,'replace',media.media_id);assert.notEqual(media.path,old);assert.equal(php.fileExists('/app/'+old),false);
        assert.equal(await evaluate(`echo json_encode(catalogRow($d,'media',['id'=>${media.media_id}])['path']);`),media.path);
    });
    await check('image enhancement preview, cancel and approval work with JSON storage',async()=>{
        const before=snapshot();let preview=await post('enhancement_create',{product_id:'100001',media_id:String(media.media_id)});assert.deepEqual(snapshot(),before);
        await post('enhancement_cancel',{token:preview.token});assert.deepEqual(snapshot(),before);
        preview=await post('enhancement_create',{product_id:'100001',media_id:String(media.media_id)});
        const old=media.path;media=await post('enhancement_approve',{token:preview.token});assert.match(media.path,/^uploads\/local\//);assert.notEqual(media.path,old);
        assert.equal(php.fileExists('/app/'+old),false);
        await post('enhancement_approve',{token:preview.token},false);
    });
    if (process.env.LUFLY_TEST_PYTHON && process.env.LUFLY_TEST_X4_MODEL) await check('real EDSR x4 preview/download/cancel/approval persist losslessly in files mode without SQL',async()=>{
        await require('./ai_bridge.cjs')(php);
        const config=php.readFileAsText('/app/config.local.php');
        php.writeFile('/app/config.local.php',config.replace("'storage_mode'=>'files'","'enhancement_python'=>'/test-python','enhancement_x4_model'=>'/test-x4-model','storage_mode'=>'files'"));
        try {
            const before=snapshot(), fields={product_id:'100001',media_id:String(media.media_id),profile:'ai4'};
            const preview=await post('enhancement_create',fields);
            assert.equal(preview.scale,4);assert.equal(preview.width,160);assert.equal(preview.height,128);
            assert.deepEqual(snapshot(),before);
            const downloaded=await server.request({url:'/index.php'+preview.download_url});
            assert.match(downloaded.headers['content-disposition'][0],/attachment/);
            await post('enhancement_cancel',{token:preview.token});assert.deepEqual(snapshot(),before);
            const second=await post('enhancement_create',fields);
            const output=await server.request({url:'/index.php'+second.download_url});
            media=await post('enhancement_approve',{token:second.token});
            assert.match(media.path,/^uploads\/local\//);
            assert.deepEqual(Buffer.from(php.readFileAsBuffer('/app/'+media.path)),Buffer.from(output.bytes));
        } finally {php.writeFile('/app/config.local.php',config);}
    });
    await check('shared images survive deleting a product and disappear after the final reference',async()=>{
        await evaluate(`catalogInsert($d,'media',['product_id'=>100002,'path'=>'${media.path}','original_name'=>'shared.png']);`);
        await post('delete',{id:'100001'});assert.equal(await record(100001),false);assert.ok(php.fileExists('/app/'+media.path));
        await post('delete',{id:'100002'});assert.equal(php.fileExists('/app/'+media.path),false);
    });
    await check('file mode never deletes bundled seed images',async()=>{
        php.writeFile('/app/assets/catalog/immutable.png',png);
        await evaluate("deleteMediaFile($d,'assets/catalog/immutable.png');");assert.ok(php.fileExists('/app/assets/catalog/immutable.png'));
    });
    await check('full saves, optimistic versions and failed transactions preserve data',async()=>{
        const id=await evaluate("$_POST=['code'=>'SAVE-FILES','description'=>'Saved','status'=>'Running'];echo json_encode(saveProduct($d));");
        const before=snapshot();
        const rejected=await evaluate(`$_POST=['id'=>${id},'version'=>0,'code'=>'STALE','description'=>'Stale','status'=>'Running'];try{saveProduct($d);echo json_encode(false);}catch(RuntimeException $e){echo json_encode(true);}`);
        assert.equal(rejected,true);assert.deepEqual(snapshot(),before);
        const result=await evaluate("$d->beginTransaction();catalogUpdate($d,'products',['notes'=>'Uncommitted'],['id'=>1]);$d->rollBack();echo json_encode(catalogRow($d,'products',['id'=>1])['notes']);");assert.notEqual(result,'Uncommitted');assert.deepEqual(snapshot(),before);
    });
    await check('separate stale store handles re-read under the write lock, so unrelated edits are not lost',async()=>{
        const result=await evaluate("$a=new JsonCatalogStore($config['json_path'],'/app/data/catalog.json');$b=new JsonCatalogStore($config['json_path'],'/app/data/catalog.json');catalogUpdate($a,'products',['notes'=>'First writer'],['id'=>1]);catalogUpdate($b,'products',['notes'=>'Second writer'],['id'=>2]);$c=new JsonCatalogStore($config['json_path'],'/app/data/catalog.json');echo json_encode([catalogRow($c,'products',['id'=>1])['notes'],catalogRow($c,'products',['id'=>2])['notes']]);");
        assert.deepEqual(result,['First writer','Second writer']);
    });
    await check('a blocked backup/atomic rename rolls back without changing the original or leaving temp files',async()=>{
        const result=await evaluate("$file='/app/data/failure.json.php';$f=new JsonCatalogStore($file,'/app/data/catalog.json');$old=file_get_contents($file);mkdir($file.'.backup.php');$f->beginTransaction();catalogUpdate($f,'products',['notes'=>'Must not persist'],['id'=>1]);try{$f->commit();$failed=false;}catch(Throwable $e){$failed=true;$f->rollBack();}echo json_encode([$failed,file_get_contents($file)===$old,count(glob('/app/data/.catalog-*.tmp.php'))===0]);");
        assert.deepEqual(result,[true,true,true]);
    });
    await check('invalid foreign keys roll back and IDs are never reused',async()=>{
        const before=snapshot();assert.equal(await evaluate("try{catalogInsert($d,'media',['product_id'=>999999,'path'=>'bad']);echo json_encode(false);}catch(Throwable $e){echo json_encode(true);}"),true);assert.deepEqual(snapshot(),before);
        const ids=await evaluate("$a=catalogInsert($d,'products',['code'=>'ID-ONE']);catalogDelete($d,'products',['id'=>$a]);$b=catalogInsert($d,'products',['code'=>'ID-TWO']);echo json_encode([$a,$b]);");assert.ok(ids[1]>ids[0]);
    });
    await check('snapshot, backup and temp formats do not disclose JSON over HTTP',async()=>{
        await evaluate("catalogUpdate($d,'products',['notes'=>'<?php echo \"LEAK\"; ?>'],['id'=>1]);");
        for (const file of ['catalog.json.php','catalog.json.php.backup.php']) {
            const response=await server.request({url:'/data/local/'+file});assert.equal(response.httpStatusCode,404);assert.equal(response.text,'');
        }
        assert.equal(php.listFiles('/app/data/local').some(file=>file.endsWith('.tmp.php')),false);
    });
    await check('malformed files fail closed without overwriting the snapshot or backup',async()=>{
        const good=snapshot();const backup=Buffer.from(php.readFileAsBuffer('/app/data/local/catalog.json.php.backup.php'));
        php.writeFile('/app/data/local/catalog.json.php','broken JSON');
        const response=await request();assert.match(response.text,/ملف البيانات المحلية تالف/);assert.match(response.text,/تعذر فتح البيانات المحلية/);
        assert.equal(php.readFileAsText('/app/data/local/catalog.json.php'),'broken JSON');
        assert.deepEqual(Buffer.from(php.readFileAsBuffer('/app/data/local/catalog.json.php.backup.php')),backup);
        php.writeFile('/app/data/local/catalog.json.php',good);
    });
    await check('deleting every record does not silently reimport seed products',async()=>{
        await evaluate("$d->beginTransaction();foreach(catalogRows($d,'media') as $m)catalogDelete($d,'media',['id'=>$m['id']]);foreach(catalogRows($d,'products') as $p)catalogDelete($d,'products',['id'=>$p['id']]);$d->commit();");
        assert.match((await request()).text,/لا توجد منتجات/);
        assert.equal(await evaluate("echo json_encode(count(catalogRows($d,'products')));"),0);
        assert.equal(php.readFileAsText('/app/data/old.sqlite'),'Existing database must never be opened, rewritten or removed');
    });
    console.log(`\n${passed} JSON-storage checks passed on PHP ${version}; no database used.`);
}
main().then(()=>{php.exit();process.exit(0)}).catch(error=>{console.error(error);if(php)php.exit();process.exit(1)});
