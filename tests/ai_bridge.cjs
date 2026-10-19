/** Test-only WASM -> real Python bridge. No fixtures are substituted for inference. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawn} = require('node:child_process');
const {EventEmitter} = require('node:events');
module.exports = async function installAiBridge(php) {
    const python = process.env.LUFLY_TEST_PYTHON;
    const model = process.env.LUFLY_TEST_X4_MODEL;
    assert.ok(fs.existsSync(python));
    if (model) assert.ok(fs.existsSync(model));
    php.writeFile('/test-python','Explicitly configured test interpreter');
    if (model) php.writeFile('/test-x4-model','Host bridge maps this to the real pinned model');
    const state = {runs:0, fail:false, wrongScale:false};
    await php.setSpawnHandler((command,args)=>{
        assert.equal(command,'/test-python');
        assert.equal(args[0],'/app/tools/image_enhancement/enhance.py');
        const input=args[args.indexOf('--input')+1], output=args[args.indexOf('--output')+1];
        const requested=args[args.indexOf('--profile')+1];
        assert.ok(['ai','ai4'].includes(requested));
        const profile=state.wrongScale ? 'ai' : requested;
        const temp=fs.mkdtempSync(path.join(os.tmpdir(),'lufly-ai-bridge-'));
        const source=path.join(temp,'input.png'), target=path.join(temp,'output.png');
        fs.writeFileSync(source,php.readFileAsBuffer(input));
        const parameters=[path.resolve(__dirname,'../tools/image_enhancement/enhance.py'),'--input',source,'--output',target,'--profile',profile];
        if (profile==='ai4') {
            assert.equal(args[args.indexOf('--model-path')+1],'/test-x4-model');
            parameters.push('--model-path',model);
        }
        const child=spawn(python,state.fail ? ['-c','raise SystemExit(1)'] : parameters);
        const bridge=new EventEmitter();
        bridge.stdout=child.stdout;bridge.stderr=child.stderr;bridge.stdin=child.stdin;bridge.pid=child.pid;
        bridge.kill=(...args)=>child.kill(...args);
        child.on('spawn',()=>bridge.emit('spawn'));
        child.on('error',error=>bridge.emit('error',error));
        child.on('close',code=>{
            if (code===0) php.writeFile(output,fs.readFileSync(target));
            fs.rmSync(temp,{recursive:true,force:true});
            bridge.emit('exit',code);
        });
        state.runs++;return bridge;
    });
    return state;
};
