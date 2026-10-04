(() => {
'use strict';
const $=s=>document.querySelector(s);
const canvas=$('#nbCanvas'),ctx=canvas.getContext('2d',{alpha:true});
const imageCache=new Map();
const state={
 notebook:'',notebooks:[],page:1,pages:100,tool:'select',color:'#111111',size:3,fontSize:28,zoom:50,
 background:'blank',paper:'letter',orientation:'portrait',onLine:false,alignment:'left',font:'caveat',
 drawing:false,points:[],objects:[],undo:[],redo:[],dirty:false,lineAnchor:null,
 recognitionTimers:new Map(),recognitionBusy:new Set(),selectedId:null,drag:null,textCursor:null,longPressTimer:null,longPressStart:null
};
const paper={'letter':[816,1056],'legal':[816,1344],'tabloid':[1056,1632],'half-letter':[528,816]};
const WRITE_LEFT=76,WRITE_RIGHT=42,WRITE_TOP=64,WRITE_BOTTOM=92,RULE_STEP=36;
function uid(prefix='o'){return prefix+'_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,8);}
function status(t,bad=false){const el=$('#nbStatus');el.textContent=t;el.dataset.bad=bad?'1':'0';}
function api(action,options={}){
 const init={credentials:'same-origin',...options};init.headers=new Headers(init.headers||{});
 if((init.method||'GET').toUpperCase()==='POST'){init.headers.set('Content-Type','application/json');init.headers.set('X-Drive-CSRF',String(window.DRIVE_UPLOAD_CSRF||''));}
 return fetch('notebook-api.php?action='+encodeURIComponent(action),init).then(async r=>{const j=await r.json().catch(()=>({ok:false,error:'Respuesta inválida'}));if(!r.ok||!j.ok)throw new Error(j.error||('HTTP '+r.status));return j;});
}
function canvasSize(){let [w,h]=paper[state.paper]||paper.letter;if(state.orientation==='landscape')[w,h]=[h,w];canvas.width=w;canvas.height=h;applyZoom();render();}
function applyZoom(){
 const w=canvas.width*state.zoom/100,h=canvas.height*state.zoom/100,stage=canvas.closest('.nb-page-stage');
 canvas.style.width=w+'px';canvas.style.height=h+'px';
 if(stage){stage.style.width=w+'px';stage.style.height=h+'px';}
}
function background(){
 ctx.save();ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.lineWidth=1;
 if(state.background==='ruled'||state.background==='notes'){ctx.strokeStyle='#b8d1ef';for(let y=WRITE_TOP;y<canvas.height;y+=RULE_STEP){ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(canvas.width,y);ctx.stroke();}if(state.background==='notes'){ctx.strokeStyle='#efb8b8';ctx.beginPath();ctx.moveTo(72,0);ctx.lineTo(72,canvas.height);ctx.stroke();}}
 if(state.background==='grid'||state.background==='millimeter'){const step=state.background==='millimeter'?12:RULE_STEP;ctx.strokeStyle=state.background==='millimeter'?'#dde8f4':'#c7d9ee';for(let x=0;x<canvas.width;x+=step){ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,canvas.height);ctx.stroke();}for(let y=0;y<canvas.height;y+=step){ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(canvas.width,y);ctx.stroke();}}
 if(state.background==='dots'){ctx.fillStyle='#b8c8d8';for(let y=24;y<canvas.height;y+=28)for(let x=24;x<canvas.width;x+=28){ctx.beginPath();ctx.arc(x,y,1.4,0,Math.PI*2);ctx.fill();}}
 ctx.restore();
}
function styleFor(o){const table={pencil:{alpha:.82,mul:1},pen:{alpha:1,mul:.9},fountain:{alpha:1,mul:1.25},marker:{alpha:.28,mul:4},brush:{alpha:.75,mul:2.5},eraser:{alpha:1,mul:5}};return table[o.tool]||table.pencil;}
function drawStroke(o){if(!o.points?.length)return;const s=styleFor(o);ctx.save();ctx.globalAlpha=s.alpha;ctx.lineCap=o.tool==='fountain'?'square':'round';ctx.lineJoin='round';ctx.strokeStyle=o.tool==='eraser'?'#fff':o.color;ctx.lineWidth=Math.max(1,o.size*s.mul);ctx.beginPath();ctx.moveTo(o.points[0].x,o.points[0].y);for(const p of o.points.slice(1))ctx.lineTo(p.x,p.y);ctx.stroke();ctx.restore();}
function nearestBaseline(y){const max=canvas.height-WRITE_BOTTOM;const line=WRITE_TOP+Math.round((y-WRITE_TOP)/RULE_STEP)*RULE_STEP;return Math.max(WRITE_TOP,Math.min(max,line));}
function alignStrokeToRule(points){if(!state.onLine||['eraser','line-eraser','select'].includes(state.tool)||!points.length)return points;const rawStart=state.lineAnchor?.rawY??points[0].y;const baseline=state.lineAnchor?.baseline??nearestBaseline(rawStart);return points.map(p=>({x:p.x,y:Math.max(0,Math.min(canvas.height,baseline+Math.max(-26,Math.min(2,p.y-rawStart))))}));}
const fontStacks={caveat:"'Caveat','Segoe Print','Comic Sans MS',cursive",dancing:"'Dancing Script','Segoe Script','Comic Sans MS',cursive",shadows:"'Shadows Into Light','Segoe Print','Comic Sans MS',cursive",sans:"Arial,'Helvetica Neue',Helvetica,sans-serif",serif:"Georgia,'Times New Roman',serif",mono:"'Roboto Mono',Consolas,'Courier New',monospace",rounded:"'Trebuchet MS','Arial Rounded MT Bold',Arial,sans-serif"};
function textFont(size,font='caveat'){return size+'px '+(fontStacks[font]||fontStacks.caveat);}
function splitWordToWidth(word,maxWidth,font,size){ctx.save();ctx.font=textFont(size,font);if(ctx.measureText(word).width<=maxWidth){ctx.restore();return[word];}const pieces=[];let current='';for(const char of [...word]){const candidate=current+char;if(current&&ctx.measureText(candidate+'-').width>maxWidth){pieces.push(current+'-');current=char;}else current=candidate;}if(current)pieces.push(current);ctx.restore();return pieces;}
function layoutText(text,size,maxWidth,font=state.font){ctx.save();ctx.font=textFont(size,font);const source=String(text||'').trim().split(/\s+/).filter(Boolean),tokens=[];for(const word of source)tokens.push(...splitWordToWidth(word,maxWidth,font,size));const lines=[];let words=[],width=0;for(const token of tokens){const tokenWidth=ctx.measureText(token).width,spacer=words.length?ctx.measureText(' ').width:0;if(words.length&&width+spacer+tokenWidth>maxWidth){lines.push({words,width});words=[token];width=tokenWidth;}else{words.push(token);width+=spacer+tokenWidth;}}if(words.length)lines.push({words,width});ctx.restore();return lines;}
function textBounds(o){
 const size=o.size||28,left=o.x??WRITE_LEFT,right=canvas.width-WRITE_RIGHT,maxWidth=Math.max(80,right-left),lines=layoutText(o.text,size,maxWidth,o.font||'caveat'),lineHeight=o.onLine===true?RULE_STEP:Math.max(size*1.5,RULE_STEP),start=o.onLine===true?nearestBaseline(o.y??WRITE_TOP):(o.y??WRITE_TOP);
 const actual=Math.max(24,...lines.map(line=>line.width));
 return{x:left,y:o.onLine===true?start-size:start,w:Math.min(maxWidth,actual),h:Math.max(lineHeight,lines.length*lineHeight)};
}
function drawText(o){const size=o.size||28,alignment=o.alignment||'left',lineMode=o.onLine===true,left=o.x??WRITE_LEFT,right=canvas.width-WRITE_RIGHT,maxWidth=Math.max(80,right-left),lines=layoutText(o.text,size,maxWidth,o.font||'caveat'),lineHeight=lineMode?RULE_STEP:Math.max(size*1.5,RULE_STEP);let y=lineMode?nearestBaseline(o.y??WRITE_TOP):(o.y??WRITE_TOP);ctx.save();ctx.fillStyle=o.color||'#111';ctx.font=textFont(size,o.font||'caveat');ctx.textBaseline=lineMode?'alphabetic':'top';const spaceWidth=ctx.measureText(' ').width;lines.forEach((line,index)=>{if(y>canvas.height-WRITE_BOTTOM)return;let x=left;if(alignment==='right')x=right-line.width;const justify=alignment==='justify'&&index<lines.length-1&&line.words.length>1,extra=justify?(maxWidth-line.width)/(line.words.length-1):0;line.words.forEach((word,wordIndex)=>{ctx.fillText(word,x,y);x+=ctx.measureText(word).width;if(wordIndex<line.words.length-1)x+=spaceWidth+extra;});y+=lineHeight;});ctx.restore();}
function getImage(src){if(imageCache.has(src))return imageCache.get(src);const img=new Image();img.onload=()=>render();img.src=src;imageCache.set(src,img);return img;}
function drawImageObject(o){const img=getImage(o.src);if(!img.complete)return;ctx.save();ctx.translate(o.x+o.width/2,o.y+o.height/2);ctx.rotate((o.rotation||0)*Math.PI/180);ctx.drawImage(img,-o.width/2,-o.height/2,o.width,o.height);ctx.restore();}
function objectBounds(o){if(o.kind==='text')return textBounds(o);if(o.kind==='image')return{x:o.x,y:o.y,w:o.width,h:o.height};if(o.kind==='stroke'){const xs=o.points.map(p=>p.x),ys=o.points.map(p=>p.y);return{x:Math.min(...xs),y:Math.min(...ys),w:Math.max(12,Math.max(...xs)-Math.min(...xs)),h:Math.max(12,Math.max(...ys)-Math.min(...ys))};}return{x:0,y:0,w:0,h:0};}
function selected(){return state.objects.find(o=>o.id===state.selectedId)||null;}
function drawSelection(){
 const o=selected();
 if(o){const b=objectBounds(o);ctx.save();ctx.strokeStyle='#1677c8';ctx.lineWidth=2;ctx.setLineDash([7,5]);ctx.strokeRect(b.x-5,b.y-5,b.w+10,b.h+10);ctx.setLineDash([]);ctx.fillStyle='#fff';ctx.strokeStyle='#1677c8';
   if(o.kind==='image'){
     for(const p of [[b.x+b.w,b.y+b.h],[b.x+b.w/2,b.y-28]]){ctx.beginPath();ctx.arc(p[0],p[1],8,0,Math.PI*2);ctx.fill();ctx.stroke();}
     const dx=b.x+b.w+5,dy=b.y-5;ctx.fillStyle='#c62828';ctx.beginPath();ctx.arc(dx,dy,11,0,Math.PI*2);ctx.fill();
     ctx.strokeStyle='#fff';ctx.lineWidth=2;ctx.beginPath();ctx.moveTo(dx-4,dy-4);ctx.lineTo(dx+4,dy+4);ctx.moveTo(dx+4,dy-4);ctx.lineTo(dx-4,dy+4);ctx.stroke();
   }
   if(o.kind==='text'){ctx.beginPath();ctx.arc(b.x+b.w,b.y+b.h,8,0,Math.PI*2);ctx.fill();ctx.stroke();}
   ctx.restore();
 }
 if(state.textCursor&&!o){ctx.save();ctx.strokeStyle='#1677c8';ctx.lineWidth=2;ctx.beginPath();ctx.moveTo(state.textCursor.x,state.textCursor.y-28);ctx.lineTo(state.textCursor.x,state.textCursor.y+3);ctx.stroke();ctx.restore();}
}
function render(){background();for(const o of state.objects){if(o.kind==='text')drawText(o);else if(o.kind==='image')drawImageObject(o);else drawStroke(o);}drawSelection();}
function snap(){state.undo.push(JSON.stringify(state.objects));if(state.undo.length>60)state.undo.shift();state.redo=[];}
function pos(e){const r=canvas.getBoundingClientRect();return{x:(e.clientX-r.left)*canvas.width/r.width,y:(e.clientY-r.top)*canvas.height/r.height};}
function previewPoints(){return alignStrokeToRule([...state.points]);}
function hitObject(p){for(let i=state.objects.length-1;i>=0;i--){const o=state.objects[i],b=objectBounds(o),pad=10;if(p.x>=b.x-pad&&p.x<=b.x+b.w+pad&&p.y>=b.y-pad&&p.y<=b.y+b.h+pad)return o;}return null;}
function selectionAction(p,o){
 if(!o)return null;const b=objectBounds(o);
 if(o.kind==='image'){
   const remove={x:b.x+b.w+5,y:b.y-5},resize={x:b.x+b.w,y:b.y+b.h},rotate={x:b.x+b.w/2,y:b.y-28};
   if(Math.hypot(p.x-remove.x,p.y-remove.y)<20)return'delete-image';
   if(Math.hypot(p.x-resize.x,p.y-resize.y)<18)return'resize';
   if(Math.hypot(p.x-rotate.x,p.y-rotate.y)<18)return'rotate';
 }
 if(o.kind==='text'&&Math.hypot(p.x-(b.x+b.w),p.y-(b.y+b.h))<18)return'text-resize';
 return'move';
}
function objectTouchesLine(object,baseline){const tolerance=RULE_STEP/2;if(object.kind==='text'){const size=object.size||28,left=object.x??WRITE_LEFT,maxWidth=Math.max(80,canvas.width-WRITE_RIGHT-left),count=Math.max(1,layoutText(object.text,size,maxWidth,object.font||'caveat').length),start=object.onLine===true?nearestBaseline(object.y??WRITE_TOP):(object.y??WRITE_TOP),step=object.onLine===true?RULE_STEP:Math.max(size*1.5,RULE_STEP);for(let i=0;i<count;i++)if(Math.abs((start+i*step)-baseline)<=tolerance)return true;return false;}return Array.isArray(object.points)&&object.points.some(p=>Math.abs(p.y-baseline)<=tolerance);}
function eraseWholeLine(y){const baseline=nearestBaseline(y),before=state.objects.length;snap();state.objects=state.objects.filter(o=>!objectTouchesLine(o,baseline));if(state.objects.length!==before){state.dirty=true;render();status('Línea borrada');}else status('No hay escritura en esa línea');}
function lineStrokeObjects(baseline){return state.objects.filter(o=>o.kind==='stroke'&&o.onLine===true&&o.tool!=='eraser'&&o.baseline===baseline&&o.ocrPending===true);}
function linePng(baseline,objects){const scale=2,pad=44,top=Math.max(0,baseline-pad),height=Math.min(canvas.height-top,pad*2),out=document.createElement('canvas');out.width=canvas.width*scale;out.height=height*scale;const c2=out.getContext('2d');c2.fillStyle='#fff';c2.fillRect(0,0,out.width,out.height);c2.scale(scale,scale);c2.translate(0,-top);for(const o of objects){const s=styleFor(o);c2.save();c2.globalAlpha=Math.max(.75,s.alpha);c2.lineCap='round';c2.lineJoin='round';c2.strokeStyle='#111';c2.lineWidth=Math.max(2,o.size*s.mul);c2.beginPath();c2.moveTo(o.points[0].x,o.points[0].y);for(const p of o.points.slice(1))c2.lineTo(p.x,p.y);c2.stroke();c2.restore();}return out.toDataURL('image/png');}
function scheduleLineRecognition(baseline){if(!state.onLine||['eraser','line-eraser','select'].includes(state.tool))return;const previous=state.recognitionTimers.get(baseline);if(previous)clearTimeout(previous);status('Esperando fin de escritura…');state.recognitionTimers.set(baseline,setTimeout(()=>recognizeLine(baseline),1100));}
async function recognizeLine(baseline){state.recognitionTimers.delete(baseline);if(state.recognitionBusy.has(baseline))return;const objects=lineStrokeObjects(baseline);if(!objects.length)return;state.recognitionBusy.add(baseline);status('Leyendo escritura…');try{const j=await api('recognize_line',{method:'POST',body:JSON.stringify({png:linePng(baseline,objects)})}),text=String(j.text||'').trim();if(!j.recognized||!text){objects.forEach(o=>o.ocrPending=false);status('No pude leer la frase; conservé los trazos.',true);return;}const ids=new Set(objects.map(o=>o.id)),minX=Math.max(WRITE_LEFT,Math.min(...objects.flatMap(o=>o.points.map(p=>p.x))));snap();state.objects=state.objects.filter(o=>!ids.has(o.id));state.objects.push({kind:'text',id:uid('t'),text,x:minX,y:baseline,color:state.color,size:state.fontSize,onLine:true,alignment:state.alignment,font:state.font,recognizedFromInk:true});state.dirty=true;render();status('Escritura reconocida');}catch(e){objects.forEach(o=>o.ocrPending=false);status('No se pudo reconocer; conservé los trazos.',true);}finally{state.recognitionBusy.delete(baseline);}}
function loadSelectedIntoPrompt(o){
 if(o?.kind!=='text')return;
 const input=$('#nbPrompt');input.value=o.text||'';state.font=o.font||'caveat';state.fontSize=o.size||28;state.alignment=o.alignment||'left';state.color=o.color||'#111111';syncControls();input.focus();input.select();status('Editando texto seleccionado');
}
function clearLongPress(){if(state.longPressTimer){clearTimeout(state.longPressTimer);state.longPressTimer=null;}state.longPressStart=null;}
canvas.addEventListener('pointerdown',e=>{
 e.preventDefault();const point=pos(e);
 if(state.tool==='line-eraser'){eraseWholeLine(point.y);return;}
 if(state.tool==='select'){
   const o=hitObject(point);state.selectedId=o?.id||null;
   if(!o){
     state.textCursor={x:Math.max(WRITE_LEFT,Math.min(canvas.width-WRITE_RIGHT,point.x)),y:state.onLine?nearestBaseline(point.y):point.y};
     $('#nbPrompt').value='';render();status('Punto de texto seleccionado');
     state.longPressStart=point;state.longPressTimer=setTimeout(()=>readClipboardImage(point),700);
     return;
   }
   state.textCursor=null;if(o.kind==='text')loadSelectedIntoPrompt(o);
   const b=objectBounds(o),action=selectionAction(point,o);
   if(action==='delete-image'&&o.kind==='image'){snap();state.objects=state.objects.filter(item=>item.id!==o.id);state.selectedId=null;state.dirty=true;render();status('Imagen eliminada');return;}
   snap();state.drag={action,start:point,orig:{x:o.x,y:o.y,width:o.width,height:o.height,rotation:o.rotation||0,size:o.size||28},center:{x:b.x+b.w/2,y:b.y+b.h/2}};render();canvas.setPointerCapture?.(e.pointerId);return;
 }
 snap();state.drawing=true;state.points=[point];state.lineAnchor=state.onLine?{rawY:point.y,baseline:nearestBaseline(point.y)}:null;canvas.setPointerCapture?.(e.pointerId);
});
canvas.addEventListener('pointermove',e=>{
 const point=pos(e);
 if(state.longPressStart&&Math.hypot(point.x-state.longPressStart.x,point.y-state.longPressStart.y)>12)clearLongPress();
 if(state.tool==='select'&&state.drag){
   const o=selected();if(!o)return;const d=state.drag;
   if(d.action==='move'){o.x=(d.orig.x??objectBounds(o).x)+(point.x-d.start.x);o.y=(d.orig.y??objectBounds(o).y)+(point.y-d.start.y);if(o.kind==='stroke'){const dx=point.x-d.start.x,dy=point.y-d.start.y;o.points=o.points.map(p=>({x:p.x+dx,y:p.y+dy}));d.start=point;d.orig={x:0,y:0};}}
   else if(d.action==='resize'&&o.kind==='image'){const ratio=d.orig.width/Math.max(1,d.orig.height),nw=Math.max(40,d.orig.width+(point.x-d.start.x));o.width=nw;o.height=Math.max(40,nw/ratio);}
   else if(d.action==='rotate'&&o.kind==='image'){const a0=Math.atan2(d.start.y-d.center.y,d.start.x-d.center.x),a1=Math.atan2(point.y-d.center.y,point.x-d.center.x);o.rotation=d.orig.rotation+(a1-a0)*180/Math.PI;}
   else if(d.action==='text-resize'&&o.kind==='text'){o.size=Math.max(12,Math.min(96,d.orig.size+(point.x-d.start.x)/3));state.fontSize=Math.round(o.size);$('#nbFontSize').value=state.fontSize;}
   state.dirty=true;render();return;
 }
 if(!state.drawing)return;state.points.push(point);render();drawStroke({kind:'stroke',tool:state.tool,color:state.color,size:state.size,points:previewPoints()});
});
canvas.addEventListener('pointerup',()=>{clearLongPress();if(state.tool==='select'&&state.drag){state.drag=null;render();return;}if(!state.drawing)return;state.drawing=false;const points=previewPoints(),baseline=state.lineAnchor?.baseline??null,stroke={kind:'stroke',id:uid('s'),tool:state.tool,color:state.color,size:state.size,points,onLine:state.onLine,baseline,ocrPending:state.onLine&&!['eraser','line-eraser'].includes(state.tool)};state.objects.push(stroke);state.points=[];state.lineAnchor=null;state.dirty=true;render();if(stroke.ocrPending&&baseline!==null)scheduleLineRecognition(baseline);});
$('#nbTool').addEventListener('change',e=>{state.tool=e.target.value;state.selectedId=null;render();});
$('#nbFont').addEventListener('change',e=>{state.font=e.target.value;const o=selected();if(o?.kind==='text'){snap();o.font=state.font;state.dirty=true;render();}});
$('#nbFontSize').addEventListener('input',e=>{state.fontSize=Math.max(12,Math.min(96,+e.target.value||28));const o=selected();if(o?.kind==='text'){snap();o.size=state.fontSize;state.dirty=true;render();}});
$('#nbZoom').addEventListener('change',e=>{state.zoom=Math.max(25,Math.min(200,+e.target.value||50));applyZoom();});
$('#nbColor').addEventListener('input',e=>{state.color=e.target.value;const o=selected();if(o?.kind==='text'){snap();o.color=state.color;state.dirty=true;render();}});
$('#nbSize').addEventListener('input',e=>state.size=+e.target.value);
$('#nbBackground').addEventListener('change',e=>{state.background=e.target.value;state.dirty=true;render();});
$('#nbPaper').addEventListener('change',e=>{state.paper=e.target.value;state.dirty=true;canvasSize();});
$('#nbOrientation').addEventListener('change',e=>{state.orientation=e.target.value;state.dirty=true;canvasSize();});
$('#nbOnLine').addEventListener('change',e=>{state.onLine=e.target.checked;state.dirty=true;});
$('#nbAlignment').addEventListener('change',e=>{state.alignment=e.target.value;const o=selected();if(o?.kind==='text'){snap();o.alignment=state.alignment;state.dirty=true;render();}});
$('#nbUndo').onclick=()=>{if(!state.undo.length)return;state.redo.push(JSON.stringify(state.objects));state.objects=JSON.parse(state.undo.pop());state.selectedId=null;state.dirty=true;render();};
$('#nbRedo').onclick=()=>{if(!state.redo.length)return;state.undo.push(JSON.stringify(state.objects));state.objects=JSON.parse(state.redo.pop());state.selectedId=null;state.dirty=true;render();};
$('#nbClear').onclick=()=>{if(!confirm('¿Borrar todo lo escrito en esta hoja?'))return;snap();state.objects=[];state.selectedId=null;state.dirty=true;render();};
$('#nbDeleteSelected').onclick=()=>{if(!state.selectedId)return;snap();state.objects=state.objects.filter(o=>o.id!==state.selectedId);state.selectedId=null;state.dirty=true;render();};
$('#nbEditSelected').onclick=()=>{const o=selected();if(!o||o.kind!=='text'){status('Selecciona un texto para editar.',true);return;}loadSelectedIntoPrompt(o);};
$('#nbApplyEdit').onclick=e=>{e.preventDefault();const o=selected();if(!o||o.kind!=='text')return;snap();o.text=$('#nbEditText').value;o.font=state.font;o.size=state.fontSize;o.alignment=state.alignment;o.color=state.color;state.dirty=true;$('#nbEditDialog').close();render();status('Texto actualizado');};
function nextTextY(size){const textObjects=state.objects.filter(o=>o.kind==='text');if(!textObjects.length)return state.onLine?WRITE_TOP:80;const last=textObjects[textObjects.length-1],maxWidth=Math.max(80,canvas.width-WRITE_RIGHT-(last.x??WRITE_LEFT)),count=Math.max(1,layoutText(last.text,last.size||size,maxWidth,last.font||'caveat').length),step=last.onLine===true?RULE_STEP:Math.max((last.size||size)*1.5,RULE_STEP);return(last.y??WRITE_TOP)+(count*step);}
$('#nbWritePrompt').onclick=()=>{
 const input=$('#nbPrompt'),text=input.value.trim();if(!text)return;
 const current=selected();
 if(current?.kind==='text'){snap();current.text=text;current.font=state.font;current.size=state.fontSize;current.alignment=state.alignment;current.color=state.color;state.dirty=true;input.value='';state.selectedId=null;render();status('Texto actualizado');return;}
 const size=state.fontSize,cursor=state.textCursor,y=cursor?.y??nextTextY(size),x=cursor?.x??WRITE_LEFT;
 if(y>canvas.height-WRITE_BOTTOM){status('No queda espacio en esta hoja para más texto.',true);return;}
 snap();state.objects.push({kind:'text',id:uid('t'),text,x,y:state.onLine?nearestBaseline(y):y,color:state.color,size,onLine:state.onLine,alignment:state.alignment,font:state.font});
 input.value='';state.textCursor=null;state.dirty=true;render();
};
$('#nbPrompt').addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();$('#nbWritePrompt').click();}});
function processImageSource(src,mode,done){
 const img=new Image();img.onload=()=>{const max=1400,ratio=Math.min(1,max/img.width,max/img.height),w=Math.max(1,Math.round(img.width*ratio)),h=Math.max(1,Math.round(img.height*ratio)),out=document.createElement('canvas');out.width=w;out.height=h;const c2=out.getContext('2d');c2.drawImage(img,0,0,w,h);
   if(mode!=='normal'){const im=c2.getImageData(0,0,w,h),d=im.data,gray=new Uint8ClampedArray(w*h);for(let i=0,p=0;i<d.length;i+=4,p++)gray[p]=Math.round(.299*d[i]+.587*d[i+1]+.114*d[i+2]);
     for(let y=0;y<h;y++)for(let x=0;x<w;x++){const p=y*w+x,i=p*4,g=gray[p];let v=g;
       if(mode==='gray')v=g;
       else{const l=gray[y*w+Math.max(0,x-1)],r=gray[y*w+Math.min(w-1,x+1)],u=gray[Math.max(0,y-1)*w+x],dn=gray[Math.min(h-1,y+1)*w+x],edge=Math.min(255,Math.abs(r-l)+Math.abs(dn-u));v=mode==='outline'?(edge>38?0:255):Math.max(0,255-edge*1.8-(255-g)*.18);}
       d[i]=d[i+1]=d[i+2]=v;d[i+3]=255;
     }c2.putImageData(im,0,0);
   }
   done(out.toDataURL('image/png'),w,h);
 };img.src=src;
}
function insertProcessedImage(src,naturalW,naturalH,atPoint=null){
 const maxW=canvas.width*.6,maxH=canvas.height*.5,ratio=Math.min(1,maxW/naturalW,maxH/naturalH),width=Math.max(40,naturalW*ratio),height=Math.max(40,naturalH*ratio),point=atPoint||{x:canvas.width/2,y:canvas.height/2};
 snap();const obj={kind:'image',id:uid('i'),src,x:Math.max(0,Math.min(canvas.width-width,point.x-width/2)),y:Math.max(0,Math.min(canvas.height-height,point.y-height/2)),width,height,rotation:0};
 state.objects.push(obj);state.selectedId=obj.id;state.textCursor=null;state.tool='select';$('#nbTool').value='select';state.dirty=true;render();status('Imagen pegada: mueve, redimensiona o gira.');
}
function pasteImage(file,atPoint=null){
 if(!file||!file.type.startsWith('image/'))return;if(file.size>8*1024*1024){status('La imagen copiada excede 8 MB.',true);return;}
 const reader=new FileReader();reader.onload=()=>processImageSource(String(reader.result||''),$('#nbPasteMode').value,(src,w,h)=>insertProcessedImage(src,w,h,atPoint));reader.readAsDataURL(file);
}
async function readClipboardImage(atPoint=null){
 clearLongPress();
 if(!navigator.clipboard?.read){status('Este navegador no permite leer imágenes del portapapeles con toque. Usa Pegar del sistema.',true);return;}
 try{const items=await navigator.clipboard.read();for(const item of items){const type=item.types.find(t=>t.startsWith('image/'));if(!type)continue;pasteImage(await item.getType(type),atPoint);return;}status('No hay una imagen en el portapapeles.',true);}
 catch(e){status('El navegador bloqueó el portapapeles. Autoriza acceso o usa Pegar.',true);}
}
document.addEventListener('paste',e=>{const items=[...(e.clipboardData?.items||[])],imageItem=items.find(i=>i.kind==='file'&&i.type.startsWith('image/'));if(!imageItem)return;const file=imageItem.getAsFile();if(!file)return;e.preventDefault();pasteImage(file,state.textCursor);});
$('#nbPasteImage').onclick=()=>readClipboardImage(state.textCursor);
$('#nbImproveAi').onclick=async()=>{
 if(!state.notebook){status('Crea o selecciona una libreta primero.',true);return;}
 const button=$('#nbImproveAi');button.disabled=true;
 const previousSelected=state.selectedId;state.selectedId=null;render();status('Bedrock está revisando la hoja…');
 try{
   const j=await api('improve_ai',{method:'POST',body:JSON.stringify({
     notebook:state.notebook,
     page:state.page,
     png:canvas.toDataURL('image/png'),
     json:serialize()
   })});
   const improved=JSON.parse(j.json);
   if(!Array.isArray(improved.objects))throw new Error('Bedrock devolvió una hoja inválida.');
   snap();state.objects=improved.objects;normalizeObjects();state.selectedId=null;state.textCursor=null;state.dirty=true;
   render();status(j.summary||'Hoja mejorada con IA');
 }catch(e){
   state.selectedId=previousSelected;render();status(e.message||'No se pudo mejorar la hoja.',true);
 }finally{button.disabled=false;}
};
function normalizeObjects(){for(const o of state.objects){if(!o.id)o.id=uid(o.kind==='text'?'t':o.kind==='image'?'i':'s');if(o.kind==='text'){o.font=o.font||'caveat';o.size=o.size||28;}if(o.kind==='image'){o.rotation=Number(o.rotation)||0;}}}
function serialize(){return JSON.stringify({version:7,page:state.page,pages:state.pages,paper:state.paper,orientation:state.orientation,background:state.background,onLine:state.onLine,alignment:state.alignment,font:state.font,fontSize:state.fontSize,objects:state.objects});}
async function save(){if(!state.notebook)return;status('Guardando PNG…');try{state.selectedId=null;render();const j=await api('save_page',{method:'POST',body:JSON.stringify({notebook:state.notebook,page:state.page,png:canvas.toDataURL('image/png'),json:serialize()})});state.dirty=false;status(j.transcription_error?'Guardada · transcripción pendiente':'Guardada · transcrita');}catch(e){status(e.message,true);}}
$('#nbSave').onclick=save;
async function loadPage(){status('Cargando…');state.objects=[];state.selectedId=null;render();try{const u='notebook-api.php?action=load_page&notebook='+encodeURIComponent(state.notebook)+'&page='+state.page,r=await fetch(u,{credentials:'same-origin'}),j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'No se pudo cargar');if(!j.empty){const d=JSON.parse(j.json);state.objects=Array.isArray(d.objects)?d.objects:[];normalizeObjects();state.paper=d.paper||state.paper;state.orientation=d.orientation||state.orientation;state.background=d.background||state.background;state.onLine=d.onLine===true;state.alignment=['left','right','justify'].includes(d.alignment)?d.alignment:'left';state.font=['caveat','dancing','shadows','sans','serif','mono','rounded'].includes(d.font)?d.font:'caveat';state.fontSize=Math.max(12,Math.min(96,+d.fontSize||28));state.zoom=50;syncControls();canvasSize();}state.dirty=false;status(j.empty?'Hoja nueva':'Hoja cargada');}catch(e){status(e.message,true);}}
function syncControls(){$('#nbTool').value=state.tool;$('#nbFont').value=state.font;$('#nbFontSize').value=state.fontSize;$('#nbZoom').value=String(state.zoom);$('#nbBackground').value=state.background;$('#nbPaper').value=state.paper;$('#nbOrientation').value=state.orientation;$('#nbOnLine').checked=state.onLine;$('#nbAlignment').value=state.alignment;$('#nbPage').value=state.page;$('#nbPageLabel').textContent=state.page;$('#nbMenuPage').textContent=state.page;$('#nbTotal').textContent=state.pages;$('#nbMenuTotal').textContent=state.pages;}
async function gotoPage(n){n=Math.max(1,Math.min(state.pages,n));if(n===state.page)return;if(state.dirty&&confirm('La hoja tiene cambios. ¿Guardarla antes de cambiar?'))await save();state.page=n;syncControls();await loadPage();canvas.parentElement.classList.remove('is-turning');void canvas.parentElement.offsetWidth;canvas.parentElement.classList.add('is-turning');}
$('#nbPrev').onclick=()=>gotoPage(state.page-1);$('#nbNext').onclick=()=>gotoPage(state.page+1);$('#nbPage').onchange=e=>gotoPage(+e.target.value||1);
async function refreshBooks(select=''){const j=await api('list');state.notebooks=j.notebooks||[];const sel=$('#nbNotebook');sel.replaceChildren();for(const b of state.notebooks){const o=document.createElement('option');o.value=b.name;o.textContent=b.name;sel.append(o);}if(select&&state.notebooks.some(b=>b.name===select))sel.value=select;state.notebook=sel.value||'';const current=state.notebooks.find(b=>b.name===state.notebook);if(current){state.pages=+current.pages||100;state.paper=current.paper||'letter';state.orientation=current.orientation||'portrait';state.background=current.background||'blank';syncControls();canvasSize();}$('#nbMoveTarget').replaceChildren(...state.notebooks.filter(b=>b.name!==state.notebook).map(b=>{const o=document.createElement('option');o.value=b.name;o.textContent=b.name;return o;}));if(state.notebook)await loadPage();else status('Crea tu primera libreta');}
$('#nbNotebook').onchange=async e=>{if(state.dirty&&confirm('¿Guardar cambios antes de cambiar de libreta?'))await save();state.notebook=e.target.value;state.page=1;const current=state.notebooks.find(b=>b.name===state.notebook);if(current){state.pages=+current.pages||100;state.paper=current.paper||'letter';state.orientation=current.orientation||'portrait';state.background=current.background||'blank';canvasSize();}syncControls();await loadPage();};
$('#nbNewBook').onclick=()=>$('#nbNewDialog').showModal();
$('#nbCreateBook').onclick=async e=>{e.preventDefault();try{const name=$('#nbNewName').value.trim(),pages=+$('#nbNewPages').value||100;await api('create',{method:'POST',body:JSON.stringify({name,pages,paper:state.paper,orientation:state.orientation,background:state.background})});state.pages=pages;$('#nbNewDialog').close();await refreshBooks(name);}catch(err){status(err.message,true);}};
$('#nbMovePage').onclick=()=>{if(!state.notebook)return;if($('#nbMoveTarget').options.length===0){status('No hay otra libreta destino',true);return;}$('#nbMoveDialog').showModal();};
$('#nbConfirmMove').onclick=async e=>{e.preventDefault();try{if(state.dirty)await save();const target=$('#nbMoveTarget').value;await api('move_page',{method:'POST',body:JSON.stringify({notebook:state.notebook,page:state.page,target})});$('#nbMoveDialog').close();state.objects=[];state.dirty=false;render();status('Hoja movida a '+target);}catch(err){status(err.message,true);}};
document.querySelectorAll('.nb-menu').forEach(menu=>menu.addEventListener('toggle',()=>{
 if(!menu.open)return;
 document.querySelectorAll('.nb-menu').forEach(other=>{if(other!==menu)other.open=false;});
}));
document.addEventListener('pointerdown',e=>{
 if(!e.target.closest('.nb-menu'))document.querySelectorAll('.nb-menu[open]').forEach(menu=>menu.open=false);
});
window.addEventListener('beforeunload',e=>{if(!state.dirty)return;e.preventDefault();e.returnValue='';});
canvasSize();syncControls();refreshBooks().catch(e=>status(e.message,true));
})();