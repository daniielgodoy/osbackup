<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Caixinha de Natal — Noel centralizado</title>
<style>
  /* Página no tamanho 21 x 9 cm */
  @page { size: 21cm 9cm; margin: 0; }
  html, body { width: 21cm; height: 9cm; margin: 0; }
  body{
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
    font-family: "Inter", Arial, Helvetica, sans-serif;
    background:#fff;
    display:grid; place-items:center;   /* centraliza no meio da página */
  }

  /* CARTÃO CENTRAL COM BORDA EM 4 LADOS */
  .card{
    box-sizing: border-box;
    width: 19.2cm;              /* menor que a página para sobrar área de corte */
    height: 7.8cm;
    border-radius: 16px;

    /* Borda bengala doce feita com background-clip */
    border: 6px solid transparent; /* espessura da borda */
    background:
      linear-gradient(#fff, #fff) padding-box,
      repeating-linear-gradient(
        45deg,
        #d4242b 0 12px, #ffffff 12px 24px,
        #1d8f3a 24px 36px, #ffffff 36px 48px
      ) border-box;

    display: grid;
    grid-template-columns: 1fr 8.8cm; /* texto | noel */
    align-items: center;
    gap: 10mm;
    padding: 10mm 12mm;
    position: relative;
    box-shadow: 0 6px 18px rgba(0,0,0,.08);
    overflow: hidden;
  }

  /* Poás/festinhas */
  .festive{
    position:absolute; inset:6mm; border-radius:10px; pointer-events:none;
    background:
      radial-gradient(circle at 12% 24%, rgba(212,36,43,.12) 0 3px, transparent 4px) 0 0/90px 90px,
      radial-gradient(circle at 78% 70%, rgba(29,143,58,.12) 0 3px, transparent 4px) 0 0/110px 110px,
      radial-gradient(circle at 32% 72%, rgba(255,215,0,.25) 0 2px, transparent 3px) 0 0/80px 80px,
      radial-gradient(circle at 62% 18%, rgba(255,215,0,.25) 0 2px, transparent 3px) 0 0/80px 80px;
  }

  .left{ display:grid; gap:6px; }
  .title{ margin:0; font-size:54px; line-height:1.02; font-weight:900; color:#d4242b; text-shadow:0 2px 0 rgba(0,0,0,.12); }
  .subtitle{ margin:10px 0 0 0; font-size:22px; font-weight:700; color:#1d8f3a; }
  .note{ margin:8px 0 0 0; font-size:16px; color:#333; opacity:.95; }

  /* Noel + faixa centralizados entre si */
  .santa-wrap{
    width:100%;
    aspect-ratio: 1.15/1;
    position:relative;
    display:grid; place-items:center; /* centraliza a imagem */
  }
  .santa-img{
    width:100%; height:100%;
    object-fit: contain;
    filter: drop-shadow(0 6px 10px rgba(0,0,0,.18));
  }

  .ribbon{
    position:absolute;
    left:50%; transform: translateX(-50%);  /* centro perfeito sob o Noel */
    bottom: 8mm;
    min-width: 7.6cm; max-width: 90%;
    background:#1d8f3a; color:#fff; font-weight:800;
    text-align:center; padding:8px 12px; border-radius:999px;
    box-shadow: 0 3px 0 rgba(0,0,0,.15), inset 0 0 0 3px #e7c65a;
    letter-spacing:.06em;
  }

  @media print { .card{ box-shadow:none; } }
</style>
</head>
<body>
  <div class="card">
    <div class="festive" aria-hidden="true"></div>

    <div class="left">
      <h1 class="title">Caixinha de Natal</h1>
      <p class="subtitle">🎄 Espalhe alegria — sua contribuição faz a diferença!</p>
      <p class="note">Sua gorjeta é <strong>100% para os funcionários</strong>. Obrigado e boas festas! ✨</p>
    </div>

    <div class="santa-wrap">
      <img class="santa-img" src="noel.png" alt="Papai Noel sorridente">
      <div class="ribbon">Feliz Natal!</div>
    </div>
  </div>
</body>
</html>
