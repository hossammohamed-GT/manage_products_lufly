/** Unit-test the CLI config helper in WASM by adapting ONLY its SAPI gate in memory. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const modules = process.env.LUFLY_TEST_MODULES;
const dependency = name => require(modules ? path.join(modules,name) : name);
const {PHP,PHPRequestHandler} = dependency('@php-wasm/universal');
const {loadNodeRuntime} = dependency('@php-wasm/node');
const version=process.env.LUFLY_TEST_PHP_VERSION || '8.3';
let php, passed=0;
async function check(name,fn) {await fn();passed++;console.log('PASS '+name);}
async function query(code) {
    const r=await php.run({code:'<?php '+code});assert.equal(r.exitCode,0,r.text);assert.equal(r.errors,'');return r.json;
}
async function run(args,ok=true) {
    const encoded=Buffer.from(JSON.stringify(['setup_config.php',...args])).toString('base64');
    let r;
    try {
        r=await php.run({code:`<?php if(!defined('STDERR')) define('STDERR',fopen('php://stderr','wb')); $argv=json_decode(base64_decode('${encoded}'),true); $argc=count($argv); require '/setup/test-cli.php';`});
    } catch(error) {
        if (ok || !error.response) throw error;
        r=error.response;
    }
    assert.equal(r.exitCode,ok?0:1,r.text);return r;
}
async function main() {
    const pkg='@php-wasm/node-'+version.replace('.','-');
    const wasm=path.dirname(require.resolve(modules?path.join(modules,pkg):pkg));
    php=new PHP(await loadNodeRuntime(version,{emscriptenOptions:{processId:1,locateFile:file=>path.isAbsolute(file)?file:path.join(wasm,'asyncify',file)}}));
    for(const dir of ['/project','/runtime','/setup/models']) php.mkdir(dir);
    const source=fs.readFileSync(path.resolve(__dirname,'../tools/image_enhancement/setup_config.php'),'utf8');
    assert.ok(source.includes("if (PHP_SAPI !== 'cli')"));
    php.writeFile('/setup/original.php',source);
    php.writeFile('/setup/test-cli.php',source.replace("if (PHP_SAPI !== 'cli')",'if (false)'));
    php.writeFile('/project/index.php','<?php');
    php.writeFile('/runtime/python.exe','test interpreter file');
    php.writeFile('/runtime/model.pb','verified test model');
    php.writeFile('/setup/models/EDSR_x4.json',JSON.stringify({sha256:crypto.createHash('sha256').update('verified test model').digest('hex')}));
    const config="<?php return ['driver'=>'mysql','password'=>'test-fixture-not-a-real-secret','nested'=>['enabled'=>true],'extra'=>__DIR__.'/extra'];";
    php.writeFile('/project/config.local.php',config);
    const args=['/project','/runtime/python.exe','/runtime/model.pb','/private/backups'];
    await check('HTTP cannot execute the real configuration installer',async()=>{
        const server=new PHPRequestHandler({php,documentRoot:'/setup',absoluteUrl:'http://setup.test'});
        const r=await server.request({url:'/original.php'});assert.equal(r.httpStatusCode,404);assert.equal(r.text,'');
        assert.equal(php.readFileAsText('/project/config.local.php'),config);
    });
    await check('CLI helper preserves settings, adds both paths and backs up outside the project',async()=>{
        await run(args);
        const settings=await query("echo json_encode(require '/project/config.local.php');");
        assert.deepEqual(settings,{driver:'mysql',password:'test-fixture-not-a-real-secret',nested:{enabled:true},extra:'/project/extra',enhancement_python:'/runtime/python.exe',enhancement_x4_model:'/runtime/model.pb'});
        const files=await query("echo json_encode(glob('/private/backups/*'));");assert.equal(files.length,1);
        assert.equal(php.readFileAsText(files[0]),config);
    });
    await check('bad model, missing executable and public backup paths fail without modifying settings',async()=>{
        const old=php.readFileAsText('/project/config.local.php');
        await run(['/project','/missing/python.exe',args[2],args[3]],false);
        await run([...args.slice(0,3),'/project/public-backups'],false);
        php.writeFile('/runtime/model.pb','corrupt');await run(args,false);
        php.writeFile('/runtime/model.pb','verified test model');
        assert.equal(php.readFileAsText('/project/config.local.php'),old);
    });
    await check('rename failure leaves no temporary configuration or misplaced secret backup',async()=>{
        php.unlink('/project/config.local.php');php.mkdir('/project/config.local.php');
        await run(args,false);
        assert.deepEqual(await query("echo json_encode(glob('/project/.config-local-*'));"),[]);
        assert.deepEqual(await query("echo json_encode(glob('/project/public-backups/*'));"),[]);
    });
    console.log(`\n${passed} setup-helper checks passed on PHP ${version} (WASM, not native PowerShell).`);
}
main().then(()=>{php.exit();process.exit(0);}).catch(e=>{console.error(e);if(php)php.exit();process.exit(1);});
