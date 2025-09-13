(function(){
  const root = document.getElementById('wcof-order-list');
  if(!root || !window.WCOF_ORD) return;

  // SOUND (default on, 5 beeps)
  const soundBtn = document.getElementById('wcof-sound');
  let audioCtx = null;
  function ensureAudio(){ try{ if(!audioCtx) audioCtx = new (window.AudioContext||window.webkitAudioContext)(); if(audioCtx.state === 'suspended') audioCtx.resume(); }catch(e){} }
  function singleBeep(at){ try{ ensureAudio(); if(!audioCtx) return; const o=audioCtx.createOscillator(), g=audioCtx.createGain(), t=audioCtx.currentTime+(at||0); o.type='sine'; o.frequency.setValueAtTime(1000,t); g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.25,t+0.01); g.gain.exponentialRampToValueAtTime(0.0001,t+0.25); o.connect(g); g.connect(audioCtx.destination); o.start(t); o.stop(t+0.27);}catch(e){} }
  function ring(){ for(let i=0;i<5;i++) singleBeep(i*0.32); }
  ensureAudio(); setTimeout(()=>{ if(audioCtx && audioCtx.state!=='running' && soundBtn){ soundBtn.style.display='block'; } },600);
  document.addEventListener('click', ()=>{ ensureAudio(); if(soundBtn && audioCtx && audioCtx.state==='running') soundBtn.style.display='none'; }, {once:true});
  soundBtn && soundBtn.addEventListener('click', ()=>{ ensureAudio(); ring(); soundBtn.style.display='none'; });

  function htmlEscape(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }
  function decodeHtml(s){ if(!s) return s; const t=document.createElement('textarea'); t.innerHTML=s; return t.value.replace(/&amp;/g,'&'); }
  function statusBar(st){
    return st==='wc-on-hold'
      ? 'st-await'
      : (st==='wc-processing'
          ? 'st-proc'
          : (st==='wc-out-for-delivery'
              ? 'st-out'
              : (st==='wc-completed'?'st-comp':'st-rej')));
  }
  function statusName(st){
    return st==='wc-on-hold'
      ? 'Waiting for approval'
      : (st==='wc-processing'
          ? 'Preparing'
          : (st==='wc-out-for-delivery'
              ? 'Delivering'
              : (st==='wc-completed'
                  ? 'Completed'
                  : (st==='wc-cancelled'?'Canceled':st))));
  }
  function actionButtons(o){
    if(o.status==='wc-on-hold'){
      return `<input type="number" min="0" step="1" placeholder="ETA min" class="wcof-eta">
              <button class="btn btn-approve" data-action="approve" data-url="${htmlEscape(o.approve_url||'')}">Approva</button>
              <button class="btn btn-reject" data-action="reject" data-url="${htmlEscape(o.reject_url||'')}">Rifiuta</button>`;
    } else if(o.status==='wc-processing'){
      return `<input type="number" min="0" step="1" placeholder="ETA min" value="${htmlEscape(o.eta||'')}" class="wcof-eta">
              <button class="btn btn-approve" data-action="approve" data-url="${htmlEscape(o.set_eta_url||'')}">Aggiorna ETA</button>
              <a class="btn btn-out" data-action="out" data-complete-url="${htmlEscape(o.complete_url||'')}" href="${htmlEscape(o.out_url||'')}">In Consegna</a>`;
    } else if(o.status==='wc-out-for-delivery'){
      return `<a class="btn btn-complete" data-action="complete" href="${htmlEscape(o.complete_url||'')}">Complete</a>`;
    } else if(o.status==='wc-completed'){
      return `<button class="btn btn-toggle" data-action="toggle">Dettagli</button>`;
    }
    return `<em style="color:#94a3b8">—</em>`;
  }
  function cardHTML(o){
    const items = Array.isArray(o.items)?o.items:[];
    const arrival = o.arrival ? `<span class="wcof-arrival">${htmlEscape(o.arrival)}</span>` : '—';
    const collapsed = o.status==='wc-completed';
    const address = htmlEscape(o.address||'');
    const typed = htmlEscape(o.address_typed||'');
    const phone = htmlEscape(o.phone||'');
    const note = htmlEscape(o.note||'');
    const payment = htmlEscape(o.payment||'');
    const meta = o.meta && typeof o.meta === 'object' ? Object.assign({}, o.meta) : {};
    const tip = meta._wcof_tip; if(tip!==undefined){ delete meta._wcof_tip; }
    const sched = meta._wcof_scheduled_time; if(sched!==undefined){ delete meta._wcof_scheduled_time; }
    const metaHtml = Object.keys(meta).map(k=>`<div><strong>${htmlEscape(k)}:</strong> ${htmlEscape(meta[k])}</div>`).join('');
    return `<div class="wcof-card wcof-new" data-id="${htmlEscape(o.id||'')}" data-status="${htmlEscape(o.status||'')}">
      <div class="wcof-head" style="display:grid;grid-template-columns:8px 1fr auto auto auto;gap:14px;align-items:center;padding:16px">
        <div class="wcof-left ${statusBar(o.status||'wc-on-hold')}" style="grid-row:1 / span 3"></div>
        <div class="wcof-meta">
          <p class="wcof-title">#${htmlEscape(o.number||o.id||'')} <span class="wcof-badge">${htmlEscape(o.status_name || statusName(o.status)||'')}</span></p>
          <p style="color:#475569">${htmlEscape(o.customer||'')}</p>
        </div>
        <div class="wcof-total"><strong>${htmlEscape(o.total||'')}</strong></div>
        <div class="wcof-arrival-wrap">${arrival}</div>
        <div class="wcof-items" style="grid-column:2 / 6;padding:12px 16px;background:#f9fafb;border-top:1px dashed #e5e7eb;${collapsed?'display:none;':''}">
          ${items.map(it=>`<div class="wcof-item"><span>${htmlEscape(it.name)}</span> <strong>× ${it.qty|0}</strong></div>`).join('')}
          <div class="wcof-info">
            ${typed ? `<div><strong>Indirizzo digitato:</strong> ${typed}${address?`<div class=\"wcof-address-extra\">(${address})</div>`:''}${(o.address_typed||o.coords)?`<div class=\"wcof-map-buttons\">${o.address_typed?`<a class=\"btn btn-map\" target=\"_blank\" href=\"https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(o.address_typed)}\">Mappa indirizzo</a>`:''}${o.coords?` <a class=\"btn btn-map\" target=\"_blank\" href=\"https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(o.coords)}\">Mappa coord</a>`:''}</div>`:''}</div>` : (address?`<div><strong>Indirizzo mappa:</strong> ${address}${o.coords?`<div class=\"wcof-map-buttons\"><a class=\"btn btn-map\" target=\"_blank\" href=\"https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(o.coords)}\">Mappa</a></div>`:''}</div>`:'')}

            <div><strong>Telefono:</strong> ${phone} ${o.phone?`<a class=\"btn btn-phone\" href=\"tel:${encodeURIComponent(o.phone)}\" target=\"_blank\">\u260E\ufe0f</a>`:''}</div>
            ${payment?`<div><strong>Payment:</strong> ${payment}</div>`:''}
            ${tip?`<div><strong>Tip:</strong> ${htmlEscape(tip)}</div>`:''}
            ${sched?`<div><strong>Scheduled time:</strong> ${htmlEscape(sched)}</div>`:''}
            <div><strong>Note:</strong> ${note || '—'}</div>
            ${metaHtml}
          </div>
        </div>
        <div class="wcof-actions" style="grid-column:2 / 6">${actionButtons(o)}</div>
      </div>
    </div>`;
  }
  function orderValue(card){
    const st = card.getAttribute('data-status') || '';
    const id = parseInt(card.getAttribute('data-id')||'0',10);
    let g = 99;
    if(st==='wc-on-hold') g=0;
    else if(st==='wc-processing') g=1;
    else if(st==='wc-out-for-delivery') g=2;
    else if(st==='wc-completed') g=3;
    return {g,id};
  }
  function resort(){
    const cards = Array.from(root.querySelectorAll('.wcof-card'));
    cards.sort((a,b)=>{
      const oa=orderValue(a), ob=orderValue(b);
      if(oa.g!==ob.g) return oa.g-ob.g;
      return oa.g===0 ? ob.id-oa.id : oa.id-ob.id;
    });
    cards.forEach(c=>root.appendChild(c));
  }
  function safePrependCard(o){
    try{
      const html = cardHTML(o);
      const tmp = document.createElement('div'); tmp.innerHTML = html.trim();
      const el = tmp.firstElementChild; if(!el) return;
      const id = el.getAttribute('data-id');
      if(id && root.querySelector(`.wcof-card[data-id="${CSS.escape(id)}"]`)) return;
      root.appendChild(el);
      resort();
      setTimeout(()=>el.classList.remove('wcof-new'), 5000);
    }catch(e){ console.warn('WCOF render error', e); }
  }

  let lastId = parseInt(WCOF_ORD.last_id || 0, 10);
  function schedule(next){ setTimeout(poll, next||3500); }
  function poll(){
    const url = WCOF_ORD.rest + '/orders?after_id=' + encodeURIComponent(lastId) + '&_=' + Date.now();
    fetch(url, { credentials:'include', headers:{'X-WP-Nonce': WCOF_ORD.nonce, 'Cache-Control':'no-cache'}, cache:'no-store' })
      .then(r=>r.json()).then(res=>{
        if(!res) return schedule(5005);
        const list = Array.isArray(res.orders) ? res.orders : [];
        const latest = parseInt(res.latest_id||0,10); if(latest > lastId) lastId = latest;
        if(list.length){ list.sort((a,b)=>a.id-b.id); list.forEach(safePrependCard); ring(); }
        schedule(3500);
      }).catch(()=>schedule(6000));
  }
  schedule(1500);

  root.addEventListener('click', function(e){
    const t = e.target;
    if(t.dataset && t.dataset.action==='approve'){
      e.preventDefault();
      const card = t.closest('.wcof-card'), eta = card.querySelector('.wcof-eta')?.value || 0;
      const form=document.createElement('form'); form.method='POST';
      form.action=(t.getAttribute('data-url')||t.dataset.url||'').replace(/&amp;/g,'&');
      const i=document.createElement('input'); i.type='hidden'; i.name='eta'; i.value=eta; form.appendChild(i);
      document.body.appendChild(form); form.submit();
    }
    if(t.dataset && t.dataset.action==='reject'){
      e.preventDefault(); if(!confirm('Confermi di rifiutare?')) return;
      window.location.href = (t.getAttribute('data-url')||t.dataset.url||'').replace(/&amp;/g,'&');
    }
    if(t.dataset && t.dataset.action==='out'){
      e.preventDefault();
      if(!confirm('Segnare come "In consegna"?')) return;
      const url = (t.getAttribute('href')||'').replace(/&amp;/g,'&');
      fetch(url, {credentials:'include'}).then(()=>{
        const card = t.closest('.wcof-card');
        if(card){
          card.setAttribute('data-status','wc-out-for-delivery');
          const left = card.querySelector('.wcof-left');
          if(left){ left.classList.remove('st-proc'); left.classList.add('st-out'); }
          const badge = card.querySelector('.wcof-badge');
          if(badge) badge.textContent = statusName('wc-out-for-delivery');
          t.textContent = 'Complete';
          t.classList.remove('btn-out');
          t.classList.add('btn-complete');
          t.dataset.action = 'complete';
          const cu = t.dataset.completeUrl || '';
          t.setAttribute('href', cu.replace(/&amp;/g,'&'));
          resort();
        }
      }).catch(()=>{ window.location.href = url; });
    }
    if(t.dataset && t.dataset.action==='complete'){
      e.preventDefault();
      if(!confirm('Segnare come "Completato"?')) return;
      const url = (t.getAttribute('href')||'').replace(/&amp;/g,'&');
      fetch(url, {credentials:'include'}).then(()=>{
        const card = t.closest('.wcof-card');
        if(card){
          card.setAttribute('data-status','wc-completed');
          const left = card.querySelector('.wcof-left');
          if(left){ left.classList.remove('st-out'); left.classList.add('st-comp'); }
          const badge = card.querySelector('.wcof-badge');
          if(badge) badge.textContent = statusName('wc-completed');
          const items = card.querySelector('.wcof-items');
          if(items) items.style.display = 'none';
          t.textContent = 'Dettagli';
          t.classList.remove('btn-complete');
          t.classList.add('btn-toggle');
          t.dataset.action = 'toggle';
          t.removeAttribute('href');
          resort();
        }
      }).catch(()=>{ window.location.href = url; });
    }
    if(t.dataset && t.dataset.action==='toggle'){
      const card = t.closest('.wcof-card');
      const items = card?.querySelector('.wcof-items');
      if(items){ items.style.display = items.style.display==='none'?'block':'none'; }
    }
  });
})();