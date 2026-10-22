/**
 * Video poster loader. Prefers the authenticated S3 derivative; when FFmpeg
 * is absent on a lightweight node, decodes just a visible frame in the browser.
 * It never uploads data, starts playback, or bypasses the existing session.
 */
(() => {
  'use strict';
  class VideoPosterLoader {
    constructor(doc) {
      this.doc = doc;
      this.pending = [];
      this.active = 0;
      this.maximum = 2;
      this.queued = new WeakSet();
      this.posters = new Map();
      this.observer = 'IntersectionObserver' in window
        ? new IntersectionObserver(entries => {
            for (const entry of entries) {
              if (entry.isIntersecting) {
                this.observer.unobserve(entry.target);
                this.enqueue(entry.target);
              }
            }
          }, { rootMargin: '100px' })
        : null;
      this.mutation = new MutationObserver(() => this.scan());
      this.scan();
      this.mutation.observe(doc.body || doc.documentElement, { subtree: true, childList: true });
    }
    scan() {
      for (const img of this.doc.querySelectorAll('img[data-video-poster]:not([data-video-queued])')) {
        img.dataset.videoQueued = '1';
        if (this.observer) this.observer.observe(img);
        else this.enqueue(img);
      }
    }
    enqueue(img) {
      if (this.queued.has(img)) return;
      this.queued.add(img);
      this.pending.push(img);
      this.next();
    }
    next() {
      while (this.active < this.maximum && this.pending.length) {
        const img = this.pending.shift();
        if (!img.isConnected) continue;
        this.active++;
        this.resolve(img).catch(() => {
          img.dataset.videoPosterState = 'unavailable';
        }).finally(() => {
          this.active--;
          this.next();
        });
      }
    }
    async resolve(img) {
      const thumb = img.dataset.videoThumb;
      const source = img.dataset.videoSource;
      if (!thumb || !source || !img.isConnected) return;
      const url = await this.posterUrl(thumb, source);
      if (!img.isConnected || !url) return;
      img.src = url;
      img.dataset.videoPosterState = 'ready';
    }
    posterUrl(thumb, source) {
      if (!thumb || !source) return Promise.resolve(null);
      const key = thumb + '|' + source;
      if (this.posters.has(key)) return this.posters.get(key);
      const pending = (async () => {
        let url = null;
        try {
          const response = await fetch(thumb, { credentials: 'same-origin', cache: 'no-store' });
          if (response.ok && response.headers.get('X-Thumb-Status') !== 'FALLBACK'
              && (response.headers.get('Content-Type') || '').includes('image/jpeg')) {
            const blob = await response.blob();
            if (blob.size > 0 && blob.size <= 1024 * 1024) url = URL.createObjectURL(blob);
          }
        } catch (_) { /* Try browser frame decoding. */ }
        return url || this.capture(source);
      })();
      this.posters.set(key, pending);
      pending.then(url => {
        if (!url) this.posters.delete(key);
      }, () => this.posters.delete(key));
      return pending;
    }
    capture(source) {
      return new Promise(resolve => {
        const video = this.doc.createElement('video');
        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';
        video.crossOrigin = 'anonymous';
        video.style.display = 'none';
        let done = false;
        const timeout = setTimeout(() => finish(null), 10000);
        const finish = value => {
          if (done) return;
          done = true;
          clearTimeout(timeout);
          video.removeAttribute('src');
          video.load();
          video.remove();
          resolve(value);
        };
        const snap = () => {
          if (!video.videoWidth || !video.videoHeight) return;
          try {
            const canvas = this.doc.createElement('canvas');
            canvas.width = 160;
            canvas.height = 120;
            const context = canvas.getContext('2d', { alpha: false });
            if (!context) { finish(null); return; }
            context.fillStyle = '#071426';
            context.fillRect(0, 0, 160, 120);
            const ratio = Math.min(160 / video.videoWidth, 120 / video.videoHeight);
            const w = video.videoWidth * ratio;
            const h = video.videoHeight * ratio;
            context.drawImage(video, (160-w)/2, (120-h)/2, w, h);
            canvas.toBlob(blob => finish(blob ? URL.createObjectURL(blob) : null), 'image/jpeg', 0.74);
          } catch (_) { finish(null); }
        };
        video.addEventListener('loadeddata', snap, { once: true });
        video.addEventListener('error', () => finish(null), { once: true });
        video.addEventListener('loadedmetadata', () => {
          if (Number.isFinite(video.duration) && video.duration > 0.3) {
            try { video.currentTime = Math.min(1, video.duration / 4); }
            catch (_) { /* Initial frame instead. */ }
          }
        }, { once: true });
        video.addEventListener('seeked', snap, { once: true });
        video.src = source;
        (this.doc.body || this.doc.documentElement).append(video);
        video.load();
      });
    }
  }
  const start = () => {
    if (!document.body || window.ArcadeVideoPosters) return;
    const instance = new VideoPosterLoader(document);
    window.ArcadeVideoPosters = {
      posterUrl: (thumb, source) => instance.posterUrl(thumb, source)
    };
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
