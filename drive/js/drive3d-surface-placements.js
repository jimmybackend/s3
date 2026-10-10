/* Three.js surfaces for user-owned pictures in ArcadeCloud Drive 3D.
 * A picture is a real material in the room, not a screen-space decoration.
 * No external storage or permissions are bypassed: the authenticated viewer
 * URL supplied by dataword3d.php is the image source. */
class Drive3DSurfacePlacements {
  constructor(T, scene, camera, radius, callbacks = {}) {
    this.T = T;
    this.scene = scene;
    this.camera = camera;
    this.radius = radius;
    this.callbacks = callbacks;
    this.placements = new Map();
    this.pending = null;
    this.ribs = 16;
    // Four glass bands above the shelves, plus the upper roof. Every
    // azimuth section is valid all around the 360-degree dome.
    // Exactly the physical timber horizontal beams in drive3d-scene.js:
    // 0.24, 0.47, 0.72 and 1.05 radians. Never fabricate invisible
    // frame edges: artwork and the pane must share the SAME boundaries.
    this.bands = [0, .24, .47, .72, 1.05];
  }

  static mode(mode) {
    return ['floor','window','ceiling'].includes(mode) ? mode : 'free';
  }

  static scale(value, mode) {
    const n = Number(value);
    return Math.min(mode === 'floor' ? 2.6 : .94, Math.max(.30, Number.isFinite(n) ? n : .70));
  }

  static fitMode(value) {
    return value === 'cover' ? 'cover' : 'poster';
  }

  static aspect(value) {
    const aspect = Number(value);
    // Preserve genuine portraits and panoramic photographs, not only 4:3.
    return Number.isFinite(aspect) && aspect > 0
      ? Math.max(.05, Math.min(20, aspect)) : 4 / 3;
  }

  /** Largest proportional rectangle that can fit inside the pane.
   * Neither mode can crop or stretch the original photo. On a differently
   * shaped pane, leftover glass remains visible, framed by the REAL wood.
   * 'poster' leaves a narrow protective border; 'cover' uses the whole
   * clear aperture (the maximum physically possible without losing pixels).
   */
  static fitImage(aspectInput, maxWidth, maxHeight, fitInput = 'poster') {
    const aspect = Drive3DSurfacePlacements.aspect(aspectInput);
    const mode = Drive3DSurfacePlacements.fitMode(fitInput);
    const boxWidth = Math.max(.001, Number(maxWidth) || 0);
    const boxHeight = Math.max(.001, Number(maxHeight) || 0);
    const padding = mode === 'cover' ? .997 : .978;
    let width = boxWidth * padding;
    let height = width / aspect;
    if (height > boxHeight * padding) {
      height = boxHeight * padding;
      width = height * aspect;
    }
    return {width,height,cropU:0,cropV:0,mode};
  }

  static panelId(mode, sector, band = 0) {
    return (mode === 'ceiling' ? 'c' : 'w') + '-' + sector + '-' + band;
  }

  static panelFromId(mode, value) {
    const match = /^(w|c)-(\d{1,2})-(\d)$/.exec(String(value || ''));
    if (!match || (mode === 'ceiling' ? match[1] !== 'c' || match[3] !== '0' :
      match[1] !== 'w' || Number(match[3]) > 3)) return null;
    const sector = Number(match[2]);
    if (sector >= 16) return null;
    return { sector, band:Number(match[3]) };
  }

  /** Classify the exact section between adjacent wooden dome ribs. */
  hit(ray, mode) {
    const T = this.T;
    if (mode === 'floor') {
      const point = ray.intersectPlane(new T.Plane(new T.Vector3(0,1,0), -.06),new T.Vector3());
      if (!point || Math.hypot(point.x,point.z) > this.radius - .35) return null;
      return {mode:'floor',world:[point.x,.065,point.z],panelId:''};
    }
    const point = ray.intersectSphere(new T.Sphere(new T.Vector3(0,0,0),this.radius-.16),new T.Vector3());
    if (!point) return null;
    const elevation = Math.atan2(point.y,Math.hypot(point.x,point.z));
    if (mode === 'window' && (elevation < .01 || elevation > 1.05)) return null;
    // Starting the roof near elevation 1.05 keeps it reachable when the
    // visitor looks up from the near side of the dome (not just its centre).
    if (mode === 'ceiling' && elevation < 1.05) return null;
    const step = Math.PI*2/this.ribs;
    const angle = (Math.atan2(point.x,point.z) + Math.PI*2) % (Math.PI*2);
    const sector = Math.floor(angle/step) % this.ribs;
    const band = mode === 'window'
      ? Math.max(0,Math.min(3,this.bands.findIndex((limit,index)=>index < this.bands.length-1 && elevation < this.bands[index+1])))
      : 0;
    const panelId = Drive3DSurfacePlacements.panelId(mode,sector,band);
    return { mode,world:point.toArray(),panelId };
  }

  prepare(id,mode) {
    if (Drive3DSurfacePlacements.mode(mode) === 'free') return false;
    this.pending = {id:String(id),mode};
    return true;
  }

  cancel() { this.pending = null; }

  choose(ray) {
    if (!this.pending) return false;
    const {id,mode} = this.pending;
    const placement = this.hit(ray,mode);
    if (!placement) {
      this.callbacks.onHint?.('Toca ' + (mode === 'floor' ? 'el piso' : mode === 'ceiling' ? 'la parte superior del domo' : 'una ventana entre las costillas de madera') + '.');
      return true; // do not select a shelf while the user chooses a surface
    }
    const previous = this.placements.get(id)?.entry || {};
    const next = {
      id, openHref:previous.openHref || '',
      mode:placement.mode, panelId:placement.panelId,
      world:placement.world,
      surfaceScale:Drive3DSurfacePlacements.scale(previous.surfaceScale,mode),
      surfaceFit:Drive3DSurfacePlacements.fitMode(previous.surfaceFit)
    };
    this.pending = null;
    // Rendering is performed by the owner after app metadata is synchronized.
    this.callbacks.onPlaced?.(next);
    return true;
  }

  /** Place a correctly proportioned print INSIDE one wooden cell.
   * The pane is curved, but its artwork uses the photo's true width/height
   * ratio in physical tangent-plane dimensions at the panel midpoint. */
  domePatch(entry) {
    const T = this.T;
    const {mode} = entry;
    const info = Drive3DSurfacePlacements.panelFromId(mode,entry.panelId);
    if (!info) return null;
    const slice = Math.PI * 2 / this.ribs;
    // Old installations stored .70 as the default. Map that old slider
    // position to an almost full-size print, without discarding intentional
    // smaller values; the ± buttons can still shrink the poster.
    const scale = Drive3DSurfacePlacements.scale(entry.surfaceScale,mode);
    const paneScale = Math.min(1, scale / .72);
    // Artwork sits just on the INNER face of the dome, ahead of the glass
    // panorama and below the wooden frame (so there is no milky overlay).
    const rad = this.radius - .08;
    const midAngle = (info.sector+.5) * slice;
    const bounds = mode === 'ceiling'
      ? [1.075,1.545]
      : [this.bands[info.band]+.012,this.bands[info.band+1]-.012];
    const elevationCenter = (bounds[0]+bounds[1]) / 2;
    // Measure the ACTUAL wooden cell. Prior .84 * .70 scaling used less
    // than 60% of the available width, even in 'cover' mode.
    const maxWidth = rad * Math.max(.001,Math.cos(elevationCenter)) * slice * .985 * paneScale;
    const maxHeight = rad * (bounds[1]-bounds[0]) * .985 * paneScale;
    const fit = Drive3DSurfacePlacements.fitImage(entry.aspect,maxWidth,maxHeight,entry.surfaceFit);
    const longitudeHalf = fit.width / (2 * rad * Math.max(.001,Math.cos(elevationCenter)));
    const elevationHalf = fit.height / (2 * rad);
    const segmentsX = 12,segmentsY = 8;
    const vertices=[],uv=[],indices=[];
    for(let row=0;row<=segmentsY;row++){
      const v=row/segmentsY;
      const elev=elevationCenter+(v*2-1)*elevationHalf;
      for(let col=0;col<=segmentsX;col++){
        const u=col/segmentsX,angle=midAngle+(u*2-1)*longitudeHalf;
        vertices.push(rad*Math.cos(elev)*Math.sin(angle),rad*Math.sin(elev),rad*Math.cos(elev)*Math.cos(angle));
        // Inside-viewed dome: increasing longitude points to the viewer's
        // LEFT, so mirror U only in the geometry. This corrects the photo's
        // visual handedness without reversing the original image or pixels.
        uv.push(1 - (fit.cropU+u*(1-2*fit.cropU)),fit.cropV+v*(1-2*fit.cropV));
      }
    }
    for(let row=0;row<segmentsY;row++)for(let col=0;col<segmentsX;col++){
      const i=row*(segmentsX+1)+col;
      indices.push(i,i+segmentsX+1,i+1,i+1,i+segmentsX+1,i+segmentsX+2);
    }
    const geometry=new T.BufferGeometry();
    geometry.setAttribute('position',new T.Float32BufferAttribute(vertices,3));
    geometry.setAttribute('uv',new T.Float32BufferAttribute(uv,2));
    geometry.setIndex(indices);
    geometry.computeVertexNormals();
    const p=new T.Vector3(rad*Math.cos(elevationCenter)*Math.sin(midAngle),
      rad*Math.sin(elevationCenter),rad*Math.cos(elevationCenter)*Math.cos(midAngle));
    return {geometry,point:p,fit};
  }

  set(entry) {
    const T=this.T;
    const mode=Drive3DSurfacePlacements.mode(entry?.mode);
    if (!entry?.id || mode === 'free') {this.remove(entry?.id);return null;}
    const id=String(entry.id);
    const previous=this.placements.get(id);
    const scale=Drive3DSurfacePlacements.scale(entry.surfaceScale,mode);
    const surfaceFit=Drive3DSurfacePlacements.fitMode(entry.surfaceFit);
    let geometry,point,fit;
    if(mode === 'floor'){
      const coordinates=Array.isArray(entry.world) ? entry.world : [0,.065,0];
      const x=Number(coordinates[0]) || 0,z=Number(coordinates[2]) || 0;
      if(Math.hypot(x,z)>this.radius-.35) return null;
      // Floor wallpaper follows the same uncut, proportional logic.
      fit=Drive3DSurfacePlacements.fitImage(entry.aspect,3.0*scale,3.0*scale,surfaceFit);
      geometry=new T.PlaneGeometry(fit.width,fit.height);
      // Normal faces up. Do NOT mirror UV on the floor: left/right here
      // already matches a print viewed from above.
      geometry.rotateX(-Math.PI/2);
      geometry.translate(x,.065,z);
      point=new T.Vector3(x,.065,z);
    } else {
      const patch=this.domePatch({...entry,mode,surfaceScale:scale});
      if(!patch)return null;
      geometry=patch.geometry;
      point=patch.point;
      fit=patch.fit;
    }
    let texture=null;
    if(previous && previous.entry.openHref === entry.openHref)texture=previous.texture;
    const material=new T.MeshBasicMaterial({
      // MeshBasicMaterial is unlit. Disabling ACES tone mapping ensures a
      // placed photo has the same sRGB colour/contrast as the free HTML
      // image viewer, rather than getting a grey filmed-over appearance.
      color:texture ? 0xffffff : 0xffffff,
      toneMapped:false, side:T.DoubleSide, map:texture, depthWrite:true,
      transparent:false, polygonOffset:true, polygonOffsetFactor:-1
    });
    const mesh=new T.Mesh(geometry,material);
    mesh.name='drive3d-placed-image';
    mesh.userData.placementId=id;
    mesh.renderOrder=4;
    this.scene.add(mesh);
    if(previous){
      previous.mesh.removeFromParent();
      previous.mesh.geometry.dispose();
      previous.mesh.material.dispose();
      if(previous.texture !== texture)previous.texture?.dispose();
    }
    const state={mesh,point,entry:{...entry,mode,surfaceScale:scale,surfaceFit},texture,fit};
    this.placements.set(id,state);
    if(!texture && typeof entry.openHref === 'string' && entry.openHref){
      const url=entry.openHref;
      new T.TextureLoader().load(url,loaded=>{
        if(this.placements.get(id)!==state){loaded.dispose();return;}
        loaded.colorSpace=T.SRGBColorSpace;
        loaded.anisotropy=Math.min(4,loaded.anisotropy || 4);
        loaded.wrapS=T.ClampToEdgeWrapping;
        loaded.wrapT=T.ClampToEdgeWrapping;
        // Pixel data/UVs are controlled by our mesh, never modified here.
        loaded.rotation=0;
        loaded.needsUpdate=true;
        state.texture=loaded;
        material.map=loaded;
        material.color.set(0xffffff);
        material.needsUpdate=true;
        this.callbacks.onRender?.();
      },undefined,()=>this.callbacks.onHint?.('No se pudo cargar una imagen colocada en el domo.'));
    }
    this.callbacks.onRender?.();
    return point.toArray();
  }

  select(ray) {
    const hits=ray.intersectObjects([...this.placements.values()].map(p=>p.mesh),false);
    if(!hits.length)return false;
    const id=hits[0].object.userData.placementId;
    if(id){this.callbacks.onSelected?.(id);return true;}
    return false;
  }

  remove(id) {
    if(!id)return;
    const old=this.placements.get(String(id));
    if(!old)return;
    old.mesh.removeFromParent();
    old.mesh.geometry.dispose();
    old.mesh.material.dispose();
    old.texture?.dispose();
    this.placements.delete(String(id));
    if(this.pending?.id===String(id))this.cancel();
    this.callbacks.onRender?.();
  }

  point(id) {return this.placements.get(String(id))?.point.toArray() || null;}
  snapshot() {return [...this.placements.values()].map(({entry,point,fit,mesh})=>{
    const uv=mesh.geometry.getAttribute('uv');
    return {
      id:entry.id,mode:entry.mode,panelId:entry.panelId||'',world:point.toArray(),
      scale:entry.surfaceScale,surfaceFit:entry.surfaceFit || 'poster',
      printSize:fit ? [fit.width,fit.height] : null,
      imageCrop:fit ? [fit.cropU,fit.cropV] : null,
      // Debuggable UV handedness: from inside a window/roof, left is U=1.
      // Floor printing is normal-facing, so left remains U=0.
      horizontalUv:uv ? [uv.getX(0),uv.getX(entry.mode==='floor' ? 1 : 12)] : null
    };
  });}
}
export {Drive3DSurfacePlacements};
