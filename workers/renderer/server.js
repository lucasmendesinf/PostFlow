// Renderizador do PostFlow (Node + Playwright).
// Etapa 1: só o esqueleto do serviço e o /health usado pelo job de teste.
// Etapa 4: POST /render recebe HTML + CSS, sanitiza, resolve {{asset:id}} e fontes locais
// e devolve o JPEG (1080x1350 ou 1080x1920, qualidade 90).

import http from 'node:http';

const PORT = Number(process.env.PORT || 3001);

function json(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(body));
}

const server = http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    return json(res, 200, { status: 'ok', servico: 'renderer' });
  }

  if (req.method === 'POST' && req.url === '/render') {
    return json(res, 501, { erro: 'Renderização entra na etapa 4.' });
  }

  return json(res, 404, { erro: 'Rota não encontrada.' });
});

server.listen(PORT, () => {
  console.log(`[renderer] ouvindo na porta ${PORT}`);
});

for (const sinal of ['SIGINT', 'SIGTERM']) {
  process.on(sinal, () => server.close(() => process.exit(0)));
}
