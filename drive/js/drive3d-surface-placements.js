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
    this.bands = [0, .28, .52, .79, 1.05];
  }

  static mode(mode) {
    return ['floor','window','ceiling'].includes(mode) ? mode : 'free';
  }

  static scale(value, mode) {
    const n = Number(value);
    return Math.min(mode === 'floor' ? 2.6 : .94, Math.max(.30, Number.isFinite(n) ? n : .70));
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
      surfaceScale:Drive3DSurfacePlacements.scale(previous.surfaceScale,mode)
    };
    this.pending = null;
    // Rendering is performed by the owner after app metadata is synchronized.
    this.callbacks.onPlaced?.(next);
    return true;
  }

  /** Create a curved patch entirely INSIDE one wooden window section. */
  domePatch(entry) {
    const T = this.T;
    const {mode} = entry;
    const info = Drive3DSurfacePlacements.panelFromId(mode,entry.panelId);
    if (!info) return null;
    const slice = Math.PI*2/this.ribs;
    const scale = Drive3DSurfacePlacements.scale(entry.surfaceScale,mode);
    const midAngle = (info.sector+.5)*slice;
    const longitudeHalf = (slice*.42)*scale;
    const bounds = mode === 'ceiling' ? [1.075,1.545] : [this.bands[info.band]+.025,this.bands[info.band+1]-.025];
    const elevationCenter = (bounds[0]+bounds[1])/2;
    const elevationHalf = Math.max(.015,(bounds[1]-bounds[0])*.5*scale);
    const segmentsX = 12,segmentsY = 8;
    const vertices=[],uv=[],indices=[];
    const rad = this.radius-.25;
    for(let row=0;row<=segmentsY;row++){
      const v=row/segmentsY;
      const elev=elevationCenter+(v*2-1)*elevationHalf;
      for(let col=0;col<=segmentsX;col++){
        const u=col/segmentsX,angle=midAngle+(u*2-1)*longitudeHalf;
        vertices.push(rad*Math.cos(elev)*Math.sin(angle),rad*Math.sin(elev),rad*Math.cos(elev)*Math.cos(angle));
        uv.push(u,v);
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
    return {geometry,point:p};
  }

  set(entry) {
    const T=this.T;
    const mode=Drive3DSurfacePlacements.mode(entry?.mode);
    if (!entry?.id || mode === 'free') {this.remove(entry?.id);return null;}
    const id=String(entry.id);
    const previous=this.placements.get(id);
    const scale=Drive3DSurfacePlacements.scale(entry.surfaceScale,mode);
    let geometry,point;
    if(mode === 'floor'){
      const coordinates=Array.isArray(entry.world) ? entry.world : [0,.065,0];
      const x=Number(coordinates[0]) || 0,z=Number(coordinates[2]) || 0;
      if(Math.hypot(x,z)>this.radius-.35) return null;
      const aspect=Math.max(.3,Math.min(4,Number(entry.aspect)||4/3));
      const w=3.0*scale,h=w/aspect;
      geometry=new T.PlaneGeometry(w,h);
      geometry.rotateX(-Math.PI/2);
      geometry.translate(x,.065,z);
      point=new T.Vector3(x,.065,z);
    } else {
      const patch=this.domePatch({...entry,mode,surfaceScale:scale});
      if(!patch)return null;
      geometry=patch.geometry;
      point=patch.point;
    }
    let texture=null;
    if(previous && previous.entry.openHref === entry.openHref)texture=previous.texture;
    const material=new T.MeshBasicMaterial({
      color:0xa1cedc, side:T.DoubleSide, map:texture, depthWrite:false,
      transparent:false, polygonOffset:true, polygonOffsetFactor:-1
    });
    const mesh=new T.Mesh(geometry,material);
    mesh.name='drive3d-placed-image';
    mesh.userData.placementId=id;
    mesh.renderOrder=3;
    this.scene.add(mesh);
    if(previous){
      previous.mesh.removeFromParent();
      previous.mesh.geometry.dispose();
      previous.mesh.material.dispose();
      if(previous.texture !== texture)previous.texture?.dispose();
    }
    const state={mesh,point,entry:{...entry,mode,surfaceScale:scale},texture};
    this.placements.set(id,state);
    if(!texture && typeof entry.openHref === 'string' && entry.openHref){
      const url=entry.openHref;
      new T.TextureLoader().load(url,loaded=>{
        if(this.placements.get(id)!==state){loaded.dispose();return;}
        loaded.colorSpace=T.SRGBColorSpace;
        loaded.anisotropy= Math.min(4,loaded.anisotropy || 4);
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
  snapshot() {return [...this.placements.values()].map(({entry,point})=>({
    id:entry.id,mode:entry.mode,panelId:entry.panelId||'',world:point.toArray(),scale:entry.surfaceScale
  }));}
}
export {Drive3DSurfacePlacements};
