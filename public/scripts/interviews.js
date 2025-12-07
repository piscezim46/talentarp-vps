(function(){
  // Minimal utility components implemented in JS that render Tailwind-styled HTML
  function statusKey(name){ return (name||'').toString().toLowerCase().trim(); }

  function escapeHtml(s){ if (s===null||s===undefined) return ''; return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c]; }); }

  // Build event objects for FullCalendar
  function buildEvents(list, statuses){
    return list.map(i => {
      const start = i.start || i.interview_datetime || null;
      const end = i.end || null;
      const status = (statuses && statuses[i.status_id]) ? statuses[i.status_id] : null;
      const color = i.status_color || (status ? status.color : eventColor(null));
      // choose readable text color based on background
      let textColor = getContrastColor(color);
      // Force white text for the special 'created' status for readability
      const statusNameForCheck = (i.status_name || (status ? status.name : '') || '').toString().toLowerCase();
      if (statusNameForCheck === 'created') textColor = '#ffffff';
      return {
        id: 'interview-' + i.id,
        // keep the full title for tooltips, eventContent will render time + creator
        title: (i.applicant_name || 'Applicant') + ' — ' + (i.position_name || ''),
        start: start,
        end: end,
        extendedProps: Object.assign({}, i),
        backgroundColor: color,
        borderColor: color,
        textColor: textColor,
        classNames: ['interview-event']
      };
    });
  }

  function eventColor(statusName){
    const k = statusKey(statusName);
    switch(k){
      case 'scheduled': return '#2563eb';
      case 'pending confirmation': return '#f59e0b';
      case 'completed': return '#16a34a';
      case 'cancelled': case 'no-show': return '#ef4444';
      default: return '#6b7280';
    }
  }

  // Return readable text color (black or white) depending on background hex color
  function getContrastColor(hex){
    if (!hex) return '#000000';
    try{
      const h = String(hex).replace('#','');
      const r = parseInt(h.substring(0,2),16);
      const g = parseInt(h.substring(2,4),16);
      const b = parseInt(h.substring(4,6),16);
      // luminance formula
      const luminance = (0.299*r + 0.587*g + 0.114*b) / 255;
      return luminance > 0.6 ? '#000000' : '#ffffff';
    }catch(e){ return '#000000'; }
  }

  // Unified notify helper: prefers window.Notify.push, falls back to alert
  function notify(opts){
    try{
      if (window.Notify && typeof window.Notify.push === 'function'){
        window.Notify.push(opts);
      }
      // If Notify isn't available, silently no-op so we don't use console/alert for users
    }catch(e){ /* swallow errors to avoid spamming console for users */ }
  }

  // Render table row
  function renderRow(i){
    const id = i.id;
    const when = i.start || '';
    const statusObj = (window._INTERVIEW_STATUSES && window._INTERVIEW_STATUSES[i.status_id]) ? window._INTERVIEW_STATUSES[i.status_id] : null;
    const statusName = i.status_name || (statusObj ? statusObj.name : '');
    const statusColor = i.status_color || (statusObj ? statusObj.color : '#6b7280');
    return `
      <tr data-id="${id}" class="hover:bg-white/5 cursor-pointer">
        <td class="px-3 py-2">${escapeHtml(i.applicant_name || '—')} ${i.applicant_id ? `<span class="text-xs text-gray-400">#${escapeHtml(i.applicant_id)}</span>` : ''}</td>
        <td class="px-3 py-2">${escapeHtml(i.position_name || '—')}</td>
        <td class="px-3 py-2">${escapeHtml(i.department_name || '—')}</td>
        <td class="px-3 py-2">${escapeHtml(i.team_name || '—')}</td>
        <td class="px-3 py-2">${escapeHtml(when)}</td>
        <td class="px-3 py-2"><span class="status-badge" style="background:${escapeHtml(statusColor)};">${escapeHtml(statusName)}</span></td>
      </tr>
    `;
  }

  // Populate filter selects
  function populateFilters(list, statuses){
    // Prefer server-provided authoritative lists if available
    const depEl = document.getElementById('f-department'); const teamEl = document.getElementById('f-team'); const posEl = document.getElementById('f-position'); const statusEl = document.getElementById('f-status');
    // reset before populating to avoid duplicate entries when called multiple times
    if (depEl) depEl.innerHTML = '<option value="">-- Select Department --</option>';
    if (teamEl) teamEl.innerHTML = '<option value="">-- Select Team --</option>';
    if (posEl) posEl.innerHTML = '<option value="">-- Select Position --</option>';
    if (statusEl) statusEl.innerHTML = '<option value="">-- Select Status --</option>';

    // Departments: server list may be an array of objects or strings
    if (window._DB_DEPARTMENTS && Array.isArray(window._DB_DEPARTMENTS)){
      window._DB_DEPARTMENTS.forEach(d => {
        const val = (d && (d.name||d.department_name)) ? (d.name||d.department_name) : (typeof d === 'string' ? d : null);
        if (!val) return;
        const o = document.createElement('option'); o.value = val; o.textContent = val; depEl && depEl.appendChild(o);
      });
    } else {
      // Fallback: derive from list
      const deps = new Set(); list.forEach(i => { if (i.department_name) deps.add(i.department_name); });
      deps.forEach(d => { const o = document.createElement('option'); o.value = d; o.textContent = d; depEl && depEl.appendChild(o); });
    }

    // Teams: still derived from interviews list (no server list available)
    if (teamEl){ const teams = new Set(); list.forEach(i => { if (i.team_name) teams.add(i.team_name); }); teams.forEach(t => { const o = document.createElement('option'); o.value = t; o.textContent = t; teamEl.appendChild(o); }); }

    // Positions: prefer server-provided positions that have interviews
    if (window._POSITIONS_WITH_INTERVIEWS && Array.isArray(window._POSITIONS_WITH_INTERVIEWS)){
      window._POSITIONS_WITH_INTERVIEWS.forEach(p => {
        const val = (p && (p.title||p.position_name||p.name)) ? (p.title||p.position_name||p.name) : (typeof p === 'string' ? p : null);
        if (!val) return;
        const o = document.createElement('option'); o.value = val; o.textContent = val; posEl && posEl.appendChild(o);
      });
    } else {
      const positions = new Set(); list.forEach(i => { if (i.position_name) positions.add(i.position_name); }); positions.forEach(p => { const o = document.createElement('option'); o.value = p; o.textContent = p; posEl && posEl.appendChild(o); });
    }

    // Statuses (use provided statuses map)
    if (statusEl && statuses){ Object.keys(statuses).forEach(k => { const s = statuses[k]; const o = document.createElement('option'); o.value = s.name; o.textContent = s.name; statusEl.appendChild(o); }); }

    // Created By: derive from current list (shows creators for displayed interviews)
    const createdEl = document.getElementById('f-created-by');
    if (createdEl){ createdEl.innerHTML = '<option value="">-- Select Creator --</option>'; const creators = new Set(); list.forEach(i=>{ if (i.created_by_name) creators.add(i.created_by_name); if (i.created_by) creators.add(i.created_by); }); creators.forEach(c => { const o = document.createElement('option'); o.value = c; o.textContent = c; createdEl.appendChild(o); }); }
  }

  // Drawer functions
  const drawer = document.getElementById('interviewDrawer');
  const drawerContent = document.getElementById('drawerContent');
  const drawerClose = document.getElementById('drawerClose');
  function openDrawer(){ drawer.classList.remove('translate-x-full'); }
  function closeDrawer(){ drawer.classList.add('translate-x-full'); }
  drawerClose.addEventListener('click', closeDrawer);

  function renderDrawer(interview){
    const statuses = window._INTERVIEW_STATUSES || {};
    const comments = interview.comments || '';
    // determine status color/name for display
    const stFromMap = (window._INTERVIEW_STATUSES && window._INTERVIEW_STATUSES[String(interview.status_id)]) ? window._INTERVIEW_STATUSES[String(interview.status_id)] : null;
    const statusNameForDisplay = interview.status_name || (stFromMap ? stFromMap.name : '') || '';
    const statusColorForDisplay = interview.status_color || (stFromMap ? stFromMap.color : '#6b7280');
    const statusTextColor = getContrastColor(statusColorForDisplay) || '#ffffff';
    // Determine allowed next statuses. Prefer server-provided transitions map when available.
    // transitions map shape: { fromStatusId: [toStatusId, ...], ... }
    const transitions = window._INTERVIEW_STATUS_TRANSITIONS || {};
    const allowedNext = [];
    try {
      const hasServerTransitions = Object.keys(transitions || {}).length > 0;
      const fromKey = interview.status_id || interview.status || null;
      if (hasServerTransitions && fromKey !== null) {
        // only consult server map when it exists; if a specific "from" key has no entries,
        // do not fall back to next-in-order to avoid showing illegal transitions.
        const tos = transitions[String(fromKey)] || transitions[fromKey] || [];
        tos.forEach(tid => {
          const s = (statuses && (statuses[tid] || statuses[String(tid)])) ? (statuses[tid] || statuses[String(tid)]) : null;
          if (s) allowedNext.push(s);
        });
      } else if (!hasServerTransitions) {
        // Fallback: show the next-in-order statuses after current only when no server map exists
        const statusList = Object.keys(statuses).map(k => statuses[k]).filter(Boolean).sort((a,b)=>{ try{ return (parseInt(a.id,10)||0) - (parseInt(b.id,10)||0); }catch(e){ return 0; } });
        for (let i=0;i<statusList.length;i++){ const s = statusList[i]; if (String(s.id) === String(interview.status_id)) { const next = statusList[i+1]; if (next) allowedNext.push(next); break; } }
        if (allowedNext.length === 0 && statusList.length){ const idx = statusList.findIndex(s=> (s.name||'').toString().toLowerCase() === (interview.status_name||'').toString().toLowerCase()); if (idx !== -1) { const next = statusList[idx+1]; if (next) allowedNext.push(next); } }
      }
    } catch(e) { /* ignore */ }

    // set the drawer title to include the interview id on the header line
    try { const hdr = document.getElementById('drawerTitle'); if (hdr) hdr.textContent = 'Interview #' + (interview.id || ''); } catch(e) {}

    const html = `
      <div class="space-y-3">
        <div>
          <div class="mb-2">
            <span class="status-badge px-2 py-1 rounded text-xs" style="background: ${escapeHtml(statusColorForDisplay)}; color: ${escapeHtml(statusTextColor)};">${escapeHtml(statusNameForDisplay)}</span>
          </div>
          <div class="text-sm text-gray-400">Applicant</div>
          <div class="font-semibold text-white">${escapeHtml(interview.applicant_name || '—')} <a href="#" class="open-app-link" data-applicant-id="${encodeURIComponent(interview.applicant_id || interview.applicant || '')}" title="Open applicant" style="margin-left:8px;color:#9ca3af;text-decoration:none;">🔗</a></div>
          <div class="text-xs text-gray-400 mt-1">${(function(){ try{ var parts = []; if (interview.applicant_email) parts.push('<a href="mailto:' + encodeURIComponent(interview.applicant_email) + '" style="color:#9ca3af;text-decoration:none;">' + escapeHtml(interview.applicant_email) + '</a>'); if (interview.applicant_phone) { var ph = (interview.applicant_phone||'').toString().trim(); var phHref = ph.replace(/[^+0-9]/g,''); parts.push('<a href="tel:' + encodeURIComponent(phHref) + '" style="color:#9ca3af;text-decoration:none;margin-left:8px;">' + escapeHtml(interview.applicant_phone) + '</a>'); } return parts.join(' ');}catch(e){ return ''; } })()}</div>
        </div>

        <div>
          <div class="text-sm text-gray-400">Position</div>
          <div class="text-white">${escapeHtml(interview.position_name || '—')} <a href="#" class="open-position-link" data-position-id="${encodeURIComponent(interview.position_id || interview.position || '')}" data-position-title="${encodeURIComponent(interview.position_name || '')}" title="Open position" style="margin-left:8px;color:#9ca3af;text-decoration:none;">🔗</a></div>
          <div class="text-xs text-gray-400">${escapeHtml(interview.department_name||'')} • ${escapeHtml(interview.team_name||'')}</div>
        </div>

        <div>
          <label class="block text-sm text-gray-300 mb-1">Scheduled</label>
          <div class="flex gap-2 items-center">
            <input id="drawerDateTime" type="datetime-local" class="w-full bg-gray-800 text-white rounded px-3 py-2" value="${(function(){ try{ if(!interview.start) return ''; const d = new Date(interview.start); if (isNaN(d.getTime())) return ''; const pad = n => (n<10? '0'+n : n); const yyyy = d.getFullYear(); const mm = pad(d.getMonth()+1); const dd = pad(d.getDate()); const hh = pad(d.getHours()); const min = pad(d.getMinutes()); return `${yyyy}-${mm}-${dd}T${hh}:${min}`;}catch(e){return '';}})() }" />
            <button id="drawerUpdateScheduleBtn" class="bg-indigo-600 px-3 py-2 rounded text-white">Save</button>
          </div>
        </div>

        <div>
          <label class="block text-sm text-gray-300 mb-1">Comments</label>
          <textarea id="drawerComments" class="w-full bg-gray-800 text-white rounded px-3 py-2" rows="6">${escapeHtml(comments)}</textarea>
        </div>

        <div class="flex gap-2">
          ${ allowedNext.map(function(s){
              const btnTextColor = getContrastColor(s.color || '#2563eb');
              return '<button class="drawerNextStatusBtn rounded font-semibold" data-next-id="' + escapeHtml(s.id) + '" style="background:' + escapeHtml(s.color || '#2563eb') + '; color:' + escapeHtml(btnTextColor) + '; padding:8px;">' + escapeHtml(s.name) + '</button>';
            }).join('') }
        </div>
      </div>
    `;
    drawerContent.innerHTML = html;
    // Attach handlers for the new open-app/open-position links so they open in-app when possible
    try {
      const appLinks = drawerContent.querySelectorAll('.open-app-link');
      appLinks.forEach(function(el){ el.addEventListener('click', function(ev){ ev.preventDefault(); try{ const aid = decodeURIComponent(el.getAttribute('data-applicant-id')||''); if (!aid) return; if (window.location && String(window.location.pathname || '').indexOf('applicants.php') !== -1 && typeof openApplicant === 'function') { try { openApplicant(aid); } catch(e) { window.location.href = 'applicants.php?openApplicant=' + encodeURIComponent(aid); } } else { window.location.href = 'applicants.php?openApplicant=' + encodeURIComponent(aid); } }catch(e){ console.warn('open applicant handler failed', e); } }); });
    } catch(e){}
    try {
      const posLinks = drawerContent.querySelectorAll('.open-position-link');
      posLinks.forEach(function(el){ el.addEventListener('click', function(ev){ ev.preventDefault(); try{ const titleRaw = decodeURIComponent(el.getAttribute('data-position-title')||''); const titleVal = (titleRaw || '').toString().toLowerCase().trim(); if (!titleVal) return; // redirect to view_positions with title filter so the card appears
        const target = 'view_positions.php?fTitle=' + encodeURIComponent(titleVal);
        window.location.href = target;
        }catch(e){ console.warn('open position handler failed', e); } }); });
    } catch(e){}
    // attach handlers for all next-status buttons (may be multiple)
    const nextBtns = drawerContent.querySelectorAll('.drawerNextStatusBtn');
    nextBtns.forEach(btn => {
      // shrink buttons by 10% and add a hover-grow effect
      try{ btn.style.transform = 'scale(0.9)'; btn.style.transition = 'transform 0.12s ease'; }catch(e){}
      btn.addEventListener('mouseenter', function(){ try{ this.style.transform = 'scale(1)'; }catch(e){} });
      btn.addEventListener('mouseleave', function(){ try{ this.style.transform = 'scale(0.9)'; }catch(e){} });

      btn.addEventListener('click', async function(){
        const nextId = this.getAttribute('data-next-id');
        const commentsEl = document.getElementById('drawerComments');
        const commentsVal = commentsEl ? (commentsEl.value || '').toString().trim() : '';

        // require comment when transitioning to Cancelled (or similar). Use status name lookup.
        const nextStatusObj = (window._INTERVIEW_STATUSES && (window._INTERVIEW_STATUSES[String(nextId)] || window._INTERVIEW_STATUSES[nextId])) || null;
        const nextName = nextStatusObj && nextStatusObj.name ? (''+nextStatusObj.name).toString().toLowerCase() : '';
        if (nextName.indexOf('cancel') !== -1 && (!commentsVal || commentsVal.length < 3)) {
          notify({ from: 'Interviews', message: 'Please provide comments when cancelling', color: '#f59e0b' });
          return;
        }

        // disable all next buttons while request is in-flight and show progress
        try{ nextBtns.forEach(n => { n.disabled = true; }); }catch(e){}
        const origText = this.textContent;
        try{
          this.textContent = 'Working...';
          const fd = new FormData(); fd.append('id', interview.id); fd.append('status_id', nextId); if (commentsEl) fd.append('comments', commentsVal);
          const res = await fetch('update_interview.php', { method:'POST', body: fd, credentials: 'same-origin' });
          const text = await res.text(); let json = {};
          try{ json = JSON.parse(text); }catch(e){ console.warn('update response not json', text); }
          if (json && (json.ok || json.success) && json.interview) {
            // Update the interview object with authoritative server response
            try{
              const updated = Object.assign({}, interview, json.interview);
              // Normalize common fields
              if (json.interview.interview_datetime) updated.start = json.interview.interview_datetime;
              if (json.interview.status_id) updated.status_id = parseInt(json.interview.status_id,10);
              if (json.interview.status_name) updated.status_name = json.interview.status_name;
              if (json.interview.status_color) updated.status_color = json.interview.status_color;
              if (json.interview.comments !== undefined) updated.comments = json.interview.comments;
              // replace in global list
              try{
                const idx = (window._INTERVIEWS||[]).findIndex(x => String(x.id) === String(updated.id));
                if (idx !== -1) window._INTERVIEWS[idx] = updated;
              }catch(e){}
              // update local reference
              for (const k in updated) try{ interview[k] = updated[k]; }catch(e){}
            }catch(e){ /* ignore */ }
            // Refresh list and calendar so change is visible everywhere, then close drawer
            try{ refreshViews(interview); }catch(e){}
            try{ refreshCalendarEvent(interview); }catch(e){}
            try{ closeDrawer(); }catch(e){}
            notify({ from: 'Interviews', message: 'Status updated to ' + ((interview.status_name||json.interview.status_name)||nextId), color: '#10b981' });
          } else {
            const errMsg = (json && (json.message || json.error)) ? (json.message || json.error) : 'Update failed';
            notify({ from: 'Interviews', message: errMsg, color: '#dc2626' });
          }
        }catch(err){ console.error(err); notify({ from: 'Interviews', message: 'Request failed', color: '#dc2626' }); }
        finally{ try{ nextBtns.forEach(n => { n.disabled = false; if (n === btn) n.textContent = origText; }); }catch(e){} }
      });
    });

    // hook up schedule update button with inline validation: Save disabled until datetime-local contains a valid datetime
    const schedBtn = document.getElementById('drawerUpdateScheduleBtn');
    const dtEl = document.getElementById('drawerDateTime');
    function isValidDateTimeLocal(v){ if (!v) return false; try{ const d = new Date(v); return !isNaN(d.getTime()); }catch(e){ return false; } }
    if (dtEl && schedBtn){
      // set initial enabled/disabled state
      try{ schedBtn.disabled = !isValidDateTimeLocal(dtEl.value); }catch(e){ schedBtn.disabled = true; }
      // respond to user changes
      ['input','change'].forEach(evt => dtEl.addEventListener(evt, function(){ try{ schedBtn.disabled = !isValidDateTimeLocal(dtEl.value); }catch(e){ schedBtn.disabled = true; } }));

      schedBtn.addEventListener('click', async function(){
        const commentsVal = document.getElementById('drawerComments') ? document.getElementById('drawerComments').value : '';
        if (!dtEl) return;
        const val = dtEl.value; // format YYYY-MM-DDTHH:MM
        if (!isValidDateTimeLocal(val)) { notify({ from: 'Interviews', message: 'Please select a valid date/time', color: '#f59e0b' }); return; }
        // convert to 'YYYY-MM-DD HH:MM:SS' (append seconds)
        const converted = val.replace('T',' ') + ':00';
        try{
          schedBtn.disabled = true; const prev = schedBtn.textContent; schedBtn.textContent = 'Saving...';
          const fd = new FormData(); fd.append('id', interview.id); fd.append('interview_datetime', converted); fd.append('comments', commentsVal);
          const res = await fetch('update_interview.php', { method:'POST', body: fd, credentials: 'same-origin' });
          const txt = await res.text(); let json = {};
          try{ json = JSON.parse(txt); }catch(e){ console.warn('update response not json', txt); }
          if (json && json.ok && json.interview) {
            // update local object with server data
            try{
              const updated = Object.assign({}, interview, json.interview);
              if (json.interview.interview_datetime) updated.start = json.interview.interview_datetime;
              if (json.interview.end) updated.end = json.interview.end;
              if (json.interview.comments !== undefined) updated.comments = json.interview.comments;
              // replace in global list
              try{ const idx = (window._INTERVIEWS||[]).findIndex(x => String(x.id) === String(updated.id)); if (idx !== -1) window._INTERVIEWS[idx] = updated; }catch(e){}
              // update local reference
              for (const k in updated) try{ interview[k] = updated[k]; }catch(e){}
            }catch(e){}
            // refresh UI and close drawer
            try{ refreshViews(interview); }catch(e){}
            try{ refreshCalendarEvent(interview); }catch(e){}
            try{ closeDrawer(); }catch(e){}
            notify({ from: 'Interviews', message: 'Schedule updated', color: '#10b981' });
          } else {
            notify({ from: 'Interviews', message: 'Update failed', color: '#dc2626' });
          }
        }catch(err){ console.error(err); notify({ from: 'Interviews', message: 'Request failed', color: '#dc2626' }); }
        finally{ schedBtn.disabled = false; schedBtn.textContent = 'Save'; }
      });
    } else if (schedBtn) {
      // if no dt element, keep Save disabled
      schedBtn.disabled = true;
    }
  }

  // Update a row in table
  function refreshTableRow(interview){
    const tr = document.querySelector('#interviewsTbody tr[data-id="' + interview.id + '"]');
    if (!tr) return;
    tr.outerHTML = renderRow(interview);
    attachRowHandlers();
  }

  // Refresh either table row or cards panel depending on which is present
  function refreshViews(interview){
    const tbody = document.getElementById('interviewsTbody');
    if (tbody) {
      // update the specific table row
      const tr = tbody.querySelector('tr[data-id="' + interview.id + '"]');
      if (tr) {
        tr.outerHTML = renderRow(interview);
        attachRowHandlers();
      } else {
        // if row not found, re-render full table
        renderTable(window._INTERVIEWS || []);
      }
    } else {
      // render cards list
      renderCards(window._INTERVIEWS || []);
    }
  }

  // Update calendar event color/text
  function refreshCalendarEvent(interview){
    if (!window._calendar) return;
    // robustly replace the event so inline styles and FullCalendar internals update correctly
    try{
      const cal = window._calendar;
      const evId = 'interview-' + interview.id;
      const existing = cal.getEventById(evId);
      if (existing) {
        try{ existing.remove(); }catch(e){}
      }
      // build a single event object matching buildEvents
      const st = (window._INTERVIEW_STATUSES || {})[String(interview.status_id)];
      const color = interview.status_color || (st ? st.color : null) || eventColor(interview.status_name);
      const textColor = getContrastColor(color);
      const evtObj = {
        id: evId,
        title: (interview.applicant_name || 'Applicant') + ' — ' + (interview.position_name || ''),
        start: interview.start || interview.interview_datetime || null,
        end: interview.end || null,
        extendedProps: Object.assign({}, interview),
        backgroundColor: color,
        borderColor: color,
        textColor: textColor,
        classNames: ['interview-event']
      };
      try{ cal.addEvent(evtObj); }catch(e){ console.warn('Failed to add calendar event', e); }
    }catch(e){ /* ignore */ }
  }

  // Lightweight hover highlight cache for calendar events so we can restore styles
  const _eventHoverCache = {};

  function highlightCalendarEvent(id, enter){
    if (!window._calendar) return;
    const evt = window._calendar.getEventById('interview-' + id);
    if (!evt) return;
    const key = String(id);
    // Try to find the event DOM element that was created in eventDidMount
    const selector = '[data-event-id="' + ('interview-' + id) + '"]';
    const el = document.querySelector(selector);
    if (enter){
      if (!_eventHoverCache[key]){
        // capture existing computed styles from the element and key children if available
        let origBg = null, origBorder = null, origText = null, origBy = null, origTime = null;
        if (el){
          try{
            const cs = window.getComputedStyle(el);
            origBg = cs.backgroundColor || null;
            origBorder = cs.borderColor || origBg;
            origText = cs.color || null;
            const byEl = el.querySelector('.fc-custom-by');
            const timeEl = el.querySelector('.fc-custom-time');
            if (byEl) origBy = window.getComputedStyle(byEl).color || null;
            if (timeEl) origTime = window.getComputedStyle(timeEl).color || null;
          }catch(e){ }
        }
        if (!origBg) origBg = evt.backgroundColor || (evt.extendedProps && evt.extendedProps.status_color) || null;
        if (!origBorder) origBorder = evt.borderColor || origBg;
        if (!origText) origText = evt.textColor || null;
        _eventHoverCache[key] = { backgroundColor: origBg, borderColor: origBorder, textColor: origText, byColor: origBy, timeColor: origTime };
      }
      try{
        // On card hover, make the event background black and text white for a strong highlight
        const byEl = el ? el.querySelector('.fc-custom-by') : null;
        const timeEl = el ? el.querySelector('.fc-custom-time') : null;
        if (el){ el.style.backgroundColor = '#000000'; el.style.borderColor = '#000000'; el.style.color = '#ffffff'; }
        if (byEl) byEl.style.color = '#ffffff';
        if (timeEl) timeEl.style.color = '#ffffff';
        const main = el ? el.querySelector('.fc-event-main') : null; if (main){ main.style.backgroundColor = '#000000'; main.style.color = '#ffffff'; main.style.borderColor = '#000000'; }
        try{ evt.setProp('backgroundColor', '#000000'); }catch(e){}
        try{ evt.setProp('borderColor', '#000000'); }catch(e){}
        try{ evt.setProp('textColor', '#ffffff'); }catch(e){}
      }catch(e){}
    } else {
      const orig = _eventHoverCache[key];
      if (orig){
        try{
          if (el){
            if (orig.backgroundColor) el.style.backgroundColor = orig.backgroundColor; else el.style.backgroundColor = '';
            if (orig.borderColor) el.style.borderColor = orig.borderColor; else el.style.borderColor = '';
            if (orig.textColor) el.style.color = orig.textColor; else el.style.color = '';
            const byEl = el.querySelector('.fc-custom-by');
            const timeEl = el.querySelector('.fc-custom-time');
            if (byEl){ if (orig.byColor) byEl.style.color = orig.byColor; else byEl.style.color = ''; }
            if (timeEl){ if (orig.timeColor) timeEl.style.color = orig.timeColor; else timeEl.style.color = ''; }
            const main = el.querySelector('.fc-event-main'); if (main){ if (orig.backgroundColor) main.style.backgroundColor = orig.backgroundColor; else main.style.backgroundColor = ''; if (orig.textColor) main.style.color = orig.textColor; else main.style.color = ''; if (orig.borderColor) main.style.borderColor = orig.borderColor; else main.style.borderColor = ''; }
          }
        }catch(e){}
        try{ if (orig.backgroundColor !== null) evt.setProp('backgroundColor', orig.backgroundColor); }
        catch(e){}
        try{ if (orig.borderColor !== null) evt.setProp('borderColor', orig.borderColor); }
        catch(e){}
        try{ if (orig.textColor !== null) evt.setProp('textColor', orig.textColor); }
        catch(e){}
        delete _eventHoverCache[key];
      }
    }
  }

  // Attach click handlers to table rows
  function attachRowHandlers(){
    document.querySelectorAll('#interviewsTbody tr').forEach(tr => {
      tr.addEventListener('click', function(){
        const id = tr.getAttribute('data-id');
        const interview = (window._INTERVIEWS || []).find(x => String(x.id) === String(id));
        if (interview){ renderDrawer(interview); openDrawer(); }
      });
    });
  }

  // Initialize calendar
  function initCalendar(events){
    const calendarEl = document.getElementById('calendar');
    // Determine constructor: prefer normalized __FC_CTOR, then FullCalendar.Calendar, then fc.Calendar-like shapes
    const CalendarCtor = window.__FC_CTOR || (window.FullCalendar && window.FullCalendar.Calendar) || null;
    if (!CalendarCtor || typeof CalendarCtor !== 'function') {
      console.error('FullCalendar Calendar constructor not available. Calendar cannot be initialized.');
      showCalendarError('Calendar is not loading', 'FullCalendar constructor not available. The loaded build may not be compatible.');
      return;
    }

    const plugins = [];
    if (window.__FC_PLUGINS && window.__FC_PLUGINS.dayGrid) plugins.push(window.__FC_PLUGINS.dayGrid);
    if (window.__FC_PLUGINS && window.__FC_PLUGINS.interaction) plugins.push(window.__FC_PLUGINS.interaction);

    const calendar = new CalendarCtor(calendarEl, {
      plugins: plugins,
      initialView: 'dayGridMonth',
      headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,dayGridWeek,dayGridDay' },
      selectable: true,
      // Allow all events to render in the cell and let CSS handle scrolling
      dayMaxEventRows: false,
      eventDisplay: 'block',
      events: events,
      // Render only the time + created_by name in the event element and avoid the default dot
      eventContent: function(arg){
        try{
          const start = arg.event.start;
          const time = start ? new Date(start).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
          const by = (arg.event.extendedProps && (arg.event.extendedProps.created_by_name || arg.event.extendedProps.created_by)) ? (arg.event.extendedProps.created_by_name || arg.event.extendedProps.created_by) : '';
          const byDept = (arg.event.extendedProps && (arg.event.extendedProps.created_by_department || arg.event.extendedProps.created_by_dept)) ? (arg.event.extendedProps.created_by_department || arg.event.extendedProps.created_by_dept) : '';
          const applicantId = (arg.event.extendedProps && (arg.event.extendedProps.applicant_id || arg.event.extendedProps.applicant)) ? (arg.event.extendedProps.applicant_id || arg.event.extendedProps.applicant) : '';
          // layout: single horizontal row with ' - ' separators: time - creator (department) - #applicant
          const creatorPart = by ? escapeHtml(by) + (byDept ? (' (' + escapeHtml(byDept) + ')') : '') : '';
          const applicantPart = applicantId ? ('#' + escapeHtml(applicantId)) : '';
          const parts = [ escapeHtml(time), creatorPart, applicantPart ].filter(Boolean).join(' - ');
          const html = `<div class="fc-custom-event">${parts}</div>`;
          return { html: html };
        }catch(e){ return { html: escapeHtml(arg.event.title || '') }; }
      },
      eventDidMount: function(info){
        try{
          info.el.setAttribute('title', info.event.title || '');
          // expose the event id on the DOM element so we can find it later for hover highlighting
          try { info.el.setAttribute('data-event-id', info.event.id || ''); } catch(e){}
          const bg = info.event.backgroundColor || (info.event.extendedProps && info.event.extendedProps.status_color) || null;
          const br = info.event.borderColor || bg;
          const txt = info.event.textColor || getContrastColor(bg);
          if (bg) { info.el.style.backgroundColor = bg; info.el.style.borderColor = br; }
          if (txt) info.el.style.color = txt;
          const main = info.el.querySelector('.fc-event-main'); if (main){ if (bg) main.style.backgroundColor = bg; if (txt) main.style.color = txt; if (br) main.style.borderColor = br; }
        }catch(e){}
      },
      eventClick: function(info){
        const props = info.event.extendedProps || {};
        const id = props.id || props.interviewId;
        const interview = (window._INTERVIEWS || []).find(x => String(x.id) === String(id));
        if (interview){ renderDrawer(interview); openDrawer(); }
      }
    });
    calendar.render();
    window._calendar = calendar;
  }

  // Render table (or fallback to cards when table isn't present)
  function renderTable(list){
    const tbody = document.getElementById('interviewsTbody');
    if (tbody) {
      tbody.innerHTML = list.map(renderRow).join('');
      attachRowHandlers();
      return;
    }
    renderCards(list);
  }

  // Render cards into the right-side panel when table is not present
  function renderCards(list){
    const container = document.getElementById('interviewsCards');
    if (!container) return;
    container.innerHTML = list.map(i => {
      const id = i.id;
      const statusObj = (window._INTERVIEW_STATUSES && window._INTERVIEW_STATUSES[i.status_id]) ? window._INTERVIEW_STATUSES[i.status_id] : null;
      const statusName = i.status_name || (statusObj ? statusObj.name : '');
      const statusColor = i.status_color || (statusObj ? statusObj.color : '#6b7280');
      return `
        <article class="p-3 rounded bg-transparent border-l-4 card-item" data-id="${id}" style="border-left-color: ${escapeHtml(statusColor)};">
          <div class="flex items-start justify-between gap-2">
            <div class="text-sm">
              <div class="font-medium" style="color:white;">${escapeHtml(i.applicant_name||'—')} ${i.applicant_id ? `<span class="text-xs text-gray-300 ml-2">#${escapeHtml(i.applicant_id)}</span>` : ''}</div>
              <div class="text-xs text-gray-300">${escapeHtml(i.position_name||'—')} · ${escapeHtml(i.department_name||'')}</div>
            </div>
            <div class="text-right">
              <div class="text-xs"><span class="status-badge px-2 py-1 rounded text-xs" style="background: ${escapeHtml(statusColor)}; color: white;">${escapeHtml(statusName)}</span></div>
            </div>
          </div>
          <div class="mt-2 text-xs text-gray-300">${escapeHtml(i.start||i.interview_datetime||'')}</div>
          <div class="mt-3 flex justify-end"></div>
        </article>
      `;
    }).join('');
    attachCardHandlers();
  }

  // Attach click handlers to card items in the right panel
  function attachCardHandlers(){
    const container = document.getElementById('interviewsCards');
    if (!container) return;
    container.querySelectorAll('article[data-id]').forEach(article => {
      const id = article.getAttribute('data-id');
      article.addEventListener('click', function(e){
        const interview = (window._INTERVIEWS||[]).find(x => String(x.id) === String(id));
        if (interview){ renderDrawer(interview); openDrawer(); }
      });
      article.addEventListener('mouseenter', function(){ highlightCalendarEvent(id, true); });
      article.addEventListener('mouseleave', function(){ highlightCalendarEvent(id, false); });
    });
  }

  // Filter logic
  function applyFilters(){
    const depEl = document.getElementById('f-department');
    const teamEl = document.getElementById('f-team');
    const managerEl = document.getElementById('f-manager');
    const posEl = document.getElementById('f-position');
    const statusEl = document.getElementById('f-status');
    const fromEl = document.getElementById('f-date-from');
    const toEl = document.getElementById('f-date-to');
    const dep = depEl ? depEl.value : '';
    const team = teamEl ? teamEl.value : '';
    const manager = managerEl ? managerEl.value : '';
    const pos = posEl ? posEl.value : '';
    const status = statusEl ? statusEl.value : '';
    const from = fromEl ? fromEl.value : '';
    const to = toEl ? toEl.value : '';
    const createdEl = document.getElementById('f-created-by');
    const created = createdEl ? createdEl.value : '';

    let rows = (window._INTERVIEWS||[]).slice();
    if (dep) rows = rows.filter(r=> (r.department_name||'') === dep);
    if (team) rows = rows.filter(r=> (r.team_name||'') === team);
    if (manager) rows = rows.filter(r=> ((r.manager_name||r.manager||'') === manager));
    if (created) rows = rows.filter(r=> ((r.created_by_name||r.created_by||'') === created));
    if (pos) rows = rows.filter(r=> (r.position_name||'') === pos);
    if (status) rows = rows.filter(r=> {
      const sn = (window._INTERVIEW_STATUSES && window._INTERVIEW_STATUSES[r.status_id]) ? window._INTERVIEW_STATUSES[r.status_id].name : '';
      return sn === status;
    });
    if (from) rows = rows.filter(r=> r.start && r.start >= from);
    if (to) rows = rows.filter(r=> r.start && r.start <= to + ' 23:59:59');

    renderTable(rows);
    // update calendar to reflect the filtered rows: rebuild event source so calendar and list are in sync
    if (window._calendar){
      try{
        // clear hover cache because events will be recreated
        for (const k in _eventHoverCache) delete _eventHoverCache[k];
      }catch(e){}
      try{
        window._calendar.removeAllEvents();
      }catch(e){ /* ignore if not supported */ }
      try{
        const events = buildEvents(rows, window._INTERVIEW_STATUSES || {});
        // add events directly
        if (events && events.length) window._calendar.addEventSource(events);
      }catch(e){ console.warn('Failed to repopulate calendar events after filtering', e); }
    }
  }

  // wire filter events
  function attachFilterHandlers(){
    ['f-department','f-team','f-position','f-status','f-manager','f-created-by','f-date-from','f-date-to'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('change', applyFilters);
    });
    const clear = document.getElementById('clearFilters'); if (clear) clear.addEventListener('click', function(){
      const ids = ['f-department','f-team','f-position','f-status','f-created-by','f-date-from','f-date-to']; ids.forEach(i => { const el = document.getElementById(i); if (el) el.value = ''; }); applyFilters();
    });
  }

  // initial boot
  document.addEventListener('DOMContentLoaded', function(){
    const list = window._INTERVIEWS || [];
    const statuses = window._INTERVIEW_STATUSES || {};
    console.log('Interviews data:', list);
    console.log('Interview statuses:', statuses);
    // Helper to display a centered error message inside the calendar area
    function showCalendarError(title, reason){
      try{
        const host = document.getElementById('calendar') || document.getElementById('calendarContainer');
        if (!host) return;
        host.innerHTML = '';
        const wrapper = document.createElement('div');
        wrapper.style.minHeight = '260px';
        wrapper.style.display = 'flex';
        wrapper.style.flexDirection = 'column';
        wrapper.style.alignItems = 'center';
        wrapper.style.justifyContent = 'center';
        wrapper.style.gap = '10px';
        wrapper.style.textAlign = 'center';
        wrapper.style.padding = '20px';
        wrapper.style.color = 'var(--text-main, #111827)';
        wrapper.innerHTML = `
            <div style="font-size:18px;font-weight:700;margin-bottom:6px;">${escapeHtml(title || 'Calendar is not loading')}</div>
            <div style="color:var(--text-muted,#6b7280);max-width:720px;">${escapeHtml(reason || 'Service provider not responding, or network/CORS issue. You can retry loading the calendar.' )}</div>
          `;
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.textContent = 'Retry loading calendar';
        retry.className = 'btn';
        retry.style.marginTop = '8px';
        retry.addEventListener('click', function(){
          // attempt to re-run the boot flow: try to load FullCalendar again
          host.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-muted,#6b7280)">Retrying&hellip;</div>';
          // small delay to allow UI to update
          setTimeout(function(){ ensureFullCalendar().then(fc => {
            if (!fc) { showCalendarError('Calendar is not loading', 'Retry failed — provider still unreachable.'); return; }
            try { const events = buildEvents(window._INTERVIEWS || [], window._INTERVIEW_STATUSES || {}); initCalendar(events); } catch(e){ showCalendarError('Calendar initialization failed', String(e && e.message ? e.message : e)); }
          }); }, 60);
        });
        wrapper.appendChild(retry);
        host.appendChild(wrapper);
      }catch(e){ console.warn('showCalendarError failed', e); }
    }

    // Ensure FullCalendar is available. Some CDN builds are ESM and contain `import` statements;
    // in that case we try a dynamic import so we can proceed without a hard failure.
    function ensureFullCalendar(){
      // If a global FullCalendar is already present (UMD build) use it.
      if (typeof window.FullCalendar !== 'undefined') {
        window.__FC = window.FullCalendar;
        window.__FC_CTOR = window.FullCalendar.Calendar || null;
        window.__FC_PLUGINS = { dayGrid: window.FullCalendar.dayGridPlugin || null, interaction: window.FullCalendar.interactionPlugin || null };
        return Promise.resolve(window.__FC);
      }

      // Otherwise attempt to load an ESM build via dynamic import first (some CDNs publish ESM-only builds),
      // and fall back to injecting UMD/global script tags if import fails. Try multiple CDN providers
      // to increase resilience in air-gapped or restricted environments.
      const versions = {
        core: '5.11.3',
        daygrid: '5.11.3',
        interaction: '5.11.3'
      };
      // Candidate CDN base prefixes we will try
      const cdnBases = ['https://cdn.jsdelivr.net/npm', 'https://unpkg.com'];

      // Try dynamic import (ESM) first. If the file is an ES module, import() will succeed and return module exports.
      // If import fails (CORS or not an ESM build) we fall back to script injection.
      async function tryImportBuild(){
        // Try dynamic import() from multiple CDN sources. If one succeeds, normalize exports.
        for (const base of cdnBases) {
          const coreUrl = base + `/@fullcalendar/core@${versions.core}/main.min.js`;
          try {
            const coreMod = await import(/* webpackIgnore: true */ coreUrl);
            const fc = coreMod && (coreMod.FullCalendar || coreMod.default || coreMod);
            let daygrid = null, interaction = null;
            try { const d = await import(/* webpackIgnore: true */ base + `/@fullcalendar/daygrid@${versions.daygrid}/main.min.js`); daygrid = d && (d.default || d); } catch(e) { console.warn('FullCalendar dayGrid dynamic import failed', e); }
            try { const it = await import(/* webpackIgnore: true */ base + `/@fullcalendar/interaction@${versions.interaction}/main.min.js`); interaction = it && (it.default || it); } catch(e) { console.warn('FullCalendar interaction dynamic import failed', e); }
            if (!window.FullCalendar) {
              if (fc && fc.Calendar) window.FullCalendar = fc;
              else if (fc && fc.calendar) window.FullCalendar = fc;
              else console.warn('Dynamic import returned unknown FullCalendar shape', fc);
            }
            if (window.FullCalendar) {
              try { window.FullCalendar.dayGridPlugin = (daygrid && (daygrid.dayGridPlugin || daygrid.default || daygrid)) || window.FullCalendar.dayGridPlugin || null; } catch(e){}
              try { window.FullCalendar.interactionPlugin = (interaction && (interaction.interactionPlugin || interaction.default || interaction)) || window.FullCalendar.interactionPlugin || null; } catch(e){}
              window.__FC = window.FullCalendar;
              window.__FC_CTOR = window.FullCalendar.Calendar || null;
              window.__FC_PLUGINS = { dayGrid: window.FullCalendar.dayGridPlugin || null, interaction: window.FullCalendar.interactionPlugin || null };
              return window.__FC;
            }
          } catch (e) {
            console.warn('FullCalendar dynamic import failed for base', base, e);
            // try next base
          }
        }
        return null;
      }

      function inject(url){
        return new Promise((resolve, reject) => {
          const s = document.createElement('script');
          s.src = url;
          s.async = false;
          s.onload = () => resolve();
          s.onerror = () => reject(new Error('Failed to load ' + url));
          document.head.appendChild(s);
        });
      }

      // Try ESM import, then fall back to injecting UMD scripts
      return tryImportBuild()
        .then(async result => {
          if (result) return result;
          // ESM import didn't yield usable fc — try UMD script approach
          // Script-injection fallback: try multiple core/plugin candidates
          const coreCandidates = [
            `https://cdn.jsdelivr.net/npm/@fullcalendar/core@${versions.core}/main.min.js`,
            `https://unpkg.com/@fullcalendar/core@${versions.core}/main.min.js`,
            `https://cdn.jsdelivr.net/npm/fullcalendar@${versions.core}/main.min.js`
          ];
          const daygridCandidates = [
            `https://cdn.jsdelivr.net/npm/@fullcalendar/daygrid@${versions.daygrid}/main.min.js`,
            `https://unpkg.com/@fullcalendar/daygrid@${versions.daygrid}/main.min.js`
          ];
          const interactionCandidates = [
            `https://cdn.jsdelivr.net/npm/@fullcalendar/interaction@${versions.interaction}/main.min.js`,
            `https://unpkg.com/@fullcalendar/interaction@${versions.interaction}/main.min.js`
          ];

          let loaded = false;
          for (const coreCandidate of coreCandidates) {
            try {
              await inject(coreCandidate);
              // try plugins (take first that loads)
              for (const d of daygridCandidates) { try { await inject(d); break; } catch(e){ console.warn('daygrid candidate failed', d, e); } }
              for (const i of interactionCandidates) { try { await inject(i); break; } catch(e){ console.warn('interaction candidate failed', i, e); } }
              if (typeof window.FullCalendar !== 'undefined') { loaded = true; break; }
            } catch(e) {
              console.warn('core candidate failed', coreCandidate, e);
            }
          }
          if (!loaded) return null;
          window.__FC = window.FullCalendar;
          window.__FC_CTOR = window.FullCalendar.Calendar || null;
          window.__FC_PLUGINS = { dayGrid: window.FullCalendar.dayGridPlugin || null, interaction: window.FullCalendar.interactionPlugin || null };
          return window.__FC;
        })
        .catch(err => { console.warn('FullCalendar load failed', err); return null; });
    }

    ensureFullCalendar().then(fc => {
      if (!fc) {
        console.error('FullCalendar not available. Calendar will not be initialized.');
        // show a user-friendly message in the calendar container
        showCalendarError('Calendar is not loading', 'Service provider not responding, or network/CORS issue.');
        // render filters/table only
        populateFilters(list, statuses);
        renderTable(list);
        attachFilterHandlers();
        return;
      }

      populateFilters(list, statuses);
      renderTable(list);
      attachFilterHandlers();
      attachPanelFilterHandler(list, statuses);
      const events = buildEvents(list, statuses);
      const calendarEl = document.getElementById('calendar') || document.getElementById('calendarContainer');
      if (!calendarEl) {
        console.error('Calendar container not found: expected #calendar or #calendarContainer');
        showCalendarError('Calendar is not loading', 'Calendar container element not found on the page.');
      } else {
        try { initCalendar(events); } catch (e) { console.error('initCalendar threw', e); showCalendarError('Calendar initialization failed', String(e && e.message ? e.message : e)); }
      }
    });

    // Panel filter button: toggles the inline filter panel that pushes down the card list
    function attachPanelFilterHandler(list, statuses){
      const btn = document.getElementById('panelFilterBtn');
      const panel = document.getElementById('filterPanel');
      const clearBtn = document.getElementById('panelClearFilters');
      if (!btn || !panel) return;

      btn.addEventListener('click', function(){
        const opened = panel.classList.toggle('open');
        if (opened) {
          populatePanelFilters(list, statuses);
          // don't auto-apply here — user can make selections and click Apply
        }
      });

      if (clearBtn) clearBtn.addEventListener('click', function(){
        ['f-department','f-manager','f-position','f-status','f-created-by'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
        applyFilters();
      });
    }

    function populatePanelFilters(list, statuses){
      const depEl = document.getElementById('f-department');
      const mgrEl = document.getElementById('f-manager');
      const posEl = document.getElementById('f-position');
      const statusEl = document.getElementById('f-status');
      const createdEl = document.getElementById('f-created-by');

      if (depEl) depEl.innerHTML = '<option value="">-- Select Department --</option>';
      if (mgrEl) mgrEl.innerHTML = '<option value="">-- Select Manager --</option>';
      if (posEl) posEl.innerHTML = '<option value="">-- Select Position --</option>';
      if (statusEl) statusEl.innerHTML = '<option value="">-- Select Status --</option>';

      // Departments from server if available
      if (window._DB_DEPARTMENTS && Array.isArray(window._DB_DEPARTMENTS)){
        window._DB_DEPARTMENTS.forEach(d => {
          const val = (d && (d.name||d.department_name)) ? (d.name||d.department_name) : (typeof d === 'string' ? d : null);
          if (!val) return;
          const o = document.createElement('option'); o.value = val; o.textContent = val; depEl && depEl.appendChild(o);
        });
      } else {
        const deps = new Set(); list.forEach(i => { if (i.department_name) deps.add(i.department_name); }); deps.forEach(d => { const o = document.createElement('option'); o.value = d; o.textContent = d; depEl && depEl.appendChild(o); });
      }

      // Managers from server if available (some exports may be strings or objects)
      if (window._DB_MANAGERS && Array.isArray(window._DB_MANAGERS)){
        window._DB_MANAGERS.forEach(m => {
          const val = (m && (m.manager_name||m.name)) ? (m.manager_name||m.name) : (typeof m === 'string' ? m : null);
          if (!val) return;
          const o = document.createElement('option'); o.value = val; o.textContent = val; mgrEl && mgrEl.appendChild(o);
        });
      } else {
        const managers = new Set(); list.forEach(i => { if (i.manager_name) managers.add(i.manager_name); if (i.manager) managers.add(i.manager); }); managers.forEach(m => { if (!m) return; const o = document.createElement('option'); o.value = m; o.textContent = m; mgrEl && mgrEl.appendChild(o); });
      }

      // Positions from server if available
      if (window._POSITIONS_WITH_INTERVIEWS && Array.isArray(window._POSITIONS_WITH_INTERVIEWS)){
        window._POSITIONS_WITH_INTERVIEWS.forEach(p => {
          const val = (p && (p.title||p.position_name||p.name)) ? (p.title||p.position_name||p.name) : (typeof p === 'string' ? p : null);
          if (!val) return;
          const o = document.createElement('option'); o.value = val; o.textContent = val; posEl && posEl.appendChild(o);
        });
      } else {
        const positions = new Set(); list.forEach(i => { if (i.position_name) positions.add(i.position_name); }); positions.forEach(p => { const o = document.createElement('option'); o.value = p; o.textContent = p; posEl && posEl.appendChild(o); });
      }

      if (statusEl && statuses){ Object.keys(statuses).forEach(k => { const s = statuses[k]; const o = document.createElement('option'); o.value = s.name; o.textContent = s.name; statusEl.appendChild(o); }); }

      // Created By: populate from list (creators for interviews currently present)
      if (createdEl){ createdEl.innerHTML = '<option value="">-- Select Creator --</option>'; const creators = new Set(); list.forEach(i => { if (i.created_by_name) creators.add(i.created_by_name); if (i.created_by) creators.add(i.created_by); }); creators.forEach(c => { const o = document.createElement('option'); o.value = c; o.textContent = c; createdEl.appendChild(o); }); }
    }

    // expose helper to open drawer for an interview id
    window.openInterview = function(id){ const interview = (window._INTERVIEWS||[]).find(x=>String(x.id)===String(id)); if (interview){ renderDrawer(interview); openDrawer(); } };
  });
})();
