(function(){
  async function api(path, opts){
    const res = await fetch(path, Object.assign({ credentials: 'same-origin' }, opts || {}));
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'api_error');
    return data;
  }

  async function status(){ return api('../api/auth.php?action=status'); }
  async function logout(){ try { await api('../api/auth.php?action=logout'); } catch {} }

  function isAdminStatus(st){
    if (!st) return false;
    const v = (st.isAdmin !== undefined) ? st.isAdmin : st.role;
    if (typeof v === 'string') return v === 'admin' || v === '1' || v === 'true';
    return !!v;
  }

  async function usersList(q){
    const url = '../api/admin.php?action=users.list' + (q ? ('&q=' + encodeURIComponent(q)) : '');
    return api(url);
  }
  async function userCreate(payload){
    return api('../api/admin.php?action=users.create', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
    });
  }
  async function userSetRole(id, role){
    return api('../api/admin.php?action=users.setRole', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id, role })
    });
  }
  async function userDelete(id){
    return api('../api/admin.php?action=users.delete', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
    });
  }
  async function asistList(params){
    const qs = new URLSearchParams(params || {}).toString();
    return api('../api/admin.php?action=asistencias.list' + (qs ? ('&' + qs) : ''));
  }
  async function asistAgg(params){
    const qs = new URLSearchParams(params || {}).toString();
    return api('../api/admin.php?action=asistencias.aggregate' + (qs ? ('&' + qs) : ''));
  }
  async function asistAdd(payload){
    return api('../api/admin.php?action=asistencias.add', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
    });
  }
  async function asistDelete(id){
    return api('../api/admin.php?action=asistencias.delete', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
    });
  }

  function renderUsers(rows){
    const tb = document.getElementById('u_table');
    if (!tb) return;
    tb.innerHTML = '';
    rows.forEach(r => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${r.nombre||''}</td><td>${r.usuario||''}</td><td>${r.email||''}</td><td>${r.role||''}</td>`;
      const td = document.createElement('td');
      const sel = document.createElement('select'); sel.innerHTML = '<option value="user">user</option><option value="admin">admin</option>';
      sel.value = r.role || 'user';
      sel.addEventListener('change', async () => { try { await userSetRole(r.id, sel.value); } catch {} });
      const del = document.createElement('button'); del.className = 'user-btn'; del.textContent = 'Eliminar';
      del.addEventListener('click', async () => {
        if (!confirm('¿Eliminar usuario?')) return;
        try { await userDelete(r.id); tr.remove(); } catch {}
      });
      td.appendChild(sel); td.appendChild(del); tr.appendChild(td);
      tb.appendChild(tr);
    });
  }

  function renderAsistList(rows){
    const th = document.getElementById('a_thead');
    const tb = document.getElementById('a_tbody');
    if (!th || !tb) return;
    th.innerHTML = '<tr><th>Usuario</th><th>Fecha</th><th>Acción</th><th>Hora</th><th>Obs</th></tr>';
    tb.innerHTML = '';
    rows.forEach(r => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${r.nombre||r.usuario||r.usuario_id}</td><td>${r.fecha}</td><td>${r.accion}</td><td>${r.hora}</td><td>${r.observacion||''}</td>`;
      tb.appendChild(tr);
    });
  }

  function renderAsistAgg(rows){
    const th = document.getElementById('a_thead');
    const tb = document.getElementById('a_tbody');
    if (!th || !tb) return;
    th.innerHTML = '<tr><th>Grupo</th><th>Acción</th><th>Cantidad</th></tr>';
    tb.innerHTML = '';
    rows.forEach(r => {
      const grp = r.day || r.week || r.month || '';
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${grp}</td><td>${r.accion}</td><td>${r.count}</td>`;
      tb.appendChild(tr);
    });
  }

  function showTab(id){
    const pUsers = document.getElementById('panel-users');
    const pAsist = document.getElementById('panel-asist');
    const tUsers = document.getElementById('tab-users');
    const tAsist = document.getElementById('tab-asist');
    if (id === 'users'){
      pUsers.classList.remove('hidden');
      pUsers.setAttribute('aria-hidden','false');
      pAsist.classList.add('hidden');
      pAsist.setAttribute('aria-hidden','true');
      tUsers.classList.add('active');
      tAsist.classList.remove('active');
    } else {
      pUsers.classList.add('hidden');
      pUsers.setAttribute('aria-hidden','true');
      pAsist.classList.remove('hidden');
      pAsist.setAttribute('aria-hidden','false');
      tUsers.classList.remove('active');
      tAsist.classList.add('active');
    }
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const st = await status();
    if (!st || !st.user || !isAdminStatus(st)){
      alert('Acceso restringido. Inicia sesión como admin.');
      location.href = './login.html';
      return;
    }

    document.getElementById('btn-logout')?.addEventListener('click', async ()=>{ await logout(); location.href='./login.html'; });
    document.getElementById('tab-users')?.addEventListener('click', (e)=>{ e.preventDefault(); showTab('users'); });
    document.getElementById('tab-asist')?.addEventListener('click', (e)=>{ e.preventDefault(); showTab('asist'); });

    // Usuarios list
    async function refreshUsers(){
      const q = (document.getElementById('u_search')?.value || '').trim();
      try { const rows = await usersList(q); renderUsers(rows); } catch {}
    }
    document.getElementById('u_search')?.addEventListener('input', () => { refreshUsers(); });
    await refreshUsers();

    // Crear usuario
    const f = document.getElementById('form-create-user');
    const msg = document.getElementById('u_create_msg');
    f?.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const payload = {
        nombre: document.getElementById('u_nombre')?.value || '',
        usuario: document.getElementById('u_usuario')?.value || '',
        email: document.getElementById('u_email')?.value || '',
        password: document.getElementById('u_password')?.value || '',
        role: document.getElementById('u_role')?.value || 'user',
      };
      try { await userCreate(payload); msg.textContent='Usuario creado'; msg.classList.remove('hidden'); await refreshUsers(); }
      catch (err) { msg.textContent='Error al crear usuario'; msg.classList.remove('hidden'); }
    });

    // Asistencias
    document.getElementById('btn-asist-agg')?.addEventListener('click', async ()=>{
      const params = {
        usuario_id: (document.getElementById('a_uid')?.value || ''),
        type: (document.getElementById('a_type')?.value || ''),
        start_date: (document.getElementById('a_start')?.value || ''),
        end_date: (document.getElementById('a_end')?.value || ''),
        group: (document.getElementById('a_group')?.value || 'day'),
      };
      try { const rows = await asistAgg(params); renderAsistAgg(rows); } catch {}
    });
    document.getElementById('btn-asist-list')?.addEventListener('click', async ()=>{
      const params = {
        usuario_id: (document.getElementById('a_uid')?.value || ''),
        start_date: (document.getElementById('a_start')?.value || ''),
        end_date: (document.getElementById('a_end')?.value || ''),
      };
      try { const rows = await asistList(params); renderAsistList(rows); } catch {}
    });
    document.getElementById('btn-asist-add')?.addEventListener('click', async ()=>{
      const payload = {
        usuario_id: parseInt(document.getElementById('a_add_uid')?.value || 0, 10) || 0,
        fecha: (document.getElementById('a_add_fecha')?.value || ''),
        hora: (document.getElementById('a_add_hora')?.value || ''),
        accion: (document.getElementById('a_add_accion')?.value || 'entrada'),
        observacion: (document.getElementById('a_add_obs')?.value || null),
      };
      try {
        await asistAdd(payload);
        alert('Registro agregado');
      } catch (err) { alert('Error al agregar registro'); }
    });
    document.getElementById('btn-asist-del')?.addEventListener('click', async ()=>{
      const id = parseInt(document.getElementById('a_del_id')?.value || 0, 10) || 0;
      if (!id) { alert('ID inválido'); return; }
      if (!confirm('Eliminar registro de asistencia ID ' + id + '?')) return;
      try { await asistDelete(id); alert('Registro eliminado'); } catch (err) { alert('Error al eliminar'); }
    });
  });
})();
