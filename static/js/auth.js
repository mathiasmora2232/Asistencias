(function(){
  async function checkStatus(){
    try {
      const res = await fetch('../api/auth.php?action=status', { cache: 'no-store', credentials: 'same-origin' });
      if (!res.ok) return null;
      return await res.json();
    } catch { return null; }
  }

  async function login(identifier, password){
    const res = await fetch('../api/auth.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ email: identifier, password })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'login_failed');
    return data;
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const status = await checkStatus();
    if (status && status.user) {
      // Ya logueado: ir al dashboard
      location.href = './dashboard.html';
      return;
    }

    const form = document.getElementById('login-form');
    const msg = document.getElementById('auth-status');
    if (form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = (document.getElementById('email')?.value || '').trim();
        const pw = (document.getElementById('password')?.value || '').trim();
        if (!id || !pw) {
          if (msg) { msg.textContent = 'Completa tus credenciales'; msg.classList.remove('hidden'); }
          return;
        }
        try {
          await login(id, pw);
          location.href = './dashboard.html';
        } catch (err) {
          if (msg) { msg.textContent = 'Credenciales inválidas'; msg.classList.remove('hidden'); }
        }
      });
    }
  });
})();
