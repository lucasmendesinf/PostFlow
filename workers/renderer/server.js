// Renderizador do PostFlow como servico HTTP (Docker/VPS). Na hospedagem com cPanel, use cli.js.
//  GET  /health
//  POST /render  { formato, slides: [{html, css}], assets: {chave: dataURI} }
//    -> { imagens: [{ jpeg_base64, problemas: [] }] }
import http from 'node:http';
import { renderizar, fecharNavegador } from './render.js';

const PORT = Number(process.env.PORT || 3001);
const LIMITE = 25 * 1024 * 1024;

function json(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(body));
}

function lerCorpo(req) {
  return new Promise((resolve, reject) => {
    let tam = 0; const partes = [];
    req.on('data', (d) => { tam += d.length; if (tam > LIMITE) { reject(new Error('corpo grande demais')); req.destroy(); } else partes.push(d); });
    req.on('end', () => resolve(Buffer.concat(partes).toString('utf8')));
    req.on('error', reject);
  });
}

const server = http.createServer(async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    return json(res, 200, { status: 'ok', servico: 'renderer' });
  }
  if (req.method === 'POST' && req.url === '/render') {
    try {
      const pedido = JSON.parse(await lerCorpo(req));
      if (!Array.isArray(pedido.slides) || !pedido.slides.length || pedido.slides.length > 10) return json(res, 400, { erro: 'Envie de 1 a 10 slides.' });
      const imagens = await renderizar(pedido);
      return json(res, 200, { imagens: imagens.map((i) => ({ jpeg_base64: i.jpeg.toString('base64'), problemas: i.problemas })) });
    } catch (e) {
      return json(res, 500, { erro: e.message });
    }
  }
  return json(res, 404, { erro: 'Rota não encontrada.' });
});

server.listen(PORT, () => {
  console.log(`[renderer] ouvindo na porta ${PORT}`);
});

for (const sinal of ['SIGINT', 'SIGTERM']) {
  process.on(sinal, () => server.close(async () => { await fecharNavegador(); process.exit(0); }));
}
