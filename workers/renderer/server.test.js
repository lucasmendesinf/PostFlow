import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';

test('GET /health responde ok', async () => {
  const port = 3901;
  const proc = spawn(process.execPath, ['server.js'], {
    cwd: import.meta.dirname,
    env: { ...process.env, PORT: String(port) },
  });

  try {
    await new Promise((resolve) => proc.stdout.once('data', resolve));
    const res = await fetch(`http://127.0.0.1:${port}/health`);
    assert.equal(res.status, 200);
    assert.deepEqual(await res.json(), { status: 'ok', servico: 'renderer' });
  } finally {
    proc.kill();
  }
});

test('sanitiza scripts, eventos e URLs externas', async () => {
  const { sanitizar, resolverAssets } = await import('./render.js');
  const s = sanitizar('<div onclick="x()">oi<script>alert(1)</script><img src="https://mal.com/a.png"><img src="data:image/png;base64,AA"></div><style>div{background:url(https://x.com/y.png)}</style>');
  assert.ok(!/script|onclick|mal\.com|x\.com/.test(s), s);
  assert.ok(s.includes('data:image/png;base64,AA'));
  assert.equal(resolverAssets('<img src="{{asset:logo}}">', { logo: 'data:image/png;base64,ZZ' }), '<img src="data:image/png;base64,ZZ">');
});

test('renderiza JPEG no tamanho do formato e aponta texto fora do quadro', { skip: !process.env.CHROMIUM_PATH && !process.env.RENDER_TEST && 'defina CHROMIUM_PATH ou RENDER_TEST=1' }, async () => {
  const { renderizar, fecharNavegador } = await import('./render.js');
  const [ok] = await renderizar({ formato: 'stories', slides: [{ html: '<div class="t">PostFlow</div>', css: ".t{font-family:'Saira';font-size:120px;color:#FD5002;padding:300px 80px}" }] });
  assert.equal(ok.jpeg[0], 0xff); assert.equal(ok.jpeg[1], 0xd8); // JPEG
  assert.deepEqual(ok.problemas, []);
  const [ruim] = await renderizar({ formato: 'feed', slides: [{ html: '<div style="position:absolute;left:900px;top:100px;font-size:120px;white-space:nowrap">Texto muito largo</div>' }] });
  assert.ok(ruim.problemas.length > 0);
  await fecharNavegador();
});
