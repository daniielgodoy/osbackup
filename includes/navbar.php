<?php
// garante que $pagina está definido em cada página antes de incluir a navbar
$pagina = $pagina ?? '';
?>
<nav class="navbar">
  <div class="nav-left">
    <a href="index.php" class="brand">
      <img src="images/logo.png" class="logo" alt="Logo">
    </a>
  </div>

  <ul class="nav-center">
    <li class="<?= $pagina==='painel'    ? 'active' : '' ?>"><a href="index.php">Painel</a></li>
    <li class="<?= $pagina==='os'        ? 'active' : '' ?>"><a href="os.php">Ordens de Serviço</a></li>
    <li class="<?= $pagina==='clientes'  ? 'active' : '' ?>"><a href="clientes.php">Clientes</a></li>
    <li class="<?= $pagina==='relatorios'? 'active' : '' ?>"><a href="relatorios.php">Relatórios</a></li>
    <li class="<?= $pagina==='config'    ? 'active' : '' ?>"><a href="config.php">Configurações</a></li>
  </ul>

  <div class="nav-right">
    <button class="icon-btn" title="Notificações"><i class="fa fa-bell"></i></button>

    <button class="icon-btn" id="themeToggle" title="Alternar tema">
      <i class="fa-solid fa-moon" id="themeIcon"></i>
    </button>

    <!-- Botão de Logout -->
    <button class="icon-btn" id="btnLogout" title="Sair">
      <i class="fa-solid fa-right-from-bracket"></i>
    </button>

    <!-- Fallback sem JS (caminho correto) -->
    <a href="includes/logout.php" class="sr-only" aria-hidden="true" tabindex="-1">Sair</a>
  </div>
</nav>

<script>
(function(){
  // ===== Tema =====
  function whenReady(fn){
    if (window.V10Theme && typeof window.V10Theme.get === 'function') return fn();
    let tries = 0;
    const t = setInterval(() => {
      if (window.V10Theme && typeof window.V10Theme.get === 'function') { clearInterval(t); fn(); }
      else if (++tries > 80) { clearInterval(t); console.warn('V10Theme não disponível; tema não será alternado.'); }
    }, 50);
  }

  whenReady(() => {
    const btn  = document.getElementById('themeToggle');
    const icon = document.getElementById('themeIcon');

    function paintIcon(mode){
      if (!icon) return;
      icon.className = (mode === 'light') ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    }
    paintIcon(window.V10Theme.get());

    btn?.addEventListener('click', () => {
      const cur  = window.V10Theme.get();
      const next = (cur === 'dark') ? 'light' : 'dark';
      window.V10Theme.set(next);
      paintIcon(next);
    });

    window.addEventListener('storage', (e) => {
      if (e.key === 'theme') paintIcon(window.V10Theme.get());
    });
  });

  // ===== Logout (sem AJAX — redireciona direto) =====
  document.addEventListener('click', (e)=>{
    const btn = e.target.closest('#btnLogout');
    if(!btn) return;
    // Se seu projeto está em /os na raiz do Apache, pode usar absoluto: window.location.href = '/os/includes/logout.php';
    window.location.href = 'includes/logout.php?next=login.php';
  });
})();
</script>
