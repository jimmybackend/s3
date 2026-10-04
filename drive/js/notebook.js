(() => {
'use strict';
const $ = s => document.querySelector(s);
const canvas = $('#nbCanvas'), ctx = canvas.getContext('2d', {alpha:true});
const state = {
  notebook:'', notebooks:[], page:1, pages:100, tool:'pencil', color:'#111111', size:3,
  background:'blank', paper:'letter', orientation:'portrait', onLine:false, alignment:'left', font:'caveat',
  drawing:false, points:[], objects:[], undo:[], redo:[], dirty:false, lineAnchor:null,
  recognitionTimers:new Map(), recognitionBusy:new Set()
};
const paper = {
  'letter':[816,1056], 'legal':[816,1344], 'tabloid':[1056,1632], 'half-letter':[528,816]
};
const WRITE_LEFT = 76, WRITE_RIGHT = 42, WRITE_TOP = 64, WRITE_BOTTOM = 92, RULE_STEP = 36;
function status(t, bad=false){ const el=$('#nbStatus'); el.textContent=t; el.dataset.bad=bad?'1':'0'; }
function api(action, options={}) {
  const init = {credentials:'same-origin', ...options};
  init.headers = new Headers(init.headers || {});
  if ((init.method||'GET').toUpperCase()==='POST') {
    init.headers.set('Content-Type','application/json');
    init.headers.set('X-Drive-CSRF', String(window.DRIVE_UPLOAD_CSRF||''));
  }
  return fetch('notebook-api.php?action='+encodeURIComponent(action), init).then(async r => {
    const j = await r.json().catch(()=>({ok:false,error:'Respuesta inválida'}));
    if (!r.ok || !j.ok) throw new Error(j.error || ('HTTP '+r.status));
    return j;
  });
}
function canvasSize() {
  let [w,h] = paper[state.paper] || paper.letter;
  if (state.orientation==='landscape') [w,h]=[h,w];
  canvas.width=w; canvas.height=h;
  render();
}
function background() {
  ctx.save(); ctx.fillStyle='#fff'; ctx.fillRect(0,0,canvas.width,canvas.height);
  ctx.lineWidth=1;
  if (state.background==='ruled' || state.background==='notes') {
    ctx.strokeStyle='#b8d1ef';
    for(let y=WRITE_TOP;y<canvas.height;y+=RULE_STEP){ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(canvas.width,y);ctx.stroke();}
    if(state.background==='notes'){ctx.strokeStyle='#efb8b8';ctx.beginPath();ctx.moveTo(72,0);ctx.lineTo(72,canvas.height);ctx.stroke();}
  }
  if (state.background==='grid' || state.background==='millimeter') {
    const step=state.background==='millimeter'?12:RULE_STEP;
    ctx.strokeStyle=state.background==='millimeter'?'#dde8f4':'#c7d9ee';
    for(let x=0;x<canvas.width;x+=step){ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,canvas.height);ctx.stroke();}
    for(let y=0;y<canvas.height;y+=step){ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(canvas.width,y);ctx.stroke();}
  }
  if(state.background==='dots'){
    ctx.fillStyle='#b8c8d8';
    for(let y=24;y<canvas.height;y+=28)for(let x=24;x<canvas.width;x+=28){ctx.beginPath();ctx.arc(x,y,1.4,0,Math.PI*2);ctx.fill();}
  }
  ctx.restore();
}
function styleFor(o){
  const table={pencil:{alpha:.82,mul:1},pen:{alpha:1,mul:.9},fountain:{alpha:1,mul:1.25},marker:{alpha:.28,mul:4},brush:{alpha:.75,mul:2.5},eraser:{alpha:1,mul:5}};
  return table[o.tool]||table.pencil;
}
function drawStroke(o){
  if(!o.points?.length)return;
  const s=styleFor(o); ctx.save(); ctx.globalAlpha=s.alpha; ctx.lineCap=o.tool==='fountain'?'square':'round'; ctx.lineJoin='round';
  ctx.strokeStyle=o.tool==='eraser'?'#fff':o.color; ctx.lineWidth=Math.max(1,o.size*s.mul);
  ctx.beginPath(); ctx.moveTo(o.points[0].x,o.points[0].y); for(const p of o.points.slice(1))ctx.lineTo(p.x,p.y); ctx.stroke(); ctx.restore();
}
function nearestBaseline(y){
  const max = canvas.height-WRITE_BOTTOM;
  const line = WRITE_TOP + Math.round((y-WRITE_TOP)/RULE_STEP)*RULE_STEP;
  return Math.max(WRITE_TOP, Math.min(max, line));
}
function alignStrokeToRule(points){
  if(!state.onLine || ['eraser','line-eraser'].includes(state.tool) || !points.length) return points;
  const rawStart=state.lineAnchor?.rawY ?? points[0].y;
  const baseline=state.lineAnchor?.baseline ?? nearestBaseline(rawStart);
  return points.map(p=>{
    const relative=Math.max(-26,Math.min(2,p.y-rawStart));
    return {x:p.x,y:Math.max(0,Math.min(canvas.height,baseline+relative))};
  });
}
const fontStacks={
  caveat:"'Caveat','Segoe Print','Comic Sans MS',cursive",
  dancing:"'Dancing Script','Segoe Script','Comic Sans MS',cursive",
  shadows:"'Shadows Into Light','Segoe Print','Comic Sans MS',cursive",
  sans:"Arial,'Helvetica Neue',Helvetica,sans-serif",
  serif:"Georgia,'Times New Roman',serif",
  mono:"'Roboto Mono',Consolas,'Courier New',monospace",
  rounded:"'Trebuchet MS','Arial Rounded MT Bold',Arial,sans-serif"
};
function textFont(size,font='caveat'){return size+"px "+(fontStacks[font]||fontStacks.caveat);}
function splitWordToWidth(word,maxWidth){
  if(ctx.measureText(word).width<=maxWidth)return [word];
  const pieces=[];let current='';
  for(const char of [...word]){
    const candidate=current+char;
    if(current && ctx.measureText(candidate+'-').width>maxWidth){pieces.push(current+'-');current=char;}
    else current=candidate;
  }
  if(current)pieces.push(current);
  return pieces;
}
function layoutText(text,size,maxWidth,font=state.font){
  ctx.save();ctx.font=textFont(size,font);
  const source=String(text||'').trim().split(/\s+/).filter(Boolean), tokens=[];
  for(const word of source) tokens.push(...splitWordToWidth(word,maxWidth));
  const lines=[];let words=[];let width=0;
  for(const token of tokens){
    const tokenWidth=ctx.measureText(token).width;
    const spacer=words.length?ctx.measureText(' ').width:0;
    if(words.length && width+spacer+tokenWidth>maxWidth){lines.push({words,width});words=[token];width=tokenWidth;}
    else {words.push(token);width+=spacer+tokenWidth;}
  }
  if(words.length)lines.push({words,width});
  ctx.restore();return lines;
}
function drawText(o){
  const size=o.size||30, alignment=o.alignment||'left', lineMode=o.onLine===true;
  const left=o.x??WRITE_LEFT, right=canvas.width-WRITE_RIGHT, maxWidth=Math.max(80,right-left);
  const lines=layoutText(o.text,size,maxWidth,o.font||'caveat');
  const lineHeight=lineMode?RULE_STEP:Math.max(size*1.5,RULE_STEP);
  let y=lineMode?nearestBaseline(o.y??WRITE_TOP):(o.y??WRITE_TOP);
  ctx.save();ctx.fillStyle=o.color||'#111';ctx.font=textFont(size,o.font||'caveat');ctx.textBaseline=lineMode?'alphabetic':'top';
  const spaceWidth=ctx.measureText(' ').width;
  lines.forEach((line,index)=>{
    if(y>canvas.height-WRITE_BOTTOM)return;
    let x=left;
    if(alignment==='right')x=right-line.width;
    const justify=alignment==='justify' && index<lines.length-1 && line.words.length>1;
    const extra=justify?(maxWidth-line.width)/(line.words.length-1):0;
    line.words.forEach((word,wordIndex)=>{
      ctx.save();ctx.translate(x,y);ctx.rotate((Math.sin((x+y)/47)*0.012));ctx.fillText(word,0,0);ctx.restore();
      x+=ctx.measureText(word).width;
      if(wordIndex<line.words.length-1)x+=spaceWidth+extra;
    });
    y+=lineHeight;
  });
  ctx.restore();
}
function render(){background(); for(const o of state.objects){ if(o.kind==='text')drawText(o); else drawStroke(o); }}
function snap(){state.undo.push(JSON.stringify(state.objects)); if(state.undo.length>60)state.undo.shift(); state.redo=[];}
function pos(e){const r=canvas.getBoundingClientRect();return{x:(e.clientX-r.left)*canvas.width/r.width,y:(e.clientY-r.top)*canvas.height/r.height};}
function previewPoints(){return alignStrokeToRule([...state.points]);}
function objectTouchesLine(object,baseline){
  const tolerance=RULE_STEP/2;
  if(object.kind==='text'){
    const size=object.size||30, left=object.x??WRITE_LEFT, maxWidth=Math.max(80,canvas.width-WRITE_RIGHT-left);
    const count=Math.max(1,layoutText(object.text,size,maxWidth,object.font||'caveat').length);
    const start=object.onLine===true?nearestBaseline(object.y??WRITE_TOP):(object.y??WRITE_TOP);
    const step=object.onLine===true?RULE_STEP:Math.max(size*1.5,RULE_STEP);
    for(let i=0;i<count;i++) if(Math.abs((start+i*step)-baseline)<=tolerance) return true;
    return false;
  }
  return Array.isArray(object.points) && object.points.some(p=>Math.abs(p.y-baseline)<=tolerance);
}
function eraseWholeLine(y){
  const baseline=nearestBaseline(y), before=state.objects.length;
  snap(); state.objects=state.objects.filter(o=>!objectTouchesLine(o,baseline));
  if(state.objects.length!==before){state.dirty=true;render();status('Línea borrada');}
  else status('No hay escritura en esa línea');
}
function lineStrokeObjects(baseline){
  return state.objects.filter(o=>o.kind==='stroke' && o.onLine===true && o.tool!=='eraser' && o.baseline===baseline && o.ocrPending===true);
}
function linePng(baseline, objects){
  const scale=2, pad=44, top=Math.max(0,baseline-pad), height=Math.min(canvas.height-top,pad*2);
  const out=document.createElement('canvas');out.width=canvas.width*scale;out.height=height*scale;
  const c2=out.getContext('2d');c2.fillStyle='#fff';c2.fillRect(0,0,out.width,out.height);c2.scale(scale,scale);c2.translate(0,-top);
  for(const o of objects){
    const s=styleFor(o);c2.save();c2.globalAlpha=Math.max(.75,s.alpha);c2.lineCap='round';c2.lineJoin='round';c2.strokeStyle='#111';c2.lineWidth=Math.max(2,o.size*s.mul);
    c2.beginPath();c2.moveTo(o.points[0].x,o.points[0].y);for(const p of o.points.slice(1))c2.lineTo(p.x,p.y);c2.stroke();c2.restore();
  }
  return out.toDataURL('image/png');
}
function scheduleLineRecognition(baseline){
  if(!state.onLine || ['eraser','line-eraser'].includes(state.tool)) return;
  const previous=state.recognitionTimers.get(baseline);if(previous)clearTimeout(previous);
  status('Esperando fin de escritura…');
  state.recognitionTimers.set(baseline,setTimeout(()=>recognizeLine(baseline),1100));
}
async function recognizeLine(baseline){
  state.recognitionTimers.delete(baseline);
  if(state.recognitionBusy.has(baseline)) return;
  const objects=lineStrokeObjects(baseline);if(!objects.length)return;
  state.recognitionBusy.add(baseline);status('Leyendo escritura…');
  try{
    const j=await api('recognize_line',{method:'POST',body:JSON.stringify({png:linePng(baseline,objects)})});
    const text=String(j.text||'').trim();
    if(!j.recognized||!text){objects.forEach(o=>o.ocrPending=false);status('No pude leer la frase; conservé los trazos.',true);return;}
    const ids=new Set(objects.map(o=>o.id));const minX=Math.max(WRITE_LEFT,Math.min(...objects.flatMap(o=>o.points.map(p=>p.x))));
    snap();state.objects=state.objects.filter(o=>!ids.has(o.id));
    state.objects.push({kind:'text',text,x:minX,y:baseline,color:state.color,size:Math.max(20,state.size*8),onLine:true,alignment:state.alignment,font:state.font,recognizedFromInk:true});
    state.dirty=true;render();status('Escritura reconocida');
  }catch(e){objects.forEach(o=>o.ocrPending=false);status('No se pudo reconocer; conservé los trazos.',true);}
  finally{state.recognitionBusy.delete(baseline);}
}
canvas.addEventListener('pointerdown',e=>{
  e.preventDefault();
  const point=pos(e);
  if(state.tool==='line-eraser'){eraseWholeLine(point.y);return;}
  snap();state.drawing=true;state.points=[point];
  state.lineAnchor=state.onLine?{rawY:point.y,baseline:nearestBaseline(point.y)}:null;
  canvas.setPointerCapture?.(e.pointerId);
});
canvas.addEventListener('pointermove',e=>{if(!state.drawing)return;state.points.push(pos(e));render();drawStroke({kind:'stroke',tool:state.tool,color:state.color,size:state.size,points:previewPoints()});});
canvas.addEventListener('pointerup',()=>{if(!state.drawing)return;state.drawing=false;const points=previewPoints(),baseline=state.lineAnchor?.baseline??null;const stroke={kind:'stroke',id:'s_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,8),tool:state.tool,color:state.color,size:state.size,points,onLine:state.onLine,baseline,ocrPending:state.onLine&& !['eraser','line-eraser'].includes(state.tool)};state.objects.push(stroke);state.points=[];state.lineAnchor=null;state.dirty=true;render();if(stroke.ocrPending&&baseline!==null)scheduleLineRecognition(baseline);});
$('#nbTool').addEventListener('change',e=>{state.tool=e.target.value;});
$('#nbFont').addEventListener('change',e=>{state.font=e.target.value;state.dirty=true;});
$('#nbColor').addEventListener('input',e=>state.color=e.target.value);
$('#nbSize').addEventListener('input',e=>state.size=+e.target.value);
$('#nbBackground').addEventListener('change',e=>{state.background=e.target.value;state.dirty=true;render();});
$('#nbPaper').addEventListener('change',e=>{state.paper=e.target.value;state.dirty=true;canvasSize();});
$('#nbOrientation').addEventListener('change',e=>{state.orientation=e.target.value;state.dirty=true;canvasSize();});
$('#nbOnLine').addEventListener('change',e=>{state.onLine=e.target.checked;state.dirty=true;});
$('#nbAlignment').addEventListener('change',e=>{state.alignment=e.target.value;state.dirty=true;});
$('#nbUndo').onclick=()=>{if(!state.undo.length)return;state.redo.push(JSON.stringify(state.objects));state.objects=JSON.parse(state.undo.pop());state.dirty=true;render();};
$('#nbRedo').onclick=()=>{if(!state.redo.length)return;state.undo.push(JSON.stringify(state.objects));state.objects=JSON.parse(state.redo.pop());state.dirty=true;render();};
$('#nbClear').onclick=()=>{if(!confirm('¿Borrar todo lo escrito en esta hoja?'))return;snap();state.objects=[];state.dirty=true;render();};
function nextTextY(size){
  const textObjects=state.objects.filter(o=>o.kind==='text');
  if(!textObjects.length)return state.onLine?WRITE_TOP:80;
  const last=textObjects[textObjects.length-1], maxWidth=Math.max(80,canvas.width-WRITE_RIGHT-(last.x??WRITE_LEFT));
  const count=Math.max(1,layoutText(last.text,last.size||size,maxWidth,last.font||'caveat').length);
  const step=last.onLine===true?RULE_STEP:Math.max((last.size||size)*1.5,RULE_STEP);
  return (last.y??WRITE_TOP)+(count*step);
}
$('#nbWritePrompt').onclick=()=>{
  const input=$('#nbPrompt'),text=input.value.trim();if(!text)return;
  const size=Math.max(20,state.size*8), y=nextTextY(size);
  if(y>canvas.height-WRITE_BOTTOM){status('No queda espacio en esta hoja para más texto.',true);return;}
  snap();state.objects.push({kind:'text',text,x:WRITE_LEFT,y:state.onLine?nearestBaseline(y):y,color:state.color,size,onLine:state.onLine,alignment:state.alignment,font:state.font});
  input.value='';state.dirty=true;render();
};
function serialize(){return JSON.stringify({version:4,page:state.page,pages:state.pages,paper:state.paper,orientation:state.orientation,background:state.background,onLine:state.onLine,alignment:state.alignment,font:state.font,objects:state.objects});}
async function save(){
  if(!state.notebook)return;
  status('Guardando PNG…');
  try{
    const j=await api('save_page',{method:'POST',body:JSON.stringify({notebook:state.notebook,page:state.page,png:canvas.toDataURL('image/png'),json:serialize()})});
    state.dirty=false; status(j.transcription_error?'Guardada · transcripción pendiente':'Guardada · transcrita');
  }catch(e){status(e.message,true);}
}
$('#nbSave').onclick=save;
async function loadPage(){
  status('Cargando…'); state.objects=[];render();
  try{
    const u='notebook-api.php?action=load_page&notebook='+encodeURIComponent(state.notebook)+'&page='+state.page;
    const r=await fetch(u,{credentials:'same-origin'}),j=await r.json(); if(!r.ok||!j.ok)throw new Error(j.error||'No se pudo cargar');
    if(!j.empty){
      const d=JSON.parse(j.json);state.objects=Array.isArray(d.objects)?d.objects:[];
      state.paper=d.paper||state.paper;state.orientation=d.orientation||state.orientation;state.background=d.background||state.background;
      state.onLine=d.onLine===true;state.alignment=['left','right','justify'].includes(d.alignment)?d.alignment:'left';state.font=['caveat','dancing','shadows','sans','serif','mono','rounded'].includes(d.font)?d.font:'caveat';
      syncControls();canvasSize();
    }
    state.dirty=false;status(j.empty?'Hoja nueva':'Hoja cargada');
  }catch(e){status(e.message,true);}
}
function syncControls(){
  $('#nbTool').value=state.tool;$('#nbFont').value=state.font;$('#nbBackground').value=state.background;$('#nbPaper').value=state.paper;$('#nbOrientation').value=state.orientation;
  $('#nbOnLine').checked=state.onLine;$('#nbAlignment').value=state.alignment;$('#nbPage').value=state.page;$('#nbPageLabel').textContent=state.page;$('#nbMenuPage').textContent=state.page;$('#nbTotal').textContent=state.pages;$('#nbMenuTotal').textContent=state.pages;
}
async function gotoPage(n){n=Math.max(1,Math.min(state.pages,n));if(n===state.page)return;if(state.dirty&&confirm('La hoja tiene cambios. ¿Guardarla antes de cambiar?'))await save();state.page=n;syncControls();await loadPage();canvas.parentElement.classList.remove('is-turning');void canvas.parentElement.offsetWidth;canvas.parentElement.classList.add('is-turning');}
$('#nbPrev').onclick=()=>gotoPage(state.page-1); $('#nbNext').onclick=()=>gotoPage(state.page+1); $('#nbPage').onchange=e=>gotoPage(+e.target.value||1);
async function refreshBooks(select=''){
  const j=await api('list');state.notebooks=j.notebooks||[];const sel=$('#nbNotebook');sel.replaceChildren();
  for(const b of state.notebooks){const o=document.createElement('option');o.value=b.name;o.textContent=b.name;sel.append(o);}
  if(select&&state.notebooks.some(b=>b.name===select))sel.value=select;
  state.notebook=sel.value||'';
  const current=state.notebooks.find(b=>b.name===state.notebook);
  if(current){state.pages=+current.pages||100;state.paper=current.paper||'letter';state.orientation=current.orientation||'portrait';state.background=current.background||'blank';syncControls();canvasSize();}
  $('#nbMoveTarget').replaceChildren(...state.notebooks.filter(b=>b.name!==state.notebook).map(b=>{const o=document.createElement('option');o.value=b.name;o.textContent=b.name;return o;}));
  if(state.notebook)await loadPage(); else status('Crea tu primera libreta');
}
$('#nbNotebook').onchange=async e=>{if(state.dirty&&confirm('¿Guardar cambios antes de cambiar de libreta?'))await save();state.notebook=e.target.value;state.page=1;const current=state.notebooks.find(b=>b.name===state.notebook);if(current){state.pages=+current.pages||100;state.paper=current.paper||'letter';state.orientation=current.orientation||'portrait';state.background=current.background||'blank';canvasSize();}syncControls();await loadPage();};
$('#nbNewBook').onclick=()=>$('#nbNewDialog').showModal();
$('#nbCreateBook').onclick=async e=>{e.preventDefault();try{const name=$('#nbNewName').value.trim();const pages=+$('#nbNewPages').value||100;await api('create',{method:'POST',body:JSON.stringify({name,pages,paper:state.paper,orientation:state.orientation,background:state.background})});state.pages=pages;$('#nbNewDialog').close();await refreshBooks(name);}catch(err){status(err.message,true);}};
$('#nbMovePage').onclick=()=>{if(!state.notebook)return;if($('#nbMoveTarget').options.length===0){status('No hay otra libreta destino',true);return;}$('#nbMoveDialog').showModal();};
$('#nbConfirmMove').onclick=async e=>{e.preventDefault();try{if(state.dirty)await save();const target=$('#nbMoveTarget').value;await api('move_page',{method:'POST',body:JSON.stringify({notebook:state.notebook,page:state.page,target})});$('#nbMoveDialog').close();state.objects=[];state.dirty=false;render();status('Hoja movida a '+target);}catch(err){status(err.message,true);}};
let touchX=null;canvas.addEventListener('touchstart',e=>{touchX=e.touches[0]?.clientX??null},{passive:true});canvas.addEventListener('touchend',e=>{if(touchX===null)return;const dx=(e.changedTouches[0]?.clientX??touchX)-touchX;touchX=null;if(Math.abs(dx)>120&&!state.drawing)gotoPage(state.page+(dx<0?1:-1));},{passive:true});
window.addEventListener('beforeunload',e=>{if(!state.dirty)return;e.preventDefault();e.returnValue='';});
canvasSize();syncControls();refreshBooks().catch(e=>status(e.message,true));
})();