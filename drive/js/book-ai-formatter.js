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
    const styles = this.styles.get(element);
    if (!styles) { appendText(element, source); return; }
    const lines = source.replace(/\r\n?/g, '\n').split('\n');
    lines.forEach((line, i) => {
      const block = document.createElement('span'); const style = styles[i] || {};
      block.className = 'book-line';
      block.dataset.kind = ['heading','subtitle','signature'].includes(style.kind) ? style.kind : 'body';
      block.style.textAlign = ['center','right'].includes(style.align) ? style.align : 'justify';
      block.style.fontWeight = style.bold === true ? '700' : '400';
      block.style.setProperty('--write-delay', `${Math.min(i * 25, 3000)}ms`);
      appendText(block, line); element.append(block);
      if (i < lines.length - 1) element.append(document.createTextNode('\n'));
    });
  }
}
