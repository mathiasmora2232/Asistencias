(function(){
  function getDashboardPath(){
    const p = (location.pathname || '').toLowerCase();
    return p.includes('/login/') ? '../pages/dashboard.html' : './dashboard.html';
  }
  function isAdminStatus(st){
    if (!st) return false;
    const v = (st.isAdmin !== undefined) ? st.isAdmin : st.role;
    if (typeof v === 'string') return v === 'admin' || v === '1' || v === 'true';
    return !!v;
  }
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

  async function registerSimple(usuario, password, role, email){
    const res = await fetch('../api/register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ usuario, password, role, email })
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'register_failed');
    return data;
  }

  document.addEventListener('DOMContentLoaded', async () => {
    // No hacer redirección automática: mostrar estado y acciones si ya hay sesión
    const st = await checkStatus();
    if (st && st.user) {
      const msg = document.getElementById('auth-status');
      if (msg) {
        const isAdmin = isAdminStatus(st);
        msg.textContent = isAdmin ? 'Sesión activa como administrador.' : 'Sesión activa.';
        msg.classList.remove('hidden');
      }
      const formContainer = document.getElementById('login-form')?.parentElement;
      if (formContainer) {
        const actions = document.createElement('div');
        actions.style.display = 'flex'; actions.style.gap = '8px'; actions.style.marginTop = '8px';
        const goBtn = document.createElement('button'); goBtn.className = 'user-btn'; goBtn.textContent = 'Ir al panel';
        goBtn.addEventListener('click', () => { location.href = getDashboardPath(); });
        const logoutBtn = document.createElement('button'); logoutBtn.className = 'user-btn'; logoutBtn.textContent = 'Cerrar sesión';
        logoutBtn.addEventListener('click', async () => {
          try { await fetch('../api/auth.php?action=logout', { method: 'POST', credentials: 'same-origin' }); } catch {}
          location.reload();
        });
        actions.appendChild(goBtn); actions.appendChild(logoutBtn); formContainer.appendChild(actions);
      }
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
          const st2 = await checkStatus();
          if (st2 && isAdminStatus(st2)) {
            location.href = getDashboardPath();
          } else {
            // Redirigir a página de registro de asistencias para usuarios
            const p = (location.pathname || '').toLowerCase();
            const userPage = p.includes('/login/') ? '../pages/registro.html' : './registro.html';
            location.href = userPage;
          }
        } catch (err) {
          if (msg) { msg.textContent = 'Credenciales inválidas'; msg.classList.remove('hidden'); }
        }
      });
    }

    // Registro simple (temporal)
    const regBtn = document.getElementById('btn-register');
    const regMsg = document.getElementById('register-status');
    if (regBtn) {
      regBtn.addEventListener('click', async () => {
        const u = (document.getElementById('reg-usuario')?.value || '').trim();
        const e = (document.getElementById('reg-email')?.value || '').trim();
        const p = (document.getElementById('reg-password')?.value || '').trim();
        const r = (document.getElementById('reg-role')?.value || 'user');
        if (!u || !p) {
          if (regMsg) { regMsg.textContent = 'Usuario y contraseña requeridos'; regMsg.classList.remove('hidden'); }
          return;
        }
        try {
          await registerSimple(u, p, r, e);
          if (regMsg) { regMsg.textContent = 'Cuenta creada. Por favor inicia sesión manualmente.'; regMsg.classList.remove('hidden'); }
        } catch (err) {
          const error = String(err && err.message || 'register_failed');
          if (regMsg) {
            regMsg.textContent = (error === 'duplicate_user') ? 'El usuario ya existe' : 'Error al registrar';
            regMsg.classList.remove('hidden');
          }
        }
      });
    }
  });
})();
