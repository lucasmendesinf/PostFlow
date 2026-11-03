// Renderizador por linha de comando (hospedagem com cPanel, sem servico rodando o tempo todo).
// O PostFlow chama:  node cli.js < pedido.json > resposta.json
// Mesmo formato do POST /render do server.js.
import { renderizar, fecharNavegador } from './render.js';

let entrada = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (d) => { entrada += d; });
process.stdin.on('end', async () => {
  try {
    const pedido = JSON.parse(entrada);
    if (!Array.isArray(pedido.slides) || !pedido.slides.length || pedido.slides.length > 10) throw new Error('Envie de 1 a 10 slides.');
    const imagens = await renderizar(pedido);
    process.stdout.write(JSON.stringify({ imagens: imagens.map((i) => ({ jpeg_base64: i.jpeg.toString('base64'), problemas: i.problemas })) }));
    await fecharNavegador();
    process.exit(0);
  } catch (e) {
    process.stdout.write(JSON.stringify({ erro: e.message }));
    await fecharNavegador();
    process.exit(1);
  }
});
