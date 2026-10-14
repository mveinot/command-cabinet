(() => {
  const themeSelect = document.querySelector('#theme-select');
  const themeStorageKey = 'command-cabinet-theme';
  const applyTheme = preference => {
    const theme = preference === 'system'
      ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
      : preference;
    document.documentElement.dataset.theme = theme;
  };
  if (themeSelect) {
    const preference = localStorage.getItem(themeStorageKey) || 'system';
    themeSelect.value = preference;
    applyTheme(preference);
    themeSelect.addEventListener('change', event => {
      localStorage.setItem(themeStorageKey, event.target.value);
      applyTheme(event.target.value);
    });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if ((localStorage.getItem(themeStorageKey) || 'system') === 'system') applyTheme('system');
    });
  }
  const searchInput = document.querySelector('#command-search');
  const searchClear = document.querySelector('.search-clear');
  if (searchInput && searchClear) {
    searchClear.style.border = '0';
    searchClear.style.padding = '0';
    searchClear.style.fontWeight = '400';
    const updateClearButton = () => { searchClear.hidden = searchInput.value.length === 0; };
    searchInput.addEventListener('input', updateClearButton);
    searchClear.addEventListener('click', () => {
      searchInput.value = '';
      updateClearButton();
      searchInput.dispatchEvent(new Event('input'));
      searchInput.focus();
    });
    updateClearButton();
  }
  const commandList = document.querySelector('#command-list');
  const environmentFilter = document.querySelector('#environment-filter');
  if (searchInput && commandList && environmentFilter) {
    let liveSearchTimer;
    let activeRequest;
    const loadResults = async () => {
      const query = searchInput.value.trim();
      if (query.length > 0 && query.length < 3) return;
      activeRequest?.abort();
      activeRequest = new AbortController();
      const params = new URLSearchParams();
      if (query) params.set('q', query);
      if (environmentFilter.value) params.set('environment', environmentFilter.value);
      commandList.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch(`/api/commands?${params}`, {signal: activeRequest.signal});
        if (!response.ok) throw new Error('Search request failed');
        const result = await response.json();
        commandList.innerHTML = result.html;
        addCardCopyControls(commandList);
        const url = params.toString() ? `/?${params}` : '/';
        history.replaceState(null, '', url);
      } catch (error) {
        if (error.name !== 'AbortError') console.error(error);
      } finally {
        commandList.removeAttribute('aria-busy');
      }
    };
    const scheduleLiveSearch = () => {
      clearTimeout(liveSearchTimer);
      const length = searchInput.value.trim().length;
      if (length === 0 || length >= 3) liveSearchTimer = setTimeout(loadResults, 250);
    };
    searchInput.addEventListener('input', scheduleLiveSearch);
    environmentFilter.addEventListener('change', scheduleLiveSearch);
  }
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  document.addEventListener('click', async event => {
    const button = event.target.closest('.favorite');
    if (!button) return;
    const data = new URLSearchParams({_token: csrf, command_id: button.dataset.commandId});
    const response = await fetch('/api/favorites', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:data});
    if (response.ok) button.textContent = button.textContent.trim() === '★' ? '☆' : '★';
  });
  const commandDetail = document.querySelector('.command-detail');
  const editLink = commandDetail?.querySelector('a[href$="/edit"]');
  const commandMatch = editLink?.getAttribute('href').match(/^\/commands\/(\d+)\/edit$/);
  if (editLink && commandMatch && document.body.dataset.role !== 'viewer') editLink.insertAdjacentHTML('afterend', ` <a class="subtle-link clone-action" href="/commands/${commandMatch[1]}/clone">Clone</a>`);
  if (commandDetail && commandMatch) {
    fetch(`/api/commands/${commandMatch[1]}/revisions`)
      .then(response => response.ok ? response.json() : Promise.reject())
      .then(result => commandDetail.querySelector('.detail-meta')?.insertAdjacentHTML('beforebegin', `${result.copy_html || ''}${result.html}`))
      .catch(() => {});
  }
  const notesEditor = document.querySelector('#notes-markdown');
  const notesPreview = document.querySelector('#notes-markdown-preview');
  if (notesEditor && notesPreview) {
    let previewTimer;
    let previewRequest;
    const renderNotesPreview = async () => {
      previewRequest?.abort();
      previewRequest = new AbortController();
      notesPreview.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch('/api/markdown-preview', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token: csrf, markdown: notesEditor.value}),signal:previewRequest.signal});
        if (!response.ok) throw new Error('Preview request failed');
        notesPreview.innerHTML = (await response.json()).html;
      } catch (error) { if (error.name !== 'AbortError') console.error(error); }
      finally { notesPreview.removeAttribute('aria-busy'); }
    };
    notesEditor.addEventListener('input', () => { clearTimeout(previewTimer); previewTimer=setTimeout(renderNotesPreview, 200); });
  }
  const template = document.querySelector('textarea[name="template"]');
  const editor = document.querySelector('#placeholder-editor');
  if (template && editor) {
    const existing = JSON.parse(editor.dataset.existing || '{}');
    const render = () => { const keys = [...new Set([...template.value.matchAll(/{{\s*([a-zA-Z][a-zA-Z0-9_.-]*)\s*}}/g)].map(m=>m[1]))]; editor.innerHTML = keys.length ? keys.map(key => { const m=existing[key]||{}; const options=Array.isArray(m.options)?m.options.join('\n'):''; const optionEditor=m.type==='select'?`<label class="select-options">Options <small>One value per line</small><textarea name="placeholders[${key}][options]" rows="4">${escapeHtml(options)}</textarea></label>`:''; return `<fieldset><legend>{{${key}}}</legend><div class="placeholder-grid"><label>Label<input name="placeholders[${key}][label]" value="${escapeHtml(m.label||key.replaceAll('_',' '))}"></label><label>Type<select name="placeholders[${key}][type]">${['text','password','hostname','path','port','multiline','select'].map(t=>`<option ${m.type===t?'selected':''}>${t}</option>`).join('')}</select></label><label>Example<input name="placeholders[${key}][example]" value="${escapeHtml(m.example||'')}"></label><label>Help text<input name="placeholders[${key}][help]" value="${escapeHtml(m.help||'')}"></label><label class="checkbox"><input type="checkbox" name="placeholders[${key}][required]" ${m.required!==false?'checked':''}> Required</label>${optionEditor}</div></fieldset>`; }).join('') : '<p>Add a <code>{{placeholder}}</code> to the command to define its temporary fill-in field.</p>'; }; const escapeHtml = value => String(value).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); template.addEventListener('input', render); editor.addEventListener('change', event => { if (!event.target.matches('select[name*="[type]"]')) return; const key=event.target.name.match(/^placeholders\[([^\]]+)\]/)?.[1]; if(key){existing[key]??={};existing[key].type=event.target.value;render();} }); render(); }
  const copy = async text => { await navigator.clipboard.writeText(text); };
  const recordCopy = async commandId => { if (!commandId) return; const response = await fetch(`/api/commands/${commandId}/copied`, {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token: csrf})}); if (!response.ok) return; const result=await response.json(); if (!commandDetail) return; const existing=commandDetail.querySelector('.copy-status'); if(existing) existing.outerHTML=result.copy_html; else commandDetail.querySelector('.detail-meta')?.insertAdjacentHTML('beforebegin',result.copy_html); };
  function addCardCopyControls(root) { root.querySelectorAll('.command-card').forEach(card => { const code=card.querySelector('pre code'); if (!code || /{{\s*[a-zA-Z][a-zA-Z0-9_.-]*\s*}}/.test(code.textContent) || card.querySelector('.card-copy')) return; const button=document.createElement('button'); button.type='button'; button.className='card-copy'; button.dataset.copyCardCommand=''; button.setAttribute('aria-label','Copy command'); button.title='Copy command'; button.textContent='⧉'; card.querySelector('pre')?.insertAdjacentElement('afterend',button); }); }
  addCardCopyControls(document);
  document.addEventListener('click', async event => { const button=event.target.closest('[data-copy-card-command]'); if (!button) return; const card=button.closest('.command-card'); const code=card?.querySelector('pre code'); if (!code) return; try { await copy(code.textContent); const id=card.querySelector('h2 a')?.getAttribute('href')?.match(/^\/commands\/(\d+)$/)?.[1]; recordCopy(id).catch(()=>{}); button.textContent='✓'; setTimeout(()=>button.textContent='⧉',1200); } catch (error) { console.error(error); } });
  document.querySelector('[data-copy-template]')?.addEventListener('click', async e => { await copy(document.querySelector('#command-template code').textContent); recordCopy(commandMatch?.[1]).catch(()=>{}); e.currentTarget.textContent='Copied'; setTimeout(()=>e.currentTarget.textContent='Copy raw command',1200); });
  document.querySelector('.copy-filled')?.addEventListener('click', async e => { const panel=e.currentTarget.closest('.fill-panel'); let rendered=panel.dataset.template; let valid=true; panel.querySelectorAll('[data-placeholder]').forEach(input=>{if(input.required&&!input.value){input.focus();valid=false;return;} rendered=rendered.replaceAll(`{{${input.dataset.placeholder}}}`,input.value); rendered=rendered.replaceAll(`{{ ${input.dataset.placeholder} }}`,input.value);}); if(!valid)return; await copy(rendered);recordCopy(commandMatch?.[1]).catch(()=>{});e.currentTarget.textContent='Copied';setTimeout(()=>e.currentTarget.textContent='Copy filled command',1200); });
  document.querySelector('.clear-values')?.addEventListener('click', e=>e.currentTarget.closest('.fill-panel').querySelectorAll('input,select').forEach(i=>i.value=''));
  const passkeyMessage = message => document.querySelector('[data-passkey-message]') && (document.querySelector('[data-passkey-message]').textContent = message);
  const passkeyPost = async (url, fields) => { const response = await fetch(url, {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token:csrf,...fields})}); const result=await response.json().catch(()=>({error:'Unexpected server response.'})); if(!response.ok) throw new Error(result.error||'Passkey request failed.'); return result; };
  const fromBase64Url = value => { const padded=value.replace(/-/g,'+').replace(/_/g,'/')+'='.repeat((4-value.length%4)%4); const binary=atob(padded); return Uint8Array.from(binary,character=>character.charCodeAt(0)).buffer; };
  const toBase64Url = value => { const bytes=new Uint8Array(value); let binary=''; bytes.forEach(byte=>binary+=String.fromCharCode(byte)); return btoa(binary).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,''); };
  const preparePasskeyOptions = options => { const prepared={...options,challenge:fromBase64Url(options.challenge)}; if(options.user) prepared.user={...options.user,id:fromBase64Url(options.user.id)}; for(const key of ['excludeCredentials','allowCredentials']) if(options[key]) prepared[key]=options[key].map(credential=>({...credential,id:fromBase64Url(credential.id)})); return prepared; };
  const serialisePasskey = credential => { const response=credential.response; const data={clientDataJSON:toBase64Url(response.clientDataJSON)}; if(response.attestationObject){data.attestationObject=toBase64Url(response.attestationObject);data.transports=response.getTransports?.()||[];} else {data.authenticatorData=toBase64Url(response.authenticatorData);data.signature=toBase64Url(response.signature);data.userHandle=response.userHandle?toBase64Url(response.userHandle):null;} return {id:credential.id,rawId:toBase64Url(credential.rawId),type:credential.type,response:data}; };
  document.querySelector('[data-passkey-register]')?.addEventListener('click', async event => { if(!window.PublicKeyCredential){passkeyMessage('Passkeys are not supported by this browser.');return;} const button=event.currentTarget;button.disabled=true;passkeyMessage('Waiting for your device…');try{const started=await passkeyPost('/api/passkeys/register/options',{});const credential=await navigator.credentials.create({publicKey:preparePasskeyOptions(started.options)});if(!credential)throw new Error('No passkey was created.');await passkeyPost('/api/passkeys/register/verify',{credential:JSON.stringify(serialisePasskey(credential)),label:document.querySelector('[data-passkey-label]')?.value||''});window.location.reload();}catch(error){passkeyMessage(error.message||'Passkey registration was cancelled.');button.disabled=false;} });
  document.querySelector('[data-passkey-login]')?.addEventListener('click', async event => { if(!window.PublicKeyCredential){passkeyMessage('Passkeys are not supported by this browser.');return;} const email=document.querySelector('#login-form input[name="email"]')?.value.trim();if(!email){passkeyMessage('Enter your email address first.');return;} const button=event.currentTarget;button.disabled=true;passkeyMessage('Waiting for your passkey…');try{const started=await passkeyPost('/api/passkeys/login/options',{email});const credential=await navigator.credentials.get({publicKey:preparePasskeyOptions(started.options)});if(!credential)throw new Error('No passkey was provided.');const result=await passkeyPost('/api/passkeys/login/verify',{credential:JSON.stringify(serialisePasskey(credential))});window.location.assign(result.redirect||'/');}catch(error){passkeyMessage(error.message||'Passkey sign-in was cancelled.');button.disabled=false;} });
})();
