/* Authorized SO action components run in the current 3D document. */
(() => {
  'use strict';
  class Drive3DActions {
    constructor(win, doc) {
      this.window=win; this.document=doc;
      this.toggle=doc.querySelector('[data-hud-more-actions]');
      this.list=doc.querySelector('[data-hud-extra-actions]');
      this.generation=0; this.expanded=false; this.assets=new Map();
    }
    init() {
      if (!this.toggle || !this.list) return this;
      this.document.addEventListener('drive3d:selection', event=>this.select(event.detail));
      this.toggle.addEventListener('click',()=>{
        this.expanded=!this.expanded;
        this.toggle.setAttribute('aria-expanded',String(this.expanded));
        this.list.hidden=!this.expanded;
        if(this.expanded) this.render();
      });
      ['drive:storage-changed','drive:folder-mutated','filesystem:changed','arcadeos:explorer-updated'].forEach(name=>{
        this.document.addEventListener(name,()=>{
          this.window.clearTimeout(this.refreshTimer);
          this.refreshTimer=this.window.setTimeout(()=>this.refreshInventory(),250);
        });
      });
      this.select({type:'folder',name:'Carpeta actual',openHref:this.window.location.href});
      return this;
    }
    select(item) {
      const identity=item?.type==='folder'?item.openHref:item?.key;
      const previous=this.item?.type==='folder'?this.item.openHref:this.item?.key;
      this.item=item;
      if(identity===previous) return;
      this.generation++;
      this.request?.abort();
      if(this.expanded) this.render();
    }
    target(item) {
      const url=new URL('so.php',this.window.location.href);
      url.searchParams.set('_drive3d_actions','native');
      const route=item.type==='folder'
        ? new URL(item.openHref||this.window.location.href,this.window.location.href).searchParams.get('ruta')
        : item.route||this.window.DRIVE_INITIAL_ROUTE;
      url.searchParams.set('ruta',route||this.window.DRIVE_INITIAL_ROUTE||'');
      if(item.type!=='folder') url.searchParams.set('buscar',item.name||'');
      return url;
    }
    asset(src, style=false) {
      const url=new URL(src,this.window.location.href);
      const allowedLocal=url.origin===this.window.location.origin && /^\/(?:.*\/)?(?:js|css)\/[\w.-]+$/.test(url.pathname);
      const allowedCdn=['https://code.jquery.com/jquery-3.5.1.min.js','https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js','https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css'].includes(url.href);
      if(!allowedLocal&&!allowedCdn) return Promise.reject(new Error('Recurso de acciones no permitido.'));
      if(this.assets.has(url.href)) return this.assets.get(url.href);
      const promise=new Promise((resolve,reject)=>{
        const node=this.document.createElement(style?'link':'script');
        if(style){node.rel='stylesheet';node.href=url.href;}
        else{
          node.src=url.href;
          for(const marker of ['background-task-feedback','polly-background','transcribe-background']){
            if(url.pathname.endsWith(marker+'.js'))node.setAttribute('data-'+marker,'');
          }
        }
        node.onload=resolve;
        node.onerror=()=>{node.remove();this.assets.delete(url.href);reject(new Error('No se pudo cargar '+url.pathname));};
        this.document.head.append(node);
      });
      this.assets.set(url.href,promise);
      return promise;
    }
    async runtime(payload) {
      if(this.boot) return this.boot;
      this.boot=(async()=>{
        const context=payload.context;
        this.window.ARCADECLOUD_DRIVE3D_NATIVE_ACTIONS=true;
        this.window.DRIVE_UPLOAD_CSRF=context.csrf;
        this.window.AWS_BUCKET_NAME=context.bucket;
        this.window.ARCADECLOUD_OS_ROOT_ROUTE=context.rootRoute;
        this.window.ARCADECLOUD_OS_CURRENT_FOLDER=context.folder;
        if(!this.root){
          this.root=this.document.createElement('div');
          this.root.id='osDesktop'; this.root.className='dw-native-actions';
          this.document.body.append(this.root);
          const parsed=new DOMParser().parseFromString(payload.html,'text/html');
          parsed.querySelectorAll('.modal,#fileContextMenu,#folderContextMenu').forEach(node=>this.root.append(node));
        }
        await this.asset('https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css',true);
        await this.asset('css/so.css',true);
        if(!this.window.jQuery) await this.asset('https://code.jquery.com/jquery-3.5.1.min.js');
        if(!this.window.jQuery?.fn?.modal) await this.asset('https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js');
        for(const src of payload.scripts) await this.asset(src);
        if(!this.window.ArcadeCloudDesktop || !this.window.ArcadeCloudOsShell) throw new Error('No se pudieron preparar las acciones.');
        // The 3D panorama uses its existing image/preference pipeline.
        this.window.ArcadeCloudOsAppearance={setWallpaper:()=>{
          const app=this.window.ArcadeCloudDrive3D,item=this.item;
          if(item?.environmentHref&&!item.locked){app.chooseBackground(item.key,item.environmentHref);app.useChosenBackground('glass');}
        }};
      })();
      try{await this.boot;}catch(error){this.boot=null;throw error;}
    }
    async installExplorer(html) {
      const parsed=new DOMParser().parseFromString(html,'text/html');
      const explorer=parsed.querySelector('.os-explorer-window');
      if(!explorer) throw new Error('La carpeta ya no está disponible.');
      explorer.querySelectorAll('script').forEach(node=>node.remove());
      if(this.explorer){
        const desktop=this.window.ArcadeCloudDesktop;
        const id=this.explorer.dataset.windowId;
        if(!await desktop.manager.requestClose(this.explorer))throw new Error('No se pudo liberar el contexto anterior.');
        desktop.explorers.delete(id);
      }
      explorer.setAttribute('data-drive3d-action-context','');
      this.root.append(explorer); this.explorer=explorer;
      this.window.ArcadeCloudDesktop.attachExplorer(explorer);
      this.window.ArcadeCloudDesktop.manager.open(explorer);
      this.window.ArcadeCloudOsShell.bindFiles(explorer);
      this.window.ArcadeCloudOsFolders.rebind(explorer);
      this.window.ArcadeCloudOsClipboard?.bindEntries(explorer);
    }
    context(item) {
      let menu;
      if(item.type==='folder'){
        const service=this.window.ArcadeCloudOsFolders;
        const folder=service.currentFolderFromDom(this.explorer);
        const normalize=route=>String(route||'').replace(/^\/+|\/+$/g,'');
        if(normalize(folder.route)!==normalize(this.target(item).searchParams.get('ruta')))throw new Error('Vuelve a seleccionar la carpeta.');
        service.showContext(folder,8,8,true);
        menu=this.document.getElementById('folderContextMenu');
      }else{
        const entry=Array.from(this.explorer.querySelectorAll('.os-file-entry')).find(node=>node.dataset.key===item.key);
        if(!entry||!item.key)throw new Error('El archivo ya no está disponible. Actualiza la carpeta.');
        this.window.ArcadeCloudOsShell.activateWindow(this.explorer);
        this.window.ArcadeCloudOsShell.showContext(entry,8,8);
        menu=this.document.getElementById('fileContextMenu');
      }
      menu.hidden=true;
      return Array.from(menu.querySelectorAll('button[data-file-action],button[data-folder-action],button[data-os-clipboard-action]')).filter(button=>!button.hidden&&!button.disabled);
    }
    async render() {
      const item=this.item,generation=this.generation;
      this.request?.abort(); this.request=new AbortController();
      this.list.textContent='Cargando acciones…';
      try{
        const response=await this.window.fetch(this.target(item),{credentials:'same-origin',cache:'no-store',signal:this.request.signal,headers:{Accept:'application/json'}});
        if(!response.ok)throw new Error('No se pudieron cargar las acciones.');
        const payload=await response.json();
        if(!payload.ok||!payload.context||!Array.isArray(payload.scripts))throw new Error('Abre tu sesión de Drive para acceder a las acciones.');
        if(generation!==this.generation)return;
        await this.runtime(payload);
        if(generation!==this.generation||!this.expanded)return;
        await this.installExplorer(payload.html);
        if(generation!==this.generation||!this.expanded)return;
        this.list.replaceChildren();
        for(const control of this.context(item)){
          const button=this.document.createElement('button');button.type='button';button.textContent=control.textContent.trim();
          button.addEventListener('click',()=>{
            if(generation!==this.generation)return;
            try{
              if(!this.context(item).includes(control))throw new Error('Esta acción ya no está disponible.');
              control.click();
            }catch(error){this.list.textContent=error.message;}
          });
          this.list.append(button);
        }
      }catch(error){if(error.name!=='AbortError'&&generation===this.generation)this.list.textContent=error.message;}
    }
    async refreshInventory() {
      const app=this.window.ArcadeCloudDrive3D;
      const state=await app?.loadCurrentFolderFiles?.();
      if(state&&!state.error)app.three?.setCurrentFiles?.(state.files||[]);
      if(this.expanded)this.render();
      this.document.dispatchEvent(new CustomEvent('background-tasks:refresh'));
    }
  }
  window.addEventListener('DOMContentLoaded',()=>{window.ArcadeCloudDrive3DActions=new Drive3DActions(window,document).init();});
})();
