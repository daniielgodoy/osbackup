<?php
// garante que a sessão exista
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// garante que $pagina está definido em cada página antes de incluir a navbar
$pagina = $pagina ?? '';

// papel do usuário
$role    = $_SESSION['role'] ?? 'member';
$isAdmin = ($role === 'admin');
?>
<nav class="navbar">
  <div class="nav-left">
    <a href="index.php" class="brand">
      <img src="images/logo.png" class="logo" alt="Logo">
    </a>
  </div>

  <ul class="nav-center">
    <!-- Sempre visíveis para qualquer usuário logado -->
    <li class="<?= $pagina==='painel' ? 'active' : '' ?>">
      <a href="index.php">Painel</a>
    </li>
    <li class="<?= $pagina==='os' ? 'active' : '' ?>">
      <a href="os.php">Ordens de Serviço</a>
    </li>

    <!-- Somente ADMIN vê esses menus -->
    <?php if ($isAdmin): ?>
      <li class="<?= $pagina==='clientes'   ? 'active' : '' ?>">
        <a href="clientes.php">Clientes</a>
      </li>
      <li class="<?= $pagina==='relatorios' ? 'active' : '' ?>">
        <a href="relatorios.php">Relatórios</a>
      </li>
      <li class="<?= $pagina==='config'     ? 'active' : '' ?>">
        <a href="config.php">Configurações</a>
      </li>
      <li class="<?= $pagina==='equipe'     ? 'active' : '' ?>">
        <a href="equipe.php">Equipe</a>
      </li>
    <?php endif; ?>
  </ul>

  <div class="nav-right">
    <button class="icon-btn" title="Notificações"><i class="fa fa-bell"></i></button>

    <button class="icon-btn" id="themeToggle" title="Alternar tema">
      <i class="fa-solid fa-moon" id="themeIcon"></i>
    </button>

    <button class="icon-btn" id="btnLogout" title="Sair">
      <i class="fa-solid fa-right-from-bracket"></i>
    </button>

    <a href="includes/logout.php" class="sr-only" aria-hidden="true" tabindex="-1">Sair</a>
  </div>
</nav>

<script>
(function(){
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
      icon.className = (mode === 'light')
        ? 'fa-solid fa-sun'
        : 'fa-solid fa-moon';
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

  // Logout simples
  document.addEventListener('click', (e)=>{
    const btn = e.target.closest('#btnLogout');
    if(!btn) return;
    window.location.href = 'includes/logout.php?next=login.php';
  });
})();
</script>
