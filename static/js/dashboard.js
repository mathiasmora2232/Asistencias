(function(){
  async function api(path, opts){
    const res = await fetch(path, Object.assign({ credentials: 'same-origin' }, opts || {}));
    const data = await res.json();
    if (!res.ok) throw new Error(data?.error || 'api_error');
    return data;
  }

  async function status(){ return api('../api/auth.php?action=status'); }
  async function logout(){
    try {
      await fetch('../api/auth.php?action=logout', { method: 'POST', credentials: 'same-origin' });
    } catch (e) {}
    try { localStorage.removeItem('active_user'); } catch (e) {}
  }

  function isAdminStatus(st){
    if (!st) return false;
    const v = (st.isAdmin !== undefined) ? st.isAdmin : st.role;
    if (typeof v === 'string') return v === 'admin' || v === '1' || v === 'true';
    return !!v;
  }

  // Nuevas utilidades API
  async function usersAll(){ return usersList(''); }
  async function jornadaGet(usuario_id){ return api('../api/admin.php?action=jornadas.get&usuario_id=' + encodeURIComponent(usuario_id)); }
  async function jornadaSet(payload){
    return api('../api/admin.php?action=jornadas.set', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
    });
  }
  async function statsPunctuality(params){
    const qs = new URLSearchParams(params || {}).toString();
    return api('../api/admin.php?action=stats.punctuality' + (qs ? ('&' + qs) : ''));
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
    // Pivot: columnas por acción
    const map = new Map();
    rows.forEach(r => {
      const key = r.day || r.week || r.month || '';
      if (!map.has(key)) map.set(key, {entrada:0, salida:0, almuerzo_inicio:0, almuerzo_fin:0});
      const obj = map.get(key);
      obj[r.accion] = (obj[r.accion]||0) + (r.count||0);
    });
    th.innerHTML = '<tr><th>Grupo</th><th>Entrada</th><th>Salida</th><th>Alm. inicio</th><th>Alm. fin</th><th>Total</th></tr>';
    tb.innerHTML = '';
    [...map.entries()].sort((a,b)=>String(b[0]).localeCompare(String(a[0]))).forEach(([grp,obj])=>{
      const total = (obj.entrada||0)+(obj.salida||0)+(obj.almuerzo_inicio||0)+(obj.almuerzo_fin||0);
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${grp}</td><td>${obj.entrada||0}</td><td>${obj.salida||0}</td><td>${obj.almuerzo_inicio||0}</td><td>${obj.almuerzo_fin||0}</td><td>${total}</td>`;
      tb.appendChild(tr);
    });
  }

  function showTab(id){
    const panels = [
      {id:'users', p:'panel-users', t:'tab-users'},
      {id:'asist', p:'panel-asist', t:'tab-asist'},
      {id:'stats', p:'panel-stats', t:'tab-stats'},
      {id:'jornada', p:'panel-jornada', t:'tab-jornada'},
    ];
    panels.forEach(({id:pid,p,t})=>{
      const panel = document.getElementById(p);
      const tab = document.getElementById(t);
      if (!panel || !tab) return;
      const active = (pid === id);
      panel.classList.toggle('hidden', !active);
      panel.setAttribute('aria-hidden', active ? 'false' : 'true');
      tab.classList.toggle('active', active);
    });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const st = await status();
    if (!st || !st.user || !isAdminStatus(st)){
      try { sessionStorage.setItem('notice', 'Acceso restringido. Inicia sesión como admin.'); } catch(e) {}
      location.href = './login.html';
      return;
    }

    // Autorizado: mostrar el contenido del dashboard
    try { document.body.style.display = ''; } catch(e) {}

    document.getElementById('btn-logout')?.addEventListener('click', async ()=>{ await logout(); location.href='./login.html'; });
    document.getElementById('tab-users')?.addEventListener('click', (e)=>{ e.preventDefault(); showTab('users'); });
    document.getElementById('tab-asist')?.addEventListener('click', (e)=>{ e.preventDefault(); showTab('asist'); });
    document.getElementById('tab-stats')?.addEventListener('click', (e)=>{ e.preventDefault(); showTab('stats'); });
    document.getElementById('tab-jornada')?.addEventListener('click', (e)=>{ e.preventDefault(); showTab('jornada'); });

    // Usuarios list + filtros
    let usersCache = [];
    async function refreshUsers(){
      const q = (document.getElementById('u_search')?.value || '').trim().toLowerCase();
      const roleF = (document.getElementById('u_role_f')?.value || '');
      const dFrom = (document.getElementById('u_created_from')?.value || '');
      const dTo = (document.getElementById('u_created_to')?.value || '');
      let rows = usersCache;
      if (q) rows = rows.filter(r => (r.nombre||'').toLowerCase().includes(q) || (r.usuario||'').toLowerCase().includes(q) || (r.email||'').toLowerCase().includes(q));
      if (roleF) rows = rows.filter(r => (r.role||'') === roleF);
      if (dFrom) rows = rows.filter(r => (r.created_at||'') >= dFrom);
      if (dTo) rows = rows.filter(r => (r.created_at||'') <= (dTo + ' 23:59:59'));
      renderUsers(rows);
    }
    try { usersCache = await usersList(''); } catch { usersCache = []; }
    ['u_search','u_role_f','u_created_from','u_created_to'].forEach(id => {
      document.getElementById(id)?.addEventListener('input', refreshUsers);
      document.getElementById(id)?.addEventListener('change', refreshUsers);
    });
    refreshUsers();

    // Modal crear usuario
    const mCreate = document.getElementById('modal-create-user');
    const openCU = document.getElementById('btn-open-create-user');
    const closeCU = document.getElementById('btn-close-create-user');
    openCU?.addEventListener('click', ()=>{ mCreate?.classList.add('show'); mCreate?.setAttribute('aria-hidden','false'); });
    closeCU?.addEventListener('click', ()=>{ mCreate?.classList.remove('show'); mCreate?.setAttribute('aria-hidden','true'); });

    // Crear usuario
    const f = document.getElementById('form-create-user');
    const msg = document.getElementById('u_create_msg') || document.getElementById('mc_msg');
    f?.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const payload = {
        nombre: document.getElementById('u_nombre')?.value || '',
        usuario: document.getElementById('u_usuario')?.value || '',
        email: document.getElementById('u_email')?.value || '',
        password: document.getElementById('u_password')?.value || '',
        role: document.getElementById('u_role')?.value || 'user',
      };
      try { await userCreate(payload); msg.textContent='Usuario creado'; msg.classList.remove('hidden'); try { mCreate?.classList.remove('show'); mCreate?.setAttribute('aria-hidden','true'); } catch{}; usersCache = await usersList(''); await refreshUsers(); }
      catch (err) { msg.textContent='Error al crear usuario'; msg.classList.remove('hidden'); }
    });

    // Asistencias
    // Modal selección de usuario (reutilizable)
    const mPicker = document.getElementById('modal-user-picker');
    const muList = document.getElementById('mu_list');
    const muSearch = document.getElementById('mu_search');
    let muTarget = null; // 'a' | 's' | 'j'
    let selectedUser = { a: null, s: null, j: null };
    function setUserLabel(prefix, user){
      const el = document.getElementById(prefix + '_user_label');
      el.textContent = user ? (user.nombre || user.usuario || ('ID '+user.id)) : '';
    }
    async function openPicker(target){
      muTarget = target;
      try { usersCache = usersCache.length ? usersCache : await usersList(''); } catch {}
      const list = usersCache.slice();
      function renderPick(filter){
        muList.innerHTML='';
        list.filter(u => {
          if (!filter) return true; const f = filter.toLowerCase();
          return (u.nombre||'').toLowerCase().includes(f) || (u.usuario||'').toLowerCase().includes(f) || (u.email||'').toLowerCase().includes(f);
        }).forEach(u => {
          const b = document.createElement('button'); b.type='button'; b.className='user-btn';
          b.textContent = `${u.nombre||u.usuario||('ID '+u.id)}`; b.addEventListener('click', ()=>{
            selectedUser[target] = u; setUserLabel(target, u); mPicker.classList.remove('show'); mPicker.setAttribute('aria-hidden','true');
            if (target==='j') loadJornada();
          });
          muList.appendChild(b);
        });
      }
      renderPick('');
      muSearch.value='';
      muSearch.oninput = ()=>renderPick(muSearch.value);
      mPicker.classList.add('show'); mPicker.setAttribute('aria-hidden','false');
    }
    document.getElementById('mu_close')?.addEventListener('click',()=>{ mPicker.classList.remove('show'); mPicker.setAttribute('aria-hidden','true'); });
    document.getElementById('btn-open-user-picker-a')?.addEventListener('click', ()=>openPicker('a'));
    document.getElementById('btn-open-user-picker-s')?.addEventListener('click', ()=>openPicker('s'));
    document.getElementById('btn-open-user-picker-j')?.addEventListener('click', ()=>openPicker('j'));

    document.getElementById('btn-asist-agg')?.addEventListener('click', async ()=>{
      const params = {
        usuario_id: (selectedUser.a?.id || ''),
        start_date: (document.getElementById('a_start')?.value || ''),
        end_date: (document.getElementById('a_end')?.value || ''),
        group: (document.getElementById('a_group')?.value || 'day'),
      };
      try { const rows = await asistAgg(params); renderAsistAgg(rows); } catch {}
    });
    document.getElementById('btn-asist-list')?.addEventListener('click', async ()=>{
      const params = {
        usuario_id: (selectedUser.a?.id || ''),
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

    // Estadísticas
    async function loadStats(){
      const uid = selectedUser.s?.id; if (!uid) { alert('Selecciona un usuario'); return; }
      const params = {
        usuario_id: uid,
        group: (document.getElementById('s_group')?.value || 'day'),
        start_date: (document.getElementById('s_start')?.value || ''),
        end_date: (document.getElementById('s_end')?.value || ''),
      };
      try {
        const rows = await statsPunctuality(params);
        const tb = document.getElementById('s_table'); tb.innerHTML = '';
        rows.forEach(r=>{
          const tr = document.createElement('tr');
          tr.innerHTML = `<td>${r.group}</td><td>${r.on_time}</td><td>${r.late}</td><td>${r.early}</td><td>${r.early_exit}</td><td>${r.overtime}</td><td>${r.avg_entry_diff_min ?? '-'}</td><td>${r.avg_exit_diff_min ?? '-'}</td>`;
          tb.appendChild(tr);
        });
      } catch (e) { alert('No se pudieron calcular estadísticas. Asegura una jornada definida.'); }
    }
    document.getElementById('btn-stats-load')?.addEventListener('click', loadStats);

    // Jornada
    async function loadJornada(){
      const uid = selectedUser.j?.id; if (!uid) return;
      try { const j = await jornadaGet(uid); document.getElementById('j_he').value = (j?.hora_entrada || '09:00:00'); document.getElementById('j_hs').value = (j?.hora_salida || '18:00:00'); document.getElementById('j_ai').value = (j?.almuerzo_inicio || ''); document.getElementById('j_af').value = (j?.almuerzo_fin || ''); document.getElementById('j_tol').value = (j?.tolerancia_min ?? 5); document.getElementById('j_hex').value = (j?.horas_extra_inicio || ''); }
      catch {}
    }
    document.getElementById('form-jornada')?.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const uid = selectedUser.j?.id; if (!uid) { alert('Selecciona un usuario'); return; }
      const payload = {
        usuario_id: uid,
        hora_entrada: document.getElementById('j_he')?.value || '09:00:00',
        hora_salida: document.getElementById('j_hs')?.value || '18:00:00',
        almuerzo_inicio: document.getElementById('j_ai')?.value || null,
        almuerzo_fin: document.getElementById('j_af')?.value || null,
        tolerancia_min: parseInt(document.getElementById('j_tol')?.value || '5', 10) || 0,
        horas_extra_inicio: document.getElementById('j_hex')?.value || null,
      };
      const msg = document.getElementById('j_msg');
      try { await jornadaSet(payload); msg.textContent = 'Jornada guardada'; msg.classList.remove('hidden'); }
      catch { msg.textContent = 'Error al guardar'; msg.classList.remove('hidden'); }
    });
  });
})();
