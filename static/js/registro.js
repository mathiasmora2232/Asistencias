(function(){
  async function api(path, opts){
    const res = await fetch(path, Object.assign({ credentials: 'same-origin' }, opts || {}));
    let data = null; try { data = await res.json(); } catch {}
    if (!res.ok) throw new Error((data && data.error) || 'api_error');
    return data || {};
  }
  async function status(){ return api('../api/auth.php?action=status'); }
  async function logout(){ try { await api('../api/auth.php?action=logout', { method:'POST' }); } catch {} }
  async function listHoy(uid, fecha){
    const qs = new URLSearchParams({ usuario_id:String(uid), date: fecha });
    return api('../api/asistencias.php?action=list&'+qs.toString());
  }
  async function add(uid, fecha, hora, accion, observacion, motivo){
    const payload = { usuario_id:uid, fecha, hora, accion, observacion };
    if (motivo && motivo.trim() !== '') payload.motivo = motivo.trim();
    return api('../api/asistencias.php?action=add', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  }
  function fmt2(n){ return String(n).padStart(2,'0'); }
  function today(){ const d = new Date(); return d.getFullYear()+"-"+fmt2(d.getMonth()+1)+"-"+fmt2(d.getDate()); }
  function now(){ const d = new Date(); return fmt2(d.getHours())+":"+fmt2(d.getMinutes()); }

  function render(rows){
    const tb = document.getElementById('r_table'); tb.innerHTML='';
    rows.forEach(r=>{ const tr=document.createElement('tr'); tr.innerHTML = `<td>${r.hora}</td><td>${r.accion}</td><td>${r.observacion||''}</td>`; tb.appendChild(tr); });
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const st = await status();
    if (!st || !st.user){
      location.href = './login.html';
      return;
    }
    if (st.user && (st.user.role === 'admin' || st.isAdmin === true)){
      // Si es admin, enviarlo al dashboard
      location.href = './dashboard.html';
      return;
    }

    const uid = st.user.id;
    document.getElementById('u-info').textContent = (st.user.nombre || st.user.usuario || st.user.email || '');

    const fechaI = document.getElementById('r_fecha');
    const horaI  = document.getElementById('r_hora');
    const accionI= document.getElementById('r_accion');
    const obsI   = document.getElementById('r_obs');
    const msg    = document.getElementById('r_msg');

    fechaI.value = today();
    horaI.value  = now();

    async function refresh(){ try { const rows = await listHoy(uid, fechaI.value); render(rows); } catch {} }
    await refresh();

    document.getElementById('btn-now').addEventListener('click', ()=>{ fechaI.value=today(); horaI.value=now(); });
    document.getElementById('btn-logout').addEventListener('click', async ()=>{ await logout(); location.href='./login.html'; });

    document.getElementById('btn-marcar').addEventListener('click', async ()=>{
      msg.classList.add('hidden'); msg.textContent='';
      try {
        await add(uid, fechaI.value, horaI.value, accionI.value, obsI.value.trim());
        msg.textContent = 'Asistencia registrada'; msg.classList.remove('hidden');
        obsI.value=''; await refresh();
      } catch (err){
        const e = String(err && err.message || 'api_error');
        if (e === 'duplicate_event' || e === 'duplicate_action_day') msg.textContent = 'Ya registraste esta acción hoy.';
        else if (e === 'reason_required') {
          const r = prompt('Se requiere motivo (llegada tarde o salida temprana):');
          if (r && r.trim() !== '') {
            try { await add(uid, fechaI.value, horaI.value, accionI.value, obsI.value.trim(), r); msg.textContent='Asistencia registrada con motivo'; msg.classList.remove('hidden'); obsI.value=''; await refresh(); return; }
            catch (e2) { /* caer al mensaje genérico */ }
          }
          msg.textContent = 'Motivo requerido no proporcionado.';
        }
        else if (e === 'invalid_user') msg.textContent = 'Usuario no válido.';
        else msg.textContent = 'Error al registrar.';
        msg.classList.remove('hidden');
      }
    });
  });
})();
