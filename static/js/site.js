(function(){
  const LS_KEY = 'active_user';
  let USERS = ['Klever','Luis','Raquel'];

  function getActiveUser(){
    try { return localStorage.getItem(LS_KEY) || null; } catch { return null; }
  }
  function setActiveUser(name){
    try { localStorage.setItem(LS_KEY, name); } catch {}
    const ev = new CustomEvent('user:selected', { detail: { name } });
    document.dispatchEvent(ev);
  }

  function showModal(){
    const m = document.getElementById('user-modal');
    if (!m) return;
    m.classList.add('show');
    m.setAttribute('aria-hidden','false');
  }
  function hideModal(){
    const m = document.getElementById('user-modal');
    if (!m) return;
    m.classList.remove('show');
    m.setAttribute('aria-hidden','true');
  }

  async function loadUsersFromApi(){
    try {
      const res = await fetch('api/users.php?action=list', { cache: 'no-store' });
      if (res.ok) {
        const list = await res.json();
        if (Array.isArray(list) && list.length) {
          USERS = list.map(u => u.nombre || u.usuario).filter(Boolean);
        }
      }
    } catch {}
  }

  function disableForm(disabled){
    const right = document.querySelector('.right-section');
    const controls = right ? right.querySelectorAll('input, select, button[type="submit"]') : [];
    controls.forEach(el => { el.disabled = !!disabled; });
    if (right) {
      right.classList.toggle('form-disabled', !!disabled);
    }
  }

  function populateModal(){
    const m = document.getElementById('user-modal');
    if (!m) return;
    const list = m.querySelector('.user-list');
    if (!list) return;
    list.innerHTML = '';
    USERS.forEach(name => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'user-btn';
      b.setAttribute('data-user', String(name));
      b.textContent = String(name);
      list.appendChild(b);
    });
  }

  function wireModal(){
    const m = document.getElementById('user-modal');
    if (!m) return;
    // No permitir cerrar clickeando el fondo: fuerza a elegir usuario
    m.querySelectorAll('.user-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        const name = btn.getAttribute('data-user');
        if (!name) return;
        setActiveUser(name);
        // Prefill del formulario
        const nameInput = document.getElementById('nombre');
        if (nameInput) {
          nameInput.value = name;
        }
        disableForm(false);
        hideModal();
      });
    });
  }

  async function tryPhpSession(){
    // Intentar usar la API PHP si existe para validar sesión
    try {
      const res = await fetch('api/auth.php?action=status', { cache: 'no-store', credentials: 'same-origin' });
      if (res.ok) {
        const data = await res.json();
        const u = data && data.user && (data.user.usuario || data.user.nombre);
        if (u) {
          setActiveUser(String(u));
          return true;
        }
      }
    } catch {}
    return false;
  }

  document.addEventListener('DOMContentLoaded', async () => {
    await loadUsersFromApi();
    populateModal();
    wireModal();

    let user = getActiveUser();
    if (!user) {
      const hasPhp = await tryPhpSession();
      user = getActiveUser();
      if (!hasPhp || !user) {
        disableForm(true);
        showModal();
      }
    }

    // Si tenemos usuario, prefijar el campo nombre y permitir editar si desea
    const nameInput = document.getElementById('nombre');
    if (nameInput && user) {
      nameInput.value = user;
    }

    // Enviar el formulario a la API PHP
    const form = document.querySelector('form');
    const statusMsg = document.getElementById('status-msg');
    if (form) {
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const nombre = (document.getElementById('nombre')?.value || '').trim();
        const accion = document.getElementById('accion')?.value || 'entrada';
        const hora = document.getElementById('hora')?.value || '';
        if (!nombre || !hora) {
          if (statusMsg) { statusMsg.textContent = 'Completa nombre y hora.'; statusMsg.classList.remove('hidden'); }
          return;
        }
        try {
          const res = await fetch('api/asistencias.php?action=add', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nombre, accion, hora })
          });
          const data = await res.json();
          if (res.ok && data?.ok) {
            if (statusMsg) { statusMsg.textContent = '¡Registro guardado exitosamente!'; statusMsg.classList.remove('hidden'); }
          } else {
            const msg = data?.error === 'duplicate_event' ? 'Evento duplicado (ya registrado).' : (data?.error || 'Error al guardar');
            if (statusMsg) { statusMsg.textContent = msg; statusMsg.classList.remove('hidden'); }
          }
        } catch (err) {
          if (statusMsg) { statusMsg.textContent = 'Error de red o servidor.'; statusMsg.classList.remove('hidden'); }
        }
      });
    }
  });
})();
