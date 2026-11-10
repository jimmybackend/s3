(() => {
  'use strict';
  class SharedTextReader {
  constructor() {
  const content = document.getElementById('reader-content');
  if (!content) return;
  const paragraphs = [...content.querySelectorAll('.reader-text')];
  const originals = paragraphs.map(p => p.textContent);
  const reader = content.closest('.reader');
  const panels = [...content.querySelectorAll('[data-reader-panel]')];
  const pages = [...content.querySelectorAll('.book-page')];
  let spread = 0;
  const pagesPanel = panels.find(panel => panel.dataset.readerPanel === 'pages');
  let dialog = null;
  let turnBusy = false;
  const pageCount = () => window.matchMedia('(max-width: 700px)').matches ? 1 : 2;
  if (pagesPanel) {
    dialog = document.createElement('dialog');
    dialog.id = 'book-dialog';
    dialog.className = 'book-dialog';
    dialog.setAttribute('aria-label', 'Libro digital');
    const bar = document.createElement('div');
    bar.className = 'book-dialog-bar';
    const title = document.createElement('span');
    title.textContent = 'ArcadeCloud · Libro digital';
    const close = document.createElement('button');
    close.type = 'button'; close.textContent = 'Cerrar ×'; close.id = 'book-close';
    close.onclick = () => dialog.close();
    bar.append(title, close);
    content.append(dialog); dialog.append(bar, pagesPanel);
    const navigation = pagesPanel.querySelector('.book-navigation');
    pagesPanel.append(navigation);
    const label = document.createElement('label');
    label.textContent = 'Buscar ';
    const input = document.createElement('input');
    input.id = 'book-search'; input.type = 'search'; input.placeholder = 'Palabra en el libro';
    input.addEventListener('input', () => { search.value = input.value; search.dispatchEvent(new Event('input')); });
    label.append(input); navigation.append(label);
  }
  const showSpread = (index) => {
    spread = Math.max(0, Math.min(Math.floor((pages.length - 1) / pageCount()) * pageCount(), Math.floor(index / pageCount()) * pageCount()));
    pages.forEach((p, i) => { p.hidden = i < spread || i >= spread + pageCount(); });
    if (!pages.length) return;
    document.getElementById('book-position').textContent = `Páginas ${spread + 1}–${Math.min(spread + pageCount(), pages.length)} de ${pages.length}`;
    document.getElementById('book-prev').disabled = spread === 0;
    document.getElementById('book-next').disabled = spread + pageCount() >= pages.length;
    document.getElementById('book-jump').value = spread + 1;
  };
  let mode = reader.dataset.initialMode;
  const activeParagraphs = () => paragraphs.filter(p => !panels.length || p.closest('[data-reader-panel]')?.dataset.readerPanel === mode);
  const search = document.getElementById('reader-search');
  const matchesLabel = document.getElementById('reader-matches');
  let marks = [], active = -1, size = 17, timer;
  const move = (delta) => {
    if (!marks.length) return;
    if (active >= 0) marks[active].classList.remove('active');
    active = (active + delta + marks.length) % marks.length;
    marks[active].classList.add('active');
    const page = marks[active].closest('.book-page');
    if (page) { showSpread(Number(page.dataset.pageIndex)); if (dialog && !dialog.open) dialog.showModal(); }
    marks[active].scrollIntoView({block: 'center'});
    matchesLabel.textContent = `${active + 1} de ${marks.length}`;
  }
  const find = () => {
    marks = []; active = -1;
    const query = search.value.toLocaleLowerCase('es');
    paragraphs.forEach((p, i) => {
      p.textContent = '';
      const source = originals[i];
      if (!query || !activeParagraphs().includes(p)) { p.textContent = source; return; }
      const lower = source.toLocaleLowerCase('es');
      let cursor = 0, at;
      while ((at = lower.indexOf(query, cursor)) !== -1) {
        p.append(document.createTextNode(source.slice(cursor, at)));
        const mark = document.createElement('mark');
        mark.textContent = source.slice(at, at + query.length);
        p.append(mark); marks.push(mark); cursor = at + query.length;
      }
      p.append(document.createTextNode(source.slice(cursor)));
    });
    matchesLabel.textContent = query ? `${marks.length} coincidencias` : '';
    if (marks.length) move(1);
  }
  const setMode = (value) => {
    mode = value;
    panels.forEach(panel => { panel.hidden = panel.dataset.readerPanel !== value; });
    reader.querySelectorAll('[data-json-mode]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.jsonMode === value)));
    const continuousButton = document.getElementById('reader-mode');
    if (continuousButton) continuousButton.hidden = value !== 'transcript';
    if (dialog) {
      if (value === 'pages' && !dialog.open) dialog.showModal();
      else if (value !== 'pages' && dialog.open) dialog.close();
    }
    clearTimeout(timer); find();
  };
  reader.querySelectorAll('[data-json-mode]').forEach(button => button.addEventListener('click', () => setMode(button.dataset.jsonMode)));
  if (pages.length) {
    showSpread(0);
    const turn = async (direction) => {
      const target = spread + direction * pageCount();
      if (turnBusy || target < 0 || target >= pages.length) return;
      turnBusy = true;
      const visible = pages.filter(p => !p.hidden);
      const leaf = direction > 0 ? visible[visible.length - 1] : visible[0];
      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (!reduced && leaf && leaf.animate) {
        const overlay = leaf.cloneNode(true);
        overlay.classList.add('book-turn-leaf');
        overlay.removeAttribute('data-page-index'); overlay.setAttribute('aria-hidden', 'true');
        overlay.style.left = `${leaf.offsetLeft}px`; overlay.style.top = `${leaf.offsetTop}px`;
        overlay.style.width = `${leaf.offsetWidth}px`; overlay.style.height = `${leaf.offsetHeight}px`;
        overlay.style.transformOrigin = direction > 0 ? 'left center' : 'right center';
        pagesPanel.querySelector('.book-spread').append(overlay);
        showSpread(target);
        try {
          await overlay.animate([
            {transform: 'rotateY(0deg)', filter: 'brightness(1)'},
            {transform: `rotateY(${direction > 0 ? -85 : 85}deg)`, filter: 'brightness(.72)', offset: .55},
            {transform: `rotateY(${direction > 0 ? -170 : 170}deg)`, filter: 'brightness(.95)'}
          ], {duration: 620, easing: 'cubic-bezier(.4,0,.2,1)'}).finished;
        } finally { overlay.remove(); turnBusy = false; }
      } else { showSpread(target); turnBusy = false; }
      pages.filter(p => !p.hidden).forEach(p => { p.scrollTop = 0; });
    };
    document.getElementById('book-prev').onclick = () => turn(-1);
    document.getElementById('book-next').onclick = () => turn(1);
    dialog.addEventListener('close', () => { document.body.classList.remove('book-open'); if (mode === 'pages') setMode('raw'); });
    const observer = new MutationObserver(() => document.body.classList.toggle('book-open', dialog.open));
    observer.observe(dialog, {attributes:true, attributeFilter:['open']});
    dialog.addEventListener('keydown', e => {
      if (['INPUT', 'TEXTAREA'].includes(e.target.tagName)) return;
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); turn(e.key === 'ArrowRight' ? 1 : -1); }
    });
    window.addEventListener('resize', () => showSpread(spread));
    document.getElementById('book-jump').onchange = e => { const value = Number(e.target.value); if (Number.isFinite(value)) showSpread(value - 1); };
  }
  if (panels.length) setMode(mode);
  search.addEventListener('input' , () => { clearTimeout(timer); timer = setTimeout(find, 180); });
  search.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); if (active < 0) find(); else move(e.shiftKey ? -1 : 1); } });
  document.getElementById('reader-prev').onclick = () => move(-1);
  document.getElementById('reader-next').onclick = () => move(1);
  for (const [id, delta] of [['reader-smaller', -1], ['reader-larger', 1]]) document.getElementById(id).onclick = () => { size = Math.max(14, Math.min(26, size + delta)); content.style.setProperty('--reader-size', `${size}px`); };
  document.getElementById('reader-mode')?.addEventListener('click', e => {
    const continuous = content.classList.toggle('continuous');
    e.currentTarget.textContent = continuous ? 'Ver segmentos' : 'Texto continuo';
    e.currentTarget.setAttribute('aria-pressed', String(continuous));
  });
  document.getElementById('reader-copy').onclick = async () => {
    const text = activeParagraphs().map(p => {
      const i = paragraphs.indexOf(p);
      const meta = p.closest('.reader-segment')?.querySelector('.segment-meta');
      const pageTitle = p.closest('.book-page')?.querySelector('header')?.innerText;
      return (pageTitle ? pageTitle + '\n' : '') + (meta ? [...meta.children].map(c => c.textContent).join(' · ') + '\n' : '') + originals[i];
    }).join('\n\n');
    try { await navigator.clipboard.writeText(text); document.getElementById('reader-status').textContent = 'Texto copiado.'; }
    catch { document.getElementById('reader-status').textContent = 'No se pudo copiar automáticamente. Selecciona el texto para copiarlo.'; }
  };
  document.getElementById('reader-print').onclick = () => window.print();
  }
  }
  new SharedTextReader();
})();
