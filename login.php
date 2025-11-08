<?php
// /os/login.php

// 1) Se já estiver logado, redireciona
session_start();
if (!empty($_SESSION['user_id'])) {
  header('Location: index.php');
  exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Entrar • V10</title>
  <link rel="stylesheet" href="css/auth.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body class="auth-body">
  <main class="auth-container">
    <section class="auth-card">
      <div class="panel panel-left">
        <div class="brand">
          <img src="images/logo.png" alt="Logo" class="logo-img" />
        </div>

        <!-- Sign in -->
        <form id="formSignIn" class="form active" autocomplete="on" novalidate>
          <h1>Entrar</h1>
          <p class="muted">Use seu e-mail <strong>ou</strong> usuário e sua senha.</p>

          <div class="field">
            <label for="si_identifier">E-mail ou usuário</label>
            <input id="si_identifier" name="identifier" type="text" placeholder="voce@exemplo.com ou @apelido" required>
          </div>

          <div class="field with-addon">
            <label for="si_password">Senha</label>
            <div class="input-wrap">
              <input id="si_password" name="password" type="password" placeholder="••••••••" minlength="6" required>
              <button type="button" class="addon eye-btn" data-toggle="#si_password" aria-label="Mostrar/ocultar senha">
                <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
              </button>
            </div>
          </div>

          <div class="row between">
            <label class="check">
              <input type="checkbox" id="remember"> Manter conectado
            </label>
            <a href="#" class="link small" id="forgotLink">Esqueci a senha</a>
          </div>

          <button class="btn primary full" id="btnSignIn" type="submit">
            <span>Entrar</span>
          </button>

          <p class="switcher">Não tem conta? <button class="link" type="button" id="goSignUp">Criar conta</button></p>

          <div id="si_msg" class="msg" role="alert" aria-live="polite"></div>
        </form>

        <!-- Sign up -->
        <form id="formSignUp" class="form" autocomplete="off" novalidate>
          <h1>Criar conta</h1>
          <p class="muted">Criamos um tenant (empresa) e uma loja padrão para você.</p>

          <div class="field">
            <label for="su_nome">Nome</label>
            <input id="su_nome" name="nome" type="text" placeholder="Seu nome" required>
          </div>

          <div class="field">
            <label for="su_handle">@usuário</label>
            <div class="handle-wrap">
              <span>@</span>
              <input id="su_handle" name="handle" type="text" placeholder="apelido-unico" pattern="[a-zA-Z0-9_\.]{3,}" title="Use 3+ caracteres: letras, números, _ ou .">
            </div>
          </div>

          <div class="field">
            <label for="su_email">E-mail</label>
            <input id="su_email" name="email" type="email" inputmode="email" placeholder="voce@exemplo.com" required>
          </div>

          <div class="field with-addon">
            <label for="su_password">Senha</label>
            <div class="input-wrap">
              <input id="su_password" name="password" type="password" placeholder="••••••••" minlength="6" required>
              <button type="button" class="addon eye-btn" data-toggle="#su_password" aria-label="Mostrar/ocultar senha">
                <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
              </button>
            </div>
          </div>

          <button class="btn primary full" id="btnSignUp" type="submit">
            <span>Criar conta</span>
          </button>

          <p class="switcher">Já tem conta? <button class="link" type="button" id="goSignIn">Entrar</button></p>

          <div id="su_msg" class="msg" role="alert" aria-live="polite"></div>
        </form>
      </div>

      <div class="panel panel-right">
        <div class="hero">
          <h2>Bem-vindo(a)!</h2>
          <p>Organize suas ordens de serviço, relatórios e controle financeiro em um único painel.</p>
        </div>
      </div>
    </section>

    <footer class="auth-footer">
      <small>© <?= date('Y') ?> V10 • Todos os direitos reservados</small>
    </footer>
  </main>

<script>
const $ = (s, p=document)=>p.querySelector(s);

// troca entre forms
$('#goSignUp')?.addEventListener('click', ()=> switchForm('up'));
$('#goSignIn')?.addEventListener('click', ()=> switchForm('in'));
function switchForm(which){
  const inF = $('#formSignIn'), upF = $('#formSignUp');
  if (which==='up'){ inF.classList.remove('active'); upF.classList.add('active'); }
  else { upF.classList.remove('active'); inF.classList.add('active'); }
  $('#si_msg').textContent = ''; $('#su_msg').textContent = '';
}

// olho da senha
document.querySelectorAll('.eye-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const input = document.querySelector(btn.dataset.toggle);
    if (!input) return;
    const icon = btn.querySelector('i');
    const open = input.type === 'text';
    input.type = open ? 'password' : 'text';
    icon.classList.toggle('fa-eye', !open);
    icon.classList.toggle('fa-eye-slash', open);
  });
});

// wrapper da API
async function api(action, body){
  const r = await fetch('includes/auth_api.php?action='+encodeURIComponent(action), {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body||{}),
    credentials: 'same-origin',
    cache: 'no-store'
  });
  let data = {};
  try { data = await r.json(); } catch(_){}
  if (!r.ok || data.ok === false) {
    // Mostra detalhe se existir
    const msg = data.detail || data.error || ('Erro ('+r.status+')');
    throw new Error(msg);
  }
  return data;
}


// login
document.getElementById('formSignIn')?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const identifier = $('#si_identifier').value.trim();
  const password   = $('#si_password').value;
  const remember   = document.getElementById('remember').checked ? 1 : 0;
  const btn = document.getElementById('btnSignIn');
  const msg = document.getElementById('si_msg');
  btn.disabled = true; msg.textContent = '';
  try{
    await api('login', { identifier, password, remember });
    location.href = 'index.php';
  }catch(err){
    msg.textContent = err.message;
  }finally{
    btn.disabled = false;
  }
});

// cadastro
document.getElementById('formSignUp')?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const nome     = $('#su_nome').value.trim();
  const handle   = $('#su_handle').value.trim();
  const email    = $('#su_email').value.trim();
  const password = $('#su_password').value;
  const btn = document.getElementById('btnSignUp');
  const msg = document.getElementById('su_msg');
  btn.disabled = true; msg.textContent = '';
  try{
    await api('register', { nome, handle, email, password });
    location.href = 'index.php';
  }catch(err){
    msg.textContent = err.message;
  }finally{
    btn.disabled = false;
  }
});

// esqueci a senha (placeholder)
document.getElementById('forgotLink')?.addEventListener('click', (e)=>{
  e.preventDefault();
  alert('Em breve: recuperação de senha por e-mail.');
});
</script>
</body>
</html>
