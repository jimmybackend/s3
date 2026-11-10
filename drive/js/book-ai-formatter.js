/* Only labels from the server are accepted; text always comes from the loaded document. */
class BookAiFormatter {
  constructor(dialog, pages, repaint) {
    this.dialog = dialog; this.pages = pages; this.repaint = repaint;
    this.states = new WeakMap(); this.styles = new WeakMap(); this.queue = Promise.resolve();
    this.scheduled = false;
  }
  visible() {
    if (this.scheduled) return;
    this.scheduled = true;
    setTimeout(() => {
      this.scheduled = false;
      for (const page of this.pages.filter(p => !p.hidden)) {
        if (this.states.has(page)) continue;
        this.states.set(page, 'queued');
        this.queue = this.queue.then(() => this.format(page)).catch(() => {});
      }
    }, 100);
  }
  async format(page) {
    if (!this.dialog.open || page.hidden) { this.states.delete(page); return; }
    const text = page.querySelector('.book-text');
    const status = document.createElement('div'); status.className = 'book-ai-status';
    status.setAttribute('role', 'status'); status.textContent = 'Preparando el diseño de esta hoja…';
    page.insertBefore(status, text); text.classList.add('book-preparing'); page.setAttribute('aria-busy', 'true');
    try {
      const body = new URLSearchParams({action:'book_ai_layout', page:page.dataset.pageIndex});
      const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 45000);
      let response;
      try { response = await fetch(window.location.href, {method:'POST', body, credentials:'same-origin', signal:controller.signal}); }
      finally { clearTimeout(timeout); }
      const data = await response.json();
      if (!response.ok || data.ok !== true || !Array.isArray(data.styles)) throw new Error('unavailable');
      this.styles.set(text, data.styles); this.states.set(page, 'ready');
      text.classList.add('book-writing'); this.repaint();
      setTimeout(() => text.classList.remove('book-writing'), 3800);
      status.textContent = data.cached ? 'Diseño de hoja recuperado.' : 'Diseño de hoja listo.';
    } catch {
      this.states.set(page, 'fallback');
      status.textContent = 'Vista original · formato IA no disponible.';
    } finally { text.classList.remove('book-preparing'); page.removeAttribute('aria-busy'); }
  }
  render(element, source, appendText) {
    const styles = this.styles.get(element) || [];
    const lines = source.replace(/\r\n?/g, '\n').split('\n');
    let paragraph = null;
    let previous = '';
    let previousKind = '';
    const sentenceEnd = value => /[.!?][”’"')\]]*\s*$/u.test(value)
      && !/\b(?:Lic|Dr|Dra|Sr|Sra|C|Art|Núm|No|Exp|Av)\.\s*$/iu.test(value);
    lines.forEach((line, i) => {
      const style = styles[i] || {};
      const allCaps = /\p{Lu}/u.test(line) && !/\p{Ll}/u.test(line);
      let kind = ['heading','subtitle','signature'].includes(style.kind) ? style.kind : 'body';
      if (allCaps && paragraph && previousKind === 'body' && !sentenceEnd(previous) && style.paragraph_start !== true) kind = 'body';
      if (allCaps && (!paragraph || previousKind === 'heading')) kind = 'heading';
      const section = /^(?:EXPEDIENTE|SENTENCIA|RESULTANDO|CONSIDERANDO|RESUELVE|OFICIO|TOCA)\b|^\d+[.)-]\s/u.test(line.trim());
      const boundary = !paragraph || style.paragraph_start === true || section || kind !== previousKind
        || (style.paragraph_start !== false && sentenceEnd(previous));
      if (line.trim() !== '') {
        if (boundary) {
          paragraph = document.createElement('span');
          paragraph.className = 'book-line book-paragraph';
          paragraph.dataset.kind = kind;
          paragraph.style.textAlign = kind === 'body' ? 'justify' : (['center','right'].includes(style.align) ? style.align : 'left');
          paragraph.style.setProperty('--write-delay', `${Math.min(i * 25, 3000)}ms`);
          element.append(paragraph);
        }
        const fragment = document.createElement('span');
        fragment.className = 'book-source-line';
        fragment.style.fontWeight = style.bold === true ? '700' : '400';
        appendText(fragment, line); this.emphasizeCaps(fragment); paragraph.append(fragment);
        previous = line; previousKind = kind;
      } else {
        // Preserve original whitespace in textContent, without creating oversized empty blocks.
        appendText(element, line); paragraph = null; previous = ''; previousKind = '';
      }
      if (i < lines.length - 1) {
        const newline = document.createTextNode('\n');
        if (paragraph) paragraph.append(newline); else element.append(newline);
      }
    });
  }

  emphasizeCaps(element) {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    for (const node of nodes) {
      const value = node.nodeValue;
      const fragment = document.createDocumentFragment();
      let cursor = 0;
      for (const match of value.matchAll(/[\p{L}\p{M}\p{N}]+/gu)) {
        const word = match[0];
        if (!/\p{Lu}/u.test(word) || /\p{Ll}/u.test(word)) continue;
        fragment.append(document.createTextNode(value.slice(cursor, match.index)));
        const strong = document.createElement('strong'); strong.className = 'book-uppercase'; strong.textContent = word;
        fragment.append(strong); cursor = match.index + word.length;
      }
      if (cursor === 0) continue;
      fragment.append(document.createTextNode(value.slice(cursor)));
      node.replaceWith(fragment);
    }
  }
}
