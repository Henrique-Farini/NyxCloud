(function () {
  'use strict';

  const form = document.getElementById('loginForm');
  if (!form) return;

  const password = document.getElementById('senha');
  const toggle = document.getElementById('togglePassword');
  const button = document.getElementById('submitButton');
  const message = document.getElementById('loginMessage');
  const emailGroup = document.getElementById('email').closest('.field-group');
  const passwordGroup = password.closest('.field-group');
  const forgot = document.getElementById('forgotPassword');
  const cleanLoginRoute = /\/login\/?$/i.test(window.location.pathname);
  const apiBase = cleanLoginRoute ? '../api/' : 'api/';

  function safeNextUrl() {
    const value = new URLSearchParams(window.location.search).get('next');
    if (!value) return '';

    try {
      const target = new URL(value, window.location.origin);
      if (target.origin !== window.location.origin) return '';
      return target.pathname + target.search + target.hash;
    } catch (error) {
      return '';
    }
  }

  const params = new URLSearchParams(window.location.search);
  if (params.get('logout') === '1') {
    localStorage.removeItem('access_token');
    sessionStorage.removeItem('access_token');
    params.delete('logout');
    const cleanQuery = params.toString();
    const cleanUrl = window.location.pathname + (cleanQuery ? `?${cleanQuery}` : '');
    window.history.replaceState({}, '', cleanUrl);
  }

  async function resumeSession() {
    const next = safeNextUrl();
    if (!next) return;

    try {
      const response = await fetch(`${apiBase}me.php`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin'
      });
      if (response.ok) window.location.replace(next);
    } catch (error) {
      // Login manual continua disponivel quando a verificacao nao responde.
    }
  }

  resumeSession();

  forgot?.addEventListener('click', async function (event) {
    event.preventDefault();
    const email = document.getElementById('email').value.trim();
    if (!email) { message.textContent = 'Informe seu e-mail para receber o link.'; return; }
    try {
      const response = await fetch(`${apiBase}forgot-password.php`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ email }) });
      const data = await response.json();
      message.textContent = data.message || 'Verifique seu e-mail.';
      message.classList.add('is-info');
    } catch (error) { message.textContent = 'Nao foi possivel solicitar redefinicao agora.'; }
  });

  toggle.addEventListener('click', function () {
    const visible = password.type === 'text';
    password.type = visible ? 'password' : 'text';
    toggle.classList.toggle('is-visible', !visible);
    toggle.setAttribute('aria-pressed', String(!visible));
    toggle.setAttribute('aria-label', visible ? 'Mostrar senha' : 'Ocultar senha');
  });

  function validate() {
    const email = document.getElementById('email');
    const emailError = document.getElementById('emailError');
    const passwordError = document.getElementById('passwordError');
    let valid = true;

    emailGroup.classList.remove('has-error');
    passwordGroup.classList.remove('has-error');

    if (!email.value.trim() || !email.validity.valid) {
      emailError.textContent = 'Informe um e-mail valido.';
      emailGroup.classList.add('has-error');
      valid = false;
    }

    if (password.value.length < 1) {
      passwordError.textContent = 'Informe sua senha.';
      passwordGroup.classList.add('has-error');
      valid = false;
    }

    return valid;
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    message.textContent = '';
    message.classList.remove('is-info');
    if (!validate()) return;

    button.disabled = true;
    button.classList.add('is-loading');

    try {
      const response = await fetch(form.action, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        body: new FormData(form)
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.message || 'Nao foi possivel entrar agora.');
      }

      localStorage.removeItem('access_token');
      sessionStorage.removeItem('access_token');

      window.location.replace(safeNextUrl() || (cleanLoginRoute ? '../painel/' : 'painel.php'));
    } catch (error) {
      message.textContent = error.message;
    } finally {
      button.disabled = false;
      button.classList.remove('is-loading');
    }
  });

  form.querySelectorAll('input').forEach(function (input) {
    input.addEventListener('input', function () {
      input.closest('.field-group')?.classList.remove('has-error');
      message.textContent = '';
      message.classList.remove('is-info');
    });
  });

  form.querySelectorAll('[data-provider]').forEach(function (socialButton) {
    socialButton.addEventListener('click', function () {
      message.textContent = 'Login com ' + socialButton.dataset.provider + ' sera habilitado em breve.';
      message.classList.add('is-info');
    });
  });
}());
