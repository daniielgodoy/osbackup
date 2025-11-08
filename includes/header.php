<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>V10 OS - Painel</title>

  <!-- Seus estilos base -->
  <link rel="stylesheet" href="./css/style.css">
  <link rel="stylesheet" href="./css/navbar.css">
  <link rel="stylesheet" href="./css/modal.css">

  <!-- Estilos condicionais por página -->
  <?php if (basename($_SERVER['PHP_SELF']) === 'os.php'): ?>
    <link rel="stylesheet" href="./css/os.css">
  <?php endif; ?>

  <?php if (basename($_SERVER['PHP_SELF']) === 'clientes.php'): ?>
    <link rel="stylesheet" href="./css/clientes.css">
  <?php endif; ?>

  <?php if (basename($_SERVER['PHP_SELF']) === 'relatorios.php'): ?>
    <link rel="stylesheet" href="./css/relatorios.css">
  <?php endif; ?>

  <!-- Libs -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- Script de TEMA (único) + no-flicker -->
  <script>
  (function(){
    if(window.__V10_SIMPLE_THEME__) return;
    window.__V10_SIMPLE_THEME__ = true;

    const ROOT = document.documentElement;
    const LS_KEY = 'theme';   // 'dark' | 'light'
    let ignore = false;

    function noFlicker(fn){
      ROOT.classList.add('no-transitions');
      try{ fn(); }finally{ setTimeout(()=>ROOT.classList.remove('no-transitions'), 60); }
    }
    function apply(theme){
      const t = (theme==='light' || theme==='dark') ? theme : 'dark';
      noFlicker(()=> {
        ROOT.setAttribute('data-theme', t);
        ROOT.setAttribute('data-theme-source', t);
      });
    }
    function get(){ try{ return localStorage.getItem(LS_KEY) || 'dark'; }catch(_){ return 'dark'; } }
    function set(t){
      try{ ignore = true; localStorage.setItem(LS_KEY, t); setTimeout(()=>ignore=false, 30); }catch(_){}
      apply(t);
    }

    // boot inicial
    apply(get());

    // sincroniza com outras abas
    window.addEventListener('storage', (e)=>{
      if(e.key===LS_KEY && !ignore) apply(e.newValue || 'dark');
    });

    // API global
    window.V10Theme = { set, get };
  })();
  </script>
  <style>
    /* mata flicker/transitions durante troca de tema */
    html.no-transitions, html.no-transitions *{
      transition: none !important;
      animation: none !important;
    }
  </style>

  <!-- Tokens/variáveis e regras de tema -->
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/theme-light.css">
  <!-- Shim de compatibilidade: neutraliza overrides agressivos do theme.css
       (DEVE ficar por ÚLTIMO para preservar seus estilos antigos) -->
  <link rel="stylesheet" href="css/theme-compat.css">
</head>
