/* ArcadeCloud OS multi-window runtime. Deliberately framework-free so Drive's
 * existing controllers/endpoints remain the single source of storage truth. */
class ArcadeCloudEventBus extends EventTarget {
  emit(type, detail = {}) { this.dispatchEvent(new CustomEvent(type, { detail })); }
  on(type, listener, options) { this.addEventListener(type, listener, options); return () => this.removeEventListener(type, listener, options); }
}

class ArcadeCloudWindowManager {
  constructor(win, doc, bus) {
    this.window = win; this.document = doc; this.bus = bus;
    this.registry = new Map(); this.apps = new Map(); this.counter = 0; this.zBase = 200; this.zLimit = 900;
  }
  registerApp(name, definition) { this.apps.set(name, Object.assign({ multiInstance: false }, definition)); }
  nextId(app) { this.counter += 1; return app + '-' + this.counter; }
  register(element, app = 'window', state = {}) {
    if (!element) return null;
    let id = element.dataset.windowId;
    if (!id) { id = this.nextId(app); element.dataset.windowId = id; }
    element.dataset.appId = app;
    const record = this.registry.get(id) || { id, app, element, state, cleanup: new Set() };
    record.element = element; record.state = state || record.state; this.registry.set(id, record);
    this.bus.emit('window-opened', { windowId: id, app });
    return record;
  }
  addCleanup(id, callback) { const record = this.registry.get(id); if (record && typeof callback === 'function') record.cleanup.add(callback); }
  focus(element) {
    const record = this.record(element); if (!record) return;
    const ordered = [...this.registry.values()].filter((item) => item.element?.isConnected && item.element.classList.contains('is-open'));
    if (++this.zBase >= this.zLimit) { ordered.sort((a, b) => (+a.element.style.zIndex || 0) - (+b.element.style.zIndex || 0)).forEach((item, i) => { item.element.style.zIndex = String(200 + i); }); this.zBase = 200 + ordered.length; }
    ordered.forEach((item) => item.element.classList.toggle('is-active', item.id === record.id));
    element.style.zIndex = String(++this.zBase); this.bus.emit('window-focused', { windowId: record.id, app: record.app });
  }
  record(elementOrId) { const id = typeof elementOrId === 'string' ? elementOrId : elementOrId?.dataset?.windowId; return id ? this.registry.get(id) : null; }
  close(element) {
    const record = this.record(element); if (!record) return;
    record.cleanup.forEach((fn) => { try { fn(); } catch (_) {} }); record.cleanup.clear();
    this.registry.delete(record.id); this.bus.emit('window-closed', { windowId: record.id, app: record.app });
  }
}

class ArcadeCloudExplorerWindow {
  constructor(runtime, win, route) {
    this.runtime = runtime; this.window = win; this.id = win.dataset.windowId;
    this.live = win.querySelector('.os-explorer-live'); this.history = []; this.future = [];
    this.route = route || this.live?.dataset.explorerRoute || runtime.root; this.page = 1;
    this.selection = new Set(); this.controller = null; this.sequence = 0; this.cleanup = [];
    this.installChrome(); this.bind(); this.updateTitle();
  }
  normalize(value) {
    let path = String(value || '').trim().replace(/\\/g, '/');
    if (/\0|(^|\/)\.\.?(\/|$)/.test(path)) throw new Error('La ruta contiene segmentos no permitidos.');
    path = path.replace(/\/+/g, '/');
    const root = this.runtime.root.replace(/^\/+|\/+$/g, '');
    if (path === '/' || path === '') return root + '/';
    path = path.replace(/^\/+/, '').replace(/\/+$/, '');
    if (path !== root && !path.startsWith(root + '/')) path = root + '/' + path;
    return path.replace(/\/+/g, '/') + '/';
  }
  virtual(route = this.route) {
    const root = this.runtime.root.replace(/^\/+|\/+$/g, ''); let path = String(route).replace(/^\/+|\/+$/g, '');
    if (path === root) return '/'; if (path.startsWith(root + '/')) path = path.slice(root.length + 1); return '/' + path;
  }
  installChrome() {
    if (!this.live) return;
    const row = this.live.querySelector('.os-explorer-pathrow'); if (!row) return;
    row.replaceChildren(); row.classList.add('os-explorer-navigation');
    const buttons = [['back','fa-arrow-left','Atrás'],['forward','fa-arrow-right','Adelante'],['up','fa-arrow-up','Subir'],['refresh','fa-rotate','Actualizar']];
    buttons.forEach(([action, icon, label]) => { const b = document.createElement('button'); b.type='button'; b.className='os-address-action'; b.dataset.explorerAction=action; b.title=label; b.setAttribute('aria-label',label); b.innerHTML='<i class="fas '+icon+'"></i>'; row.append(b); });
    const address = document.createElement('div'); address.className='os-address os-address-editable'; address.dataset.explorerAddress='';
    address.innerHTML='<i class="fas fa-folder"></i><div class="os-breadcrumb" data-explorer-breadcrumb></div><input type="text" data-explorer-address-input aria-label="Dirección de carpeta" autocomplete="off" spellcheck="false" hidden>';
    row.append(address); this.address = address.querySelector('input'); this.breadcrumb = address.querySelector('[data-explorer-breadcrumb]'); this.renderAddress();
  }
  renderAddress() {
    if (!this.breadcrumb) return; const virtual = this.virtual(); this.breadcrumb.replaceChildren();
    const parts = virtual.split('/').filter(Boolean); const root = document.createElement('button'); root.type='button'; root.textContent='Mi Drive'; root.dataset.addressRoute='/'; this.breadcrumb.append(root);
    let accumulated=''; parts.forEach((part) => { accumulated += '/' + part; const sep=document.createElement('span'); sep.textContent='›'; const b=document.createElement('button'); b.type='button'; b.textContent=part; b.dataset.addressRoute=accumulated; this.breadcrumb.append(sep,b); });
    if (this.address) this.address.value = virtual;
  }
  editAddress() { if (!this.address) return; this.breadcrumb.hidden=true; this.address.hidden=false; this.address.value=this.virtual(); this.address.focus(); this.address.select(); }
  stopEditing() { if (!this.address) return; this.address.hidden=true; this.breadcrumb.hidden=false; }
  bind() {
    const click = (event) => {
      const target = event.target instanceof Element ? event.target : null; if (!target) return;
      const action=target.closest('[data-explorer-action]')?.dataset.explorerAction;
      if (action) { event.preventDefault(); if(action==='back')this.back(); else if(action==='forward')this.forward(); else if(action==='up')this.up(); else this.navigate(this.route,{replace:true,page:this.page}); return; }
      const crumb=target.closest('[data-address-route]'); if(crumb){event.preventDefault();this.navigate(crumb.dataset.addressRoute);return;}
      if(target.closest('[data-explorer-address]')) { if(!target.closest('[data-address-route]')) this.editAddress(); return; }
      const link=target.closest('a[data-explorer-route]'); if(link){event.preventDefault();this.navigate(link.dataset.explorerRoute,{page:+link.dataset.explorerPage||1});return;}
      const folder=target.closest('.os-folder-entry'); if(folder && event.detail===2){event.preventDefault();this.navigate(folder.dataset.folderRoute);}
    };
    const keydown=(event)=>{ if(event.target===this.address){if(event.key==='Enter'){event.preventDefault();try{this.navigate(this.address.value);}catch(error){this.runtime.notify(error.message,'warning');}}else if(event.key==='Escape'){this.stopEditing();}return;}if(event.target.closest('input,textarea,[contenteditable]'))return;const selected=[...this.win.querySelectorAll('.os-file-entry.is-selected')]; if(event.ctrlKey&&event.key.toLowerCase()==='l'){event.preventDefault();this.editAddress();}else if(event.altKey&&event.key==='ArrowLeft'){event.preventDefault();this.back();}else if(event.altKey&&event.key==='ArrowRight'){event.preventDefault();this.forward();}else if(event.ctrlKey&&['c','x'].includes(event.key.toLowerCase())&&selected.length){event.preventDefault();window.ArcadeCloudOsClipboard?.captureFiles?.(selected[0],event.key.toLowerCase()==='c'?'copy':'move');}else if(event.ctrlKey&&event.key.toLowerCase()==='v'){event.preventDefault();window.ArcadeCloudOsClipboard?.paste?.(this.route);}else if(event.key==='Delete'&&selected.length){event.preventDefault();window.ArcadeCloudOsShell?.deleteSelectedFiles?.();}else if(event.key==='Enter'&&selected.length===1){event.preventDefault();window.ArcadeCloudOsShell?.openFileEntry?.(selected[0],false);}else if(event.key==='Backspace'){event.preventDefault();this.back();}};
    this.win.addEventListener('click',click); this.win.addEventListener('keydown',keydown); this.cleanup.push(()=>this.win.removeEventListener('click',click),()=>this.win.removeEventListener('keydown',keydown));
    this.bindDragDrop();
  }
  async navigate(value, options={}) {
    const route=this.normalize(value); if(!options.fromHistory && !options.replace && route!==this.route){this.history.push({route:this.route,page:this.page,scroll:this.live?.scrollTop||0});this.future=[];}
    this.controller?.abort(); this.controller=new AbortController(); const request=++this.sequence; this.win.classList.add('is-loading');
    try { const url=new URL('so.php',location.href); url.searchParams.set('ruta',route); url.searchParams.set('pagina',String(options.page||1)); url.searchParams.set('_os_fragment','explorer');
      const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:this.controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}}); if(!response.ok)throw new Error('No fue posible cargar esta carpeta.');
      const parsed=new DOMParser().parseFromString(await response.text(),'text/html'); const replacement=parsed.querySelector('#osExplorerLive'); if(!replacement)throw new Error('La respuesta del explorador no es válida.'); if(request!==this.sequence)return;
      replacement.removeAttribute('id'); replacement.dataset.explorerInstance=this.id; replacement.querySelectorAll('.os-file-entry,.os-folder-entry').forEach(entry=>entry.draggable=true); this.live.replaceWith(replacement); this.live=replacement; this.route=route; this.page=+replacement.dataset.explorerPage||1; this.installChrome(); this.renderAddress(); this.updateTitle();
      window.ArcadeCloudOsShell?.bindFiles?.(); window.ArcadeCloudOsClipboard?.bindEntries?.(); window.ArcadeCloudOsClipboard?.injectPasteToolbar?.(); window.ArcadeCloudOsFolders?.bindEntries?.();
      this.runtime.bus.emit('route-changed',{windowId:this.id,route});
    } catch(error){if(error?.name!=='AbortError'){this.showError(error?.message||'No fue posible cargar esta carpeta.');}}
    finally {if(request===this.sequence)this.win.classList.remove('is-loading');}
  }
  showError(message){let box=this.win.querySelector('.os-explorer-error');if(!box){box=document.createElement('div');box.className='os-explorer-error';this.live?.prepend(box);}box.textContent=message;}
  back(){const item=this.history.pop();if(!item)return;this.future.push({route:this.route,page:this.page});this.navigate(item.route,{page:item.page,fromHistory:true,replace:true});}
  forward(){const item=this.future.pop();if(!item)return;this.history.push({route:this.route,page:this.page});this.navigate(item.route,{page:item.page,fromHistory:true,replace:true});}
  up(){const root=this.runtime.root.replace(/\/+$/,'')+'/';if(this.route===root)return;const bits=this.route.replace(/\/+$/,'').split('/');bits.pop();this.navigate(bits.join('/')+'/');}
  updateTitle(){const title='Mis datos — '+this.virtual();this.win.dataset.windowTitle=title;this.win.querySelector('.os-window-title span').textContent=title;window.ArcadeCloudOsShell?.syncTaskbar?.();}
  bindDragDrop(){
    const start=(event)=>{const entry=event.target.closest('.os-file-entry,.os-folder-entry');if(!entry)return;const selected=[...this.win.querySelectorAll('.os-file-entry.is-selected')];const entries=entry.classList.contains('is-selected')&&selected.length?selected:[entry];const payload={sourceWindowId:this.id,sourceRoute:this.route,kind:entry.classList.contains('os-folder-entry')?'folder':'file',keys:entries.map(x=>x.dataset.key||x.dataset.folderRoute).filter(Boolean)};event.dataTransfer.effectAllowed='copyMove';event.dataTransfer.setData('application/x-arcadecloud-items',JSON.stringify(payload));event.dataTransfer.setData('text/plain',entries.length+' elemento(s)');};
    const over=(event)=>{if(!event.dataTransfer.types.includes('application/x-arcadecloud-items'))return;event.preventDefault();this.win.classList.add('is-drop-target');event.dataTransfer.dropEffect=event.ctrlKey?'copy':'move';};
    const leave=(event)=>{if(!this.win.contains(event.relatedTarget))this.win.classList.remove('is-drop-target');};
    const drop=async(event)=>{event.preventDefault();this.win.classList.remove('is-drop-target');let data;try{data=JSON.parse(event.dataTransfer.getData('application/x-arcadecloud-items'));}catch(_){return;}const folder=event.target.closest('.os-folder-entry');const destination=folder?.dataset.folderRoute||this.route;if(!data?.keys?.length)return;const mode=event.ctrlKey?'copy':(this.window.confirm('Aceptar: mover aquí.\nCancelar: copiar aquí.')?'move':'copy');try{if(data.kind==='folder'){window.ArcadeCloudOsClipboard.captureFolder({route:data.keys[0],name:data.keys[0].split('/').filter(Boolean).pop()},mode);}else{const source=this.runtime.explorers.get(data.sourceWindowId);const entries=data.keys.map(k=>source?.win.querySelector('.os-file-entry[data-key="'+CSS.escape(k)+'"]')).filter(Boolean);if(entries.length){entries.forEach(e=>e.classList.add('is-selected'));window.ArcadeCloudOsClipboard.captureFiles(entries[0],mode);}}await window.ArcadeCloudOsClipboard.paste(destination);this.runtime.bus.emit(mode==='copy'?'file-copied':'file-moved',{sourceRoute:data.sourceRoute,destination});}catch(error){this.runtime.notify(error?.message||'No se pudo transferir la selección.','danger');}};
    this.win.querySelectorAll('.os-file-entry,.os-folder-entry').forEach(e=>e.draggable=true); this.win.addEventListener('dragstart',start);this.win.addEventListener('dragover',over);this.win.addEventListener('dragleave',leave);this.win.addEventListener('drop',drop);this.cleanup.push(()=>{this.win.removeEventListener('dragstart',start);this.win.removeEventListener('dragover',over);this.win.removeEventListener('dragleave',leave);this.win.removeEventListener('drop',drop);});
  }
  destroy(){this.controller?.abort();this.cleanup.splice(0).forEach(fn=>fn());}
}

class ArcadeCloudDesktopRuntime {
  constructor(win,doc){this.window=win;this.document=doc;this.bus=new ArcadeCloudEventBus();this.manager=new ArcadeCloudWindowManager(win,doc,this.bus);this.explorers=new Map();this.root=String(win.ARCADECLOUD_OS_ROOT_ROUTE||'');}
  init(){
    document.querySelectorAll('.os-window').forEach(el=>this.manager.register(el,this.appFor(el))); const base=document.getElementById('explorerWindow');if(base)this.attachExplorer(base);
    document.addEventListener('click',(event)=>{const target=event.target instanceof Element?event.target:null;if(!target)return;const launch=target.closest('[data-window-open="explorerWindow"]');if(launch){event.preventDefault();event.stopImmediatePropagation();this.openExplorer(this.root);return;}const newer=target.closest('[data-folder-open-new]');if(newer){event.preventDefault();event.stopPropagation();const route=window.ArcadeCloudOsFolders?.activeFolder?.route||newer.dataset.folderOpenNew;this.openExplorer(route);window.ArcadeCloudOsFolders?.hideContext?.();return;}const tool=target.closest('[data-os-tool]');if(tool){event.preventDefault();this.openTool(tool.href,tool.dataset.toolTitle||tool.textContent.trim());}},true);
    document.addEventListener('pointerdown',(e)=>{const w=e.target.closest('.os-window');if(w)this.manager.focus(w);},true);
    document.addEventListener('click',(e)=>{const close=e.target.closest('[data-window-close]');if(!close)return;const w=close.closest('.os-window');const record=this.manager.record(w);if(record?.app==='explorer'){this.explorers.get(record.id)?.destroy();this.explorers.delete(record.id);}setTimeout(()=>{this.manager.close(w);if(w&&!w.id)w.remove();},0);},true);
    ['file-moved','file-copied','file-deleted','upload-completed','task-completed'].forEach(type=>this.bus.on(type,(e)=>this.refreshAffected(e.detail)));
    document.addEventListener('drive:move-task-completed',(e)=>this.bus.emit('task-completed',e.detail||{}));document.addEventListener('drive:storage-changed',(e)=>this.bus.emit('upload-completed',e.detail||{}));
    this.observer=new MutationObserver((records)=>records.forEach(record=>record.addedNodes.forEach(node=>{if(!(node instanceof Element)||!node.matches('.os-window')||node.dataset.windowId)return;this.manager.register(node,this.appFor(node));})));this.observer.observe(document.getElementById('osDesktop')||document.body,{childList:true});
    this.enhanceFolderMenu(); window.ArcadeCloudEventBus=this.bus;window.ArcadeCloudWindowManager=this.manager;window.ArcadeCloudDesktop=this;return this;
  }
  appFor(el){if(el.id==='explorerWindow'||el.classList.contains('os-explorer-window'))return'explorer';if(el.classList.contains('os-document-window'))return'viewer';return el.id?.replace(/Window$/,'')||'window';}
  attachExplorer(el){const record=this.manager.record(el)||this.manager.register(el,'explorer');el.classList.add('os-explorer-window');const controller=new ArcadeCloudExplorerWindow(this,el,el.querySelector('.os-explorer-live')?.dataset.explorerRoute);this.explorers.set(record.id,controller);this.manager.addCleanup(record.id,()=>controller.destroy());return controller;}
  openExplorer(route){const base=document.getElementById('explorerWindow');if(base&&!base.classList.contains('is-open')&&base.dataset.minimized!=='1'){window.ArcadeCloudOsShell?.openWindow?.('explorerWindow');const controller=this.explorers.get(base.dataset.windowId);if(route&&controller)controller.navigate(route,{replace:true});return controller;}const clone=base.cloneNode(true);clone.removeAttribute('id');clone.querySelector('#osExplorerLive')?.removeAttribute('id');clone.querySelectorAll('[id]').forEach(el=>el.removeAttribute('id'));clone.dataset.windowId='';clone.dataset.minimized='0';clone.classList.add('is-open','os-explorer-window');const offset=(this.explorers.size%7)*26;clone.style.left=(8+offset/14)+'vw';clone.style.top=(7+offset/16)+'vh';document.getElementById('osDesktop')?.append(clone);const record=this.manager.register(clone,'explorer');window.ArcadeCloudOsShell?.registerWindow?.(clone);const controller=this.attachExplorer(clone);if(route&&controller.normalize(route)!==controller.route)controller.navigate(route,{replace:true});this.manager.focus(clone);return controller;}
  enhanceFolderMenu(){const menu=document.getElementById('folderContextMenu');if(!menu||menu.querySelector('[data-folder-open-new]'))return;const button=document.createElement('button');button.type='button';button.dataset.folderOpenNew='';button.innerHTML='<i class="fas fa-up-right-from-square"></i> Abrir en nueva ventana';const open=menu.querySelector('[data-folder-action="open"]');open?.after(button);}
  async openTool(url,title){const el=document.createElement('section');el.className='os-window os-tool-window is-open';el.dataset.windowTitle=title;el.style.cssText='left:14vw;top:9vh;width:min(980px,82vw);height:min(680px,76vh)';el.innerHTML='<div class="os-window-titlebar" data-window-drag-handle><div class="os-window-title"><i class="fas fa-toolbox"></i><span></span></div><div class="os-window-controls"><button type="button" data-window-minimize aria-label="Minimizar"><i class="fas fa-minus"></i></button><button type="button" data-window-maximize aria-label="Maximizar"><i class="far fa-square"></i></button><button type="button" data-window-close aria-label="Cerrar"><i class="fas fa-xmark"></i></button></div></div><div class="os-window-body os-tool-body"><div class="os-tool-loading">Cargando…</div></div>';el.querySelector('.os-window-title span').textContent=title;document.getElementById('osDesktop')?.append(el);const record=this.manager.register(el,'tool');window.ArcadeCloudOsShell?.registerWindow?.(el);const controller=new AbortController();this.manager.addCleanup(record.id,()=>controller.abort());try{const response=await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}});if(!response.ok)throw new Error('No fue posible cargar la herramienta.');const parsed=new DOMParser().parseFromString(await response.text(),'text/html');const content=parsed.querySelector('main,.container,.container-fluid')||parsed.body;content.querySelectorAll('script,link[rel="stylesheet"],nav').forEach(node=>node.remove());const body=el.querySelector('.os-tool-body');body.replaceChildren(...Array.from(content.childNodes).map(node=>document.importNode(node,true)));this.bus.emit('tool-opened',{windowId:record.id,url});}catch(error){if(error?.name!=='AbortError')el.querySelector('.os-tool-body').textContent=error?.message||'No fue posible cargar la herramienta.';}return el;}
  refreshAffected(detail){this.explorers.forEach(explorer=>{if(!detail?.sourceRoute&&!detail?.destination){explorer.navigate(explorer.route,{replace:true,page:explorer.page});return;}if([detail.sourceRoute,detail.destination].includes(explorer.route))explorer.navigate(explorer.route,{replace:true,page:explorer.page});});}
  notify(message,type){window.ArcadeCloudOsShell?.notify?.(message,type);}
}

window.addEventListener('DOMContentLoaded',()=>{window.ArcadeCloudDesktopRuntime=new ArcadeCloudDesktopRuntime(window,document).init();});
