// Nucleo do renderizador: HTML + CSS do Claude -> JPEG (feed 1080x1350, stories 1080x1920).
// Usado pelo servidor HTTP (server.js, Docker/VPS) e pela linha de comando (cli.js, hospedagem com cPanel).
//
// Seguranca: o HTML vem de uma IA, entao e tratado como nao confiavel.
//  - scripts, handlers on*, <iframe>/<object>/<embed>/<link>/<meta>/<base> e URLs externas sao removidos;
//  - a pagina roda com JavaScript desligado e TODA requisicao de rede e bloqueada (so data: passa);
//  - imagens so entram por {{asset:chave}}, trocadas aqui por data: URIs que o PostFlow fornece;
//  - fontes so da biblioteca local (@fontsource), embutidas como data: URIs.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

export const TAMANHOS = { feed: [1080, 1350], carrossel: [1080, 1350], stories: [1080, 1920] };

// Familias disponiveis -> pacote @fontsource
export const FONTES = {
  Saira: 'saira', Montserrat: 'montserrat', Anton: 'anton', 'Bebas Neue': 'bebas-neue', Oswald: 'oswald', Inter: 'inter',
};
const PESOS = [300, 400, 500, 600, 700, 800, 900];

function arquivoFonte(pacote, peso) {
  try {
    const dir = path.join(path.dirname(require.resolve(`@fontsource/${pacote}/package.json`)), 'files');
    const f = path.join(dir, `${pacote}-latin-${peso}-normal.woff2`);
    return fs.existsSync(f) ? f : null;
  } catch { return null; }
}

// @font-face so das familias citadas no HTML/CSS
export function cssFontes(texto) {
  let css = '';
  for (const [familia, pacote] of Object.entries(FONTES)) {
    if (!new RegExp(familia.replace(' ', '\\s*'), 'i').test(texto)) continue;
    for (const peso of PESOS) {
      const f = arquivoFonte(pacote, peso);
      if (f) css += `@font-face{font-family:'${familia}';font-weight:${peso};font-style:normal;src:url(data:font/woff2;base64,${fs.readFileSync(f).toString('base64')}) format('woff2');}\n`;
    }
  }
  return css;
}

export function sanitizar(html) {
  let s = String(html || '');
  s = s.replace(/<\s*(script|iframe|object|embed|form|textarea|select|button|video|audio|noscript|template)\b[\s\S]*?<\s*\/\s*\1\s*>/gi, '');
  s = s.replace(/<\s*\/?\s*(script|iframe|object|embed|link|meta|base|form|input|button|textarea|select|video|audio|source|track|noscript|template)\b[^>]*>/gi, '');
  s = s.replace(/\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
  s = s.replace(/javascript:/gi, '');
  // URLs externas em atributos e em url(...) / @import
  s = s.replace(/\s(src|href|srcset|poster|xlink:href)\s*=\s*("(?!data:)[^"]*"|'(?!data:)[^']*')/gi, '');
  s = s.replace(/url\(\s*(['"]?)(?!data:|#)[^)]*\1\s*\)/gi, 'none');
  s = s.replace(/@import[^;]+;/gi, '');
  return s;
}

// {{asset:chave}} -> data URI (o PostFlow manda os assets ja como data:)
export function resolverAssets(texto, assets = {}) {
  return String(texto || '').replace(/\{\{\s*asset:([a-zA-Z0-9_\-]+)\s*\}\}/g, (m, chave) => assets[chave] || '');
}

export function montarPagina({ html, css = '', formato = 'feed', assets = {} }) {
  const [w, h] = TAMANHOS[formato] || TAMANHOS.feed;
  const corpo = sanitizar(resolverAssets(html, assets));
  const estilo = sanitizar(`<style>${resolverAssets(css, assets)}</style>`).replace(/^<style>|<\/style>$/g, '');
  return `<!doctype html><html><head><meta charset="utf-8"><style>
${cssFontes(`${html} ${css}`)}
*{box-sizing:border-box}html,body{margin:0;padding:0;width:${w}px;height:${h}px;overflow:hidden;background:#000}
</style><style>${estilo}</style></head><body>${corpo}</body></html>`;
}

let navegador = null;
async function abrirNavegador() {
  if (navegador && navegador.isConnected()) return navegador;
  const { chromium } = await import('playwright');
  navegador = await chromium.launch({
    executablePath: process.env.CHROMIUM_PATH || undefined,
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu', ...(process.env.RENDER_SINGLE_PROCESS === '1' ? ['--single-process', '--no-zygote'] : [])],
  });
  return navegador;
}
export async function fecharNavegador() {
  if (navegador) await navegador.close().catch(() => {});
  navegador = null;
}

// Verifica texto saindo do quadro (o PostFlow devolve os problemas ao Claude para corrigir)
async function problemasDeLayout(page, w, h) {
  return page.evaluate(([W, H]) => {
    const out = [];
    for (const el of document.body.querySelectorAll('*')) {
      const temTexto = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim());
      if (!temTexto) continue;
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      const t = el.textContent.trim().slice(0, 40);
      if (r.left < -1 || r.top < -1 || r.right > W + 1 || r.bottom > H + 1) out.push(`texto "${t}" sai do quadro`);
      else if (el.scrollWidth > el.clientWidth + 2 && getComputedStyle(el).overflowX !== 'visible') out.push(`texto "${t}" cortado na largura`);
    }
    return [...new Set(out)].slice(0, 10);
  }, [w, h]);
}

// slides: [{ html, css }]. Devolve [{ jpeg: Buffer, problemas: [] }]
export async function renderizar({ slides, formato = 'feed', assets = {}, qualidade = 90 }) {
  const [w, h] = TAMANHOS[formato] || TAMANHOS.feed;
  const b = await abrirNavegador();
  const ctx = await b.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, javaScriptEnabled: false });
  await ctx.route('**/*', (route) => (route.request().url().startsWith('data:') ? route.continue() : route.abort()));
  try {
    const saida = [];
    for (const s of slides) {
      const page = await ctx.newPage();
      await page.setContent(montarPagina({ html: s.html, css: s.css, formato, assets }), { waitUntil: 'load', timeout: 20000 });
      await page.evaluate(() => document.fonts.ready).catch(() => {});
      const problemas = await problemasDeLayout(page, w, h).catch(() => []);
      const jpeg = await page.screenshot({ type: 'jpeg', quality: qualidade, clip: { x: 0, y: 0, width: w, height: h } });
      await page.close();
      saida.push({ jpeg, problemas });
    }
    return saida;
  } finally {
    await ctx.close().catch(() => {});
  }
}
