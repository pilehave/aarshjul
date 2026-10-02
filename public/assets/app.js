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

  // --- Eksport ---
  var wheel = document.getElementById('wheel');
  if (!wheel) return;
  var year = wheel.getAttribute('data-year');

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
        meta: el.querySelector('.occ-meta').textContent.replace(/\s+/g, ' ').trim(),
        people: Array.prototype.map.call(el.querySelectorAll('.chip'), function (c) { return c.textContent; }).join(', '),
        done: el.classList.contains('is-done'),
        blocked: el.classList.contains('is-blocked')
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
      pdf.text((r.done ? '[x] ' : '[  ] ') + r.title + (r.blocked && !r.done ? '  (venter på forudsætning)' : ''), 18, y); y += 4.5;
      pdf.setFontSize(8); pdf.setTextColor(100);
      pdf.splitTextToSize(r.meta + (r.people ? ' · ' + r.people : ''), 175).forEach(function (line) { pdf.text(line, 24, y); y += 4; });
      pdf.setTextColor(0); y += 1.5;
    });
    pdf.save('aarshjul-' + year + '.pdf');
  }

  document.querySelectorAll('[data-export]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var kind = btn.getAttribute('data-export');
      btn.disabled = true;
      var job = kind === 'png'
        ? wheelToCanvas(2).then(function (c) { download(c.toDataURL('image/png'), 'aarshjul-' + year + '.png'); })
        : exportPdf();
      job.catch(function (e) { alert(e.message || e); }).finally(function () { btn.disabled = false; });
    });
  });
})();
