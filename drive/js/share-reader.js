(() => {
  'use strict';
  const content = document.getElementById('reader-content');
  if (!content) return;
  const paragraphs = [...content.querySelectorAll('.reader-text')];
  const originals = paragraphs.map(p => p.textContent);
  const search = document.getElementById('reader-search');
  const matchesLabel = document.getElementById('reader-matches');
  let marks = [], active = -1, size = 17, timer;
  function move(delta) {
    if (!marks.length) return;
    if (active >= 0) marks[active].classList.remove('active');
    active = (active + delta + marks.length) % marks.length;
    marks[active].classList.add('active');
    marks[active].scrollIntoView({block: 'center'});
    matchesLabel.textContent = `${active + 1} de ${marks.length}`;
  }
  function find() {
    marks = []; active = -1;
    const query = search.value.toLocaleLowerCase('es');
    paragraphs.forEach((p, i) => {
      p.textContent = '';
      const source = originals[i];
      if (!query) { p.textContent = source; return; }
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
  search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(find, 180); });
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
    const text = paragraphs.map((p, i) => {
      const meta = p.closest('.reader-segment')?.querySelector('.segment-meta');
      return (meta ? [...meta.children].map(c => c.textContent).join(' · ') + '\n' : '') + originals[i];
    }).join('\n\n');
    try { await navigator.clipboard.writeText(text); document.getElementById('reader-status').textContent = 'Texto copiado.'; }
    catch { document.getElementById('reader-status').textContent = 'No se pudo copiar automáticamente. Selecciona el texto para copiarlo.'; }
  };
  document.getElementById('reader-print').onclick = () => window.print();
})();
