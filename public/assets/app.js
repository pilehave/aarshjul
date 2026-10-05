// Årshjul: lidt JavaScript til formularen og eksport af hjulet som PNG/PDF.
(function () {
  'use strict';

  // --- Formular: vis kun felterne til den valgte gentagelsesregel ---
  var rec = document.getElementById('recurrence');
  function updateRules() {
    document.querySelectorAll('[data-rules]').forEach(function (el) {
      var on = el.getAttribute('data-rules').split(' ').indexOf(rec.value) !== -1;
      el.hidden = !on;
      el.querySelectorAll('input, select').forEach(function (i) { i.disabled = !on; });
    });
  }
  if (rec) { rec.addEventListener('change', updateRules); updateRules(); }

  // --- Formular: slutdato og varighed holdes i sync (begge datoer medregnes) ---
  var startIn = document.getElementById('start_date');
  var endIn = document.getElementById('period_end');
  var durIn = document.getElementById('duration_days');
  if (startIn && endIn && durIn) {
    var DAY = 86400000;
    var parse = function (v) { return /^\d{4}-\d{2}-\d{2}$/.test(v) ? Date.parse(v + 'T00:00:00Z') : NaN; };
    var fmt = function (t) { return new Date(t).toISOString().slice(0, 10); };
    var syncEnd = function () {
      var s = parse(startIn.value), d = parseInt(durIn.value, 10);
      if (!isNaN(s) && d >= 1) endIn.value = fmt(s + (d - 1) * DAY);
      if (!isNaN(s)) endIn.min = startIn.value;
    };
    var syncDuration = function () {
      var s = parse(startIn.value), e = parse(endIn.value);
      if (isNaN(s) || isNaN(e)) return;
      if (e < s) { endIn.value = startIn.value; e = s; }
      durIn.value = Math.round((e - s) / DAY) + 1;
    };
    startIn.addEventListener('change', syncEnd);
    durIn.addEventListener('input', syncEnd);
    endIn.addEventListener('change', syncDuration);
    if (!isNaN(parse(startIn.value))) endIn.min = startIn.value;
  }

  // --- Formular: vis farven for den valgte kategori ---
  var cat = document.getElementById('category_id');
  var swatch = document.getElementById('category-swatch');
  if (cat && swatch) {
    var updateSwatch = function () {
      swatch.style.background = cat.selectedOptions[0].getAttribute('data-color') || '#fff';
    };
    cat.addEventListener('change', updateSwatch);
    updateSwatch();
  }

  // --- Formular: vælg personer ved at skrive navnet; valgte vises som "navn ×," i feltet ---
  var picker = document.getElementById('people-picker');
  if (picker) {
    var all = JSON.parse(picker.getAttribute('data-people'));
    var input = picker.querySelector('.token-input');
    var list = picker.querySelector('.token-suggest');
    var active = -1;
    var chosen = function () {
      return Array.prototype.map.call(picker.querySelectorAll('input[name="people[]"]'), function (i) { return +i.value; });
    };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
    var updatePlaceholder = function () { input.placeholder = picker.querySelector('.token') ? '' : 'fx Anne Holm'; };
    var hide = function () { list.hidden = true; active = -1; };
    var highlight = function (n) {
      var items = list.querySelectorAll('li[data-id]');
      if (!items.length) return;
      active = (n + items.length) % items.length;
      items.forEach(function (li, i) { li.classList.toggle('active', i === active); });
      items[active].scrollIntoView({ block: 'nearest' });
    };
    var suggest = function () {
      var q = input.value.trim().toLowerCase();
      var taken = chosen();
      var hits = all.filter(function (p) { return taken.indexOf(p.id) === -1 && p.name.toLowerCase().indexOf(q) !== -1; });
      if (!q && !hits.length) { hide(); return; }
      list.innerHTML = hits.length
        ? hits.map(function (p) { return '<li role="option" data-id="' + p.id + '">' + esc(p.name) + '</li>'; }).join('')
        : '<li class="none">Ingen personer matcher. Opret dem under "Personer".</li>';
      list.hidden = false;
      active = -1;
      if (hits.length && q) highlight(0);
    };
    var add = function (id) {
      var p = all.filter(function (x) { return x.id === id; })[0];
      if (!p || chosen().indexOf(id) !== -1) return;
      var t = document.createElement('span');
      t.className = 'token';
      t.innerHTML = esc(p.name) + '<button type="button" class="token-x" title="Fjern ' + esc(p.name) + '" aria-label="Fjern ' + esc(p.name) + '">×</button>'
        + '<input type="hidden" name="people[]" value="' + p.id + '">';
      picker.insertBefore(t, input);
      input.value = '';
      updatePlaceholder();
      input.focus();
      suggest(); // vis de resterende personer, så den næste kan vælges med det samme
    };
    picker.addEventListener('click', function (ev) {
      var x = ev.target.closest('.token-x');
      if (x) { x.parentNode.remove(); updatePlaceholder(); input.focus(); suggest(); return; }
      if (ev.target === picker || ev.target === input) { input.focus(); if (list.hidden) suggest(); }
    });
    list.addEventListener('mousedown', function (ev) {
      ev.preventDefault(); // behold fokus i feltet
      var li = ev.target.closest('li[data-id]');
      if (li) add(+li.getAttribute('data-id'));
    });
    input.addEventListener('input', suggest);
    input.addEventListener('focus', suggest);
    input.addEventListener('blur', hide);
    input.addEventListener('keydown', function (ev) {
      var items = list.querySelectorAll('li[data-id]');
      if (ev.key === 'ArrowDown') { ev.preventDefault(); if (list.hidden) suggest(); highlight(active + 1); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); highlight(active - 1); }
      else if (ev.key === 'Enter' || ev.key === ',') {
        if (ev.key === 'Enter' && list.hidden && !input.value) return; // lad Enter sende formularen som normalt
        ev.preventDefault();
        if (!list.hidden && items[active]) add(+items[active].getAttribute('data-id'));
      }
      else if (ev.key === 'Escape') { hide(); }
      else if (ev.key === 'Backspace' && !input.value) {
        var tokens = picker.querySelectorAll('.token');
        if (tokens.length) { tokens[tokens.length - 1].remove(); updatePlaceholder(); suggest(); }
      }
    });
  }

  // --- Formular: tilføj/fjern linkrækker ---
  var addLink = document.getElementById('add-link');
  if (addLink) {
    addLink.addEventListener('click', function () {
      var rows = document.querySelectorAll('#links .link-row');
      var row = rows[rows.length - 1].cloneNode(true);
      row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      document.getElementById('links').appendChild(row);
    });
    document.getElementById('links').addEventListener('click', function (ev) {
      if (!ev.target.matches('[data-remove-row]')) return;
      var rows = document.querySelectorAll('#links .link-row');
      var row = ev.target.closest('.link-row');
      if (rows.length > 1) row.remove(); else row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    });
  }

  // --- Forside: beskrivelser vises med 2 linjer; "Vis mere" kun når teksten faktisk er længere ---
  var descs = document.querySelectorAll('.occ-desc');
  function updateDescToggles() {
    descs.forEach(function (d) {
      if (d.classList.contains('is-open')) return;
      d.nextElementSibling.hidden = d.scrollHeight <= d.clientHeight + 1;
    });
  }
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.occ-more');
    if (!btn) return;
    var open = btn.previousElementSibling.classList.toggle('is-open');
    btn.textContent = open ? 'Vis mindre' : 'Vis mere';
  });
  window.addEventListener('resize', updateDescToggles);
  updateDescToggles();

  // --- Forside: skjul/vis begivenhedslisten, så hjulet kan blive større ---
  var toggleList = document.getElementById('toggle-list');
  if (toggleList) {
    var root = document.documentElement;
    var syncToggle = function () {
      var hidden = root.classList.contains('list-hidden');
      toggleList.textContent = hidden ? 'Vis begivenheder' : 'Skjul begivenheder';
      toggleList.setAttribute('aria-expanded', hidden ? 'false' : 'true');
    };
    toggleList.addEventListener('click', function () {
      var hidden = root.classList.toggle('list-hidden');
      try { localStorage.setItem('aarshjul.listHidden', hidden ? '1' : '0'); } catch (e) {}
      syncToggle();
      updateDescToggles(); // en skjult liste kan ikke måles, så tjek igen, når den vises
    });
    syncToggle();
  }

  // --- Forside: klik på en begivenhed i hjulet åbner en modal med detaljerne i stedet for redigering ---
  var modal = document.getElementById('occ-modal');
  var occData = document.getElementById('occ-data');
  var wheelEl = document.getElementById('wheel');
  if (modal && occData && wheelEl && modal.showModal) {
    var details = JSON.parse(occData.textContent);
    var field = function (name) { return modal.querySelector('[data-f="' + name + '"]'); };
    var showRow = function (name, on) { modal.querySelectorAll('[data-row="' + name + '"]').forEach(function (el) { el.hidden = !on; }); };
    var openOcc = function (d) {
      field('title').textContent = d.title;
      field('category').textContent = d.category;
      field('category').style.setProperty('--c', d.color);
      field('period').textContent = d.period;
      field('recurrence').textContent = d.recurrence;
      field('description').textContent = d.description;
      field('description').hidden = !d.description;
      // Flueben: samme formular som i listen. Efter gem åbnes modalen igen via #forekomst=id_dato
      var check = field('check');
      var box = check.elements.done;
      var locked = d.blocked && !d.done;
      check.elements.id.value = d.id;
      check.elements.date.value = d.date;
      check.elements.back.value = location.pathname + location.search + '#forekomst=' + d.id + '_' + d.date;
      box.checked = d.done;
      box.disabled = locked;
      box.parentNode.title = locked ? 'Kan først krydses af, når forudsætningerne er opfyldt' : 'Marker som opfyldt';
      field('status').hidden = !locked;
      field('overdue').hidden = !d.overdue;

      var people = field('people');
      people.textContent = '';
      d.people.forEach(function (name) {
        var c = document.createElement('span');
        c.className = 'chip'; c.textContent = name;
        people.appendChild(c);
      });
      showRow('people', d.people.length > 0);

      var prereqs = field('prereqs');
      prereqs.textContent = '';
      d.prereqs.forEach(function (p) {
        var div = document.createElement('div');
        div.className = 'prereq ' + (p.done ? 'ok' : 'wait');
        div.textContent = (p.done ? '✓ ' : '⏳ ') + p.title + ' (' + (p.date || 'ingen forekomst') + ')';
        prereqs.appendChild(div);
      });
      showRow('prereqs', d.prereqs.length > 0);

      var dependents = field('dependents');
      dependents.textContent = '';
      d.dependents.forEach(function (p) {
        var div = document.createElement('div');
        div.className = 'prereq';
        div.textContent = '→ ' + p.title + ' (' + (p.date || 'ingen forekomst') + ')';
        dependents.appendChild(div);
      });
      showRow('dependents', d.dependents.length > 0);

      field('edit').href = d.edit;
      modal.showModal();
    };
    wheelEl.addEventListener('click', function (ev) {
      var a = ev.target.closest('a[data-occ]');
      if (!a || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button !== 0) return; // ctrl-klik o.l. åbner stadig redigering
      var d = details[a.getAttribute('data-occ')];
      if (!d) return;
      ev.preventDefault();
      openOcc(d);
    });
    field('check').elements.done.addEventListener('change', function () { this.form.submit(); });
    modal.addEventListener('close', function () {
      if (location.hash.indexOf('#forekomst=') === 0) history.replaceState(null, '', location.pathname + location.search);
    });
    var reopen = /^#forekomst=(\d+)_(\d{4}-\d{2}-\d{2})$/.exec(location.hash);
    if (reopen) {
      var hit = details.filter(function (x) { return x.id === +reopen[1] && x.date === reopen[2]; })[0];
      if (hit) openOcc(hit);
    }
    modal.addEventListener('click', function (ev) {
      // Luk ved klik på en lukkeknap eller på baggrunden uden for modalen
      if (ev.target.closest('[data-close]') || ev.target === modal) modal.close();
    });
  }

  // --- Eksport ---
  var wheel = document.getElementById('wheel');
  if (!wheel) return;
  var year = wheel.getAttribute('data-year');
  var fileYear = year.replace('/', '-');

  function wheelToCanvas(scale) {
    var svg = wheel.querySelector('svg').cloneNode(true);
    svg.querySelectorAll('a').forEach(function (a) { while (a.firstChild) a.parentNode.insertBefore(a.firstChild, a); a.remove(); });
    var size = 1000 * scale;
    svg.setAttribute('width', size); svg.setAttribute('height', size);
    var xml = new XMLSerializer().serializeToString(svg);
    var url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(xml);
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () {
        var c = document.createElement('canvas');
        c.width = size; c.height = size;
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
        ctx.drawImage(img, 0, 0, size, size);
        resolve(c);
      };
      img.onerror = reject;
      img.src = url;
    });
  }

  function download(href, name) {
    var a = document.createElement('a');
    a.href = href; a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
  }

  function loadJsPdf() {
    if (window.jspdf) return Promise.resolve(window.jspdf.jsPDF);
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'assets/vendor/jspdf.umd.min.js';
      s.onload = function () { resolve(window.jspdf.jsPDF); };
      s.onerror = function () { reject(new Error('Kunne ikke indlæse jsPDF. Brug i stedet Udskriv → Gem som PDF.')); };
      document.head.appendChild(s);
    });
  }

  function listForPdf() {
    var out = [];
    document.querySelectorAll('#event-list > .month, #event-list > .occ').forEach(function (el) {
      if (el.classList.contains('month')) { out.push({ month: el.textContent.trim() }); return; }
      out.push({
        title: el.querySelector('.occ-title').textContent.trim(),
        category: el.querySelector('.occ-cat').textContent.trim(),
        meta: el.querySelector('.occ-meta').textContent.replace(/\s+/g, ' ').trim(),
        people: Array.prototype.map.call(el.querySelectorAll('.chip'), function (c) { return c.textContent; }).join(', '),
        done: el.classList.contains('is-done'),
        blocked: el.classList.contains('is-blocked'),
        overdue: el.classList.contains('is-overdue')
      });
    });
    return out;
  }

  async function exportPdf() {
    var JsPDF = await loadJsPdf();
    var canvas = await wheelToCanvas(2);
    var pdf = new JsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    pdf.setFontSize(18); pdf.text('Årshjul ' + year, 105, 15, { align: 'center' });
    pdf.addImage(canvas.toDataURL('image/png'), 'PNG', 10, 22, 190, 190);

    pdf.addPage();
    var y = 15;
    pdf.setFontSize(16); pdf.text('Begivenheder ' + year, 15, y); y += 8;
    listForPdf().forEach(function (r) {
      if (y > 280) { pdf.addPage(); y = 15; }
      if (r.month) { y += 3; pdf.setFontSize(12); pdf.setFont(undefined, 'bold'); pdf.text(r.month, 15, y); pdf.setFont(undefined, 'normal'); y += 6; return; }
      pdf.setFontSize(10);
      if (r.overdue) pdf.setTextColor(214, 69, 69);
      pdf.text((r.done ? '[x] ' : '[  ] ') + r.title + ' (' + r.category + ')' + (r.overdue ? '  (overskredet)' : '') + (r.blocked && !r.done ? '  (venter på forudsætning)' : ''), 18, y); y += 4.5;
      pdf.setTextColor(0);
      pdf.setFontSize(8); pdf.setTextColor(100);
      pdf.splitTextToSize(r.meta + (r.people ? ' · ' + r.people : ''), 175).forEach(function (line) { pdf.text(line, 24, y); y += 4; });
      pdf.setTextColor(0); y += 1.5;
    });
    pdf.save('aarshjul-' + fileYear + '.pdf');
  }

  document.querySelectorAll('[data-export]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var kind = btn.getAttribute('data-export');
      btn.disabled = true;
      var job = kind === 'png'
        ? wheelToCanvas(2).then(function (c) { download(c.toDataURL('image/png'), 'aarshjul-' + fileYear + '.png'); })
        : exportPdf();
      job.catch(function (e) { alert(e.message || e); }).finally(function () { btn.disabled = false; });
    });
  });
})();
