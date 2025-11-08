// server.js (multi-device, completo)
// Requisitos:
//   package.json: { "type": "module" }
//   npm i express @whiskeysockets/baileys qrcode-terminal pino qrcode
//
// Cada "device" (ex.: t1_s3) tem seu próprio AUTH_DIR e seu próprio socket.
// Todas as rotas aceitam ?device=tX_sY (ou no body { device }) — default: "default".

import express from 'express';
import makeWASocket, {
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion
} from '@whiskeysockets/baileys';
import qrcodeTerminal from 'qrcode-terminal';
import pino from 'pino';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

// (opcional) PNG do QR
let QRLib = null;
try { QRLib = await import('qrcode'); } catch {}

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);

// Diretório RAIZ; cada device vira uma subpasta
const AUTH_DIR_ROOT = process.env.AUTH_DIR_ROOT || 'C:/OSWaAuth';
const PORT          = Number(process.env.PORT || 3001);
const logger        = pino({ level: process.env.WA_LOG_LEVEL || 'silent' });

// ────────────────────────────────────────────────────────────
// Estado por device
// ────────────────────────────────────────────────────────────
const DEVICES = new Map();
/*
  DEVICES.get(deviceKey) -> {
    sock, ready, connected, latestQR, latestQRAt, meJid, starting
  }
*/
const ensureDeviceState = (deviceKey) => {
  if (!DEVICES.has(deviceKey)) {
    DEVICES.set(deviceKey, {
      sock: null,
      ready: false,
      connected: false,
      latestQR: null,
      latestQRAt: null,
      meJid: null,
      starting: false
    });
  }
  return DEVICES.get(deviceKey);
};

const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const safeHasWS = (dev) => !!(dev.sock && dev.sock.ws && typeof dev.sock.ws.close === 'function');

function authDirFor(deviceKey) {
  const safe = String(deviceKey || 'default').replace(/[^a-z0-9_\-\.]/gi, '_');
  return path.join(AUTH_DIR_ROOT, safe);
}
function nukeAuthDir(deviceKey) {
  const dir = authDirFor(deviceKey);
  try { if (fs.existsSync(dir)) fs.rmSync(dir, { recursive: true, force: true }); } catch {}
}

// ────────────────────────────────────────────────────────────
// Inicialização por device
// ────────────────────────────────────────────────────────────
async function startDevice(deviceKey, forceFresh = false) {
  const dev = ensureDeviceState(deviceKey);
  if (dev.starting) return;
  dev.starting = true;

  try {
    const dir = authDirFor(deviceKey);
    if (forceFresh) nukeAuthDir(deviceKey);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });

    const { version } = await fetchLatestBaileysVersion();
    const { state, saveCreds } = await useMultiFileAuthState(dir);

    const sock = makeWASocket({
      auth: state,
      version,
      printQRInTerminal: false,
      logger,
      syncFullHistory: false
    });
    dev.sock = sock;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (u) => {
      const { connection, lastDisconnect, qr } = u || {};

      if (qr) {
        dev.latestQR   = qr;
        dev.latestQRAt = new Date().toISOString();

        console.log(`📲 [${deviceKey}] Escaneie o QR:`);
        try { qrcodeTerminal.generate(qr, { small: true }); } catch {}
      }

      if (connection === 'open') {
        dev.ready = true;
        dev.connected = true;
        dev.meJid = sock?.user?.id || null;
        dev.latestQR = null;
        dev.latestQRAt = null;
        console.log(`✅ [${deviceKey}] Conectado.`, dev.meJid ? `(${dev.meJid})` : '');
      }

      if (connection === 'close') {
        dev.ready = false;
        dev.connected = false;

        const code =
          lastDisconnect?.error?.output?.statusCode ??
          lastDisconnect?.error?.status ??
          lastDisconnect?.error?.code ??
          null;

        console.log(`⚠️  [${deviceKey}] Conexão fechada. Status:`, code ?? '(?)');

        if (code === DisconnectReason.loggedOut || code === 401) {
          nukeAuthDir(deviceKey);
          console.log(`🔒 [${deviceKey}] Logout detectado. Limpando auth e reiniciando…`);
        } else {
          console.log(`♻️ [${deviceKey}] Reconectando…`);
        }

        dev.latestQR = null;
        dev.latestQRAt = null;
        setTimeout(() => startDevice(deviceKey, false), 1200);
      }
    });

  } catch (err) {
    console.error(`❌ [${deviceKey}] Erro ao iniciar:`, err?.message || err);
    setTimeout(() => startDevice(deviceKey, forceFresh), 1500);
  } finally {
    dev.starting = false;
  }
}

// Inicializa "default" para já subir algo
await startDevice('default', false);

// ────────────────────────────────────────────────────────────
// HTTP API
// ────────────────────────────────────────────────────────────
const app = express();
app.use((req, res, next) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});
app.use(express.json({ limit: '1mb' }));

function paramDevice(req) {
  // aceita query ?device=... ou body { device }
  return (req.query.device || (req.body && req.body.device) || 'default');
}
function normalizePhone(n) {
  const d = String(n || '').replace(/\D+/g, '');
  if (!d) return null;
  return d.startsWith('55') ? d : '55' + d;
}
function noStore(res) {
  res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
  res.setHeader('Pragma', 'no-cache');
  res.setHeader('Expires', '0');
}

app.get('/', (_req, res) => res.json({ ok: true, service: 'wa-server', multi: true }));

// Enviar mensagem
app.post('/send', async (req, res) => {
  const device = paramDevice(req);
  const dev = ensureDeviceState(device);
  try {
    if (!dev.ready || !dev.connected) {
      return res.status(503).json({ ok:false, error:`[${device}] WhatsApp não conectado (gere o QR).` });
    }
    const { phone, message } = req.body || {};
    if (!phone || !message) return res.status(400).json({ ok:false, error:'Campos "phone" e "message" são obrigatórios.' });

    const norm = normalizePhone(phone);
    if (!norm) return res.status(400).json({ ok:false, error:'Telefone inválido' });

    const jid = `${norm}@s.whatsapp.net`;
    await dev.sock.sendMessage(jid, { text: message });
    res.json({ ok:true });
  } catch (e) {
    console.error(`❌ [/send ${device}]`, e);
    res.status(500).json({ ok:false, error:String(e?.message || e) });
  }
});

// Status
app.get('/status', async (req, res) => {
  const device = paramDevice(req);
  ensureDeviceState(device); // garante chave
  const dev = DEVICES.get(device);

  // se nunca iniciou essa chave, sobe agora
  if (!dev.sock && !dev.starting) await startDevice(device, false);

  res.json({
    ok: true,
    device,
    connected: !!dev.connected,
    ready: !!dev.ready,
    me: dev.meJid || null,
    hasQR: !!dev.latestQR,
    lastQRAt: dev.latestQRAt
  });
});

// QR (texto)
app.get('/qr', async (req, res) => {
  const device = paramDevice(req);
  noStore(res);
  const dev = ensureDeviceState(device);
  if (!dev.sock && !dev.starting) await startDevice(device, false);

  if (dev.connected) return res.json({ ok:true, device, connected:true, qr:null });
  if (!dev.latestQR)   return res.status(404).json({ ok:false, device, error:'QR indisponível. Aguarde.' });
  res.json({ ok:true, device, qr: dev.latestQR });
});

// QR (PNG via dataURL)
app.get('/qr/png', async (req, res) => {
  const device = paramDevice(req);
  noStore(res);
  if (!QRLib) return res.status(501).json({ ok:false, error:'Pacote "qrcode" não instalado.' });

  const dev = ensureDeviceState(device);
  if (!dev.sock && !dev.starting) await startDevice(device, false);

  if (dev.connected) return res.json({ ok:true, device, connected:true, dataUrl:null });
  if (!dev.latestQR)  return res.status(404).json({ ok:false, device, error:'QR indisponível. Aguarde.' });

  try {
    const dataUrl = await QRLib.toDataURL(dev.latestQR, { errorCorrectionLevel:'M', margin:1, width:300 });
    res.json({ ok:true, device, dataUrl });
  } catch (e) {
    res.status(500).json({ ok:false, device, error:String(e?.message || e) });
  }
});

// Reconnect (fecha socket, mantém credenciais)
app.post('/reconnect', async (req, res) => {
  const device = paramDevice(req);
  const dev = ensureDeviceState(device);
  try {
    dev.latestQR = null;
    dev.latestQRAt = null;
    dev.ready = false;
    dev.connected = false;
    try { if (safeHasWS(dev)) await dev.sock.ws.close(); } catch {}
    setTimeout(() => startDevice(device, false), 300);
    res.json({ ok:true, device });
  } catch (e) {
    res.status(500).json({ ok:false, device, error:String(e?.message || e) });
  }
});

// Logout (apaga auth desse device e reinicia limpo)
app.post('/logout', async (req, res) => {
  const device = paramDevice(req);
  const dev = ensureDeviceState(device);
  try {
    try { await dev.sock?.logout?.(); } catch {}
    nukeAuthDir(device);

    dev.latestQR = null;
    dev.latestQRAt = null;
    dev.meJid = null;
    dev.ready = false;
    dev.connected = false;

    setTimeout(() => startDevice(device, true), 300);
    res.json({ ok:true, device });
  } catch (e) {
    res.status(500).json({ ok:false, device, error:String(e?.message || e) });
  }
});

// Reset-auth (alias de logout)
app.post('/reset-auth', async (req, res) => {
  const device = paramDevice(req);
  const dev = ensureDeviceState(device);
  try {
    try { if (safeHasWS(dev)) await dev.sock.ws.close(); } catch {}
    nukeAuthDir(device);

    dev.latestQR = null;
    dev.latestQRAt = null;
    dev.meJid = null;
    dev.ready = false;
    dev.connected = false;

    setTimeout(() => startDevice(device, true), 300);
    res.json({ ok:true, device });
  } catch (e) {
    res.status(500).json({ ok:false, device, error:String(e?.message || e) });
  }
});

app.listen(PORT, () => {
  console.log(`✅ WA server (multi) em http://127.0.0.1:${PORT}`);
  console.log('Rotas: /send, /status, /qr, /qr/png, /reconnect, /logout, /reset-auth  (use ?device=...)');
});

// Encerramento
function gracefulExit(code=0){
  console.log('🛑 Encerrando servidor...');
  for (const [k, dev] of DEVICES.entries()) {
    try { if (safeHasWS(dev)) dev.sock.ws.close(); } catch {}
  }
  process.exit(code);
}
process.on('SIGINT',  () => gracefulExit(0));
process.on('SIGTERM', () => gracefulExit(0));
