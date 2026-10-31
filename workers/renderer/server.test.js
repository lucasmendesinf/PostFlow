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
