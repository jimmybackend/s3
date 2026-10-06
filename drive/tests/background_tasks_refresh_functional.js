'use strict';
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync(require('node:path').join(__dirname,'../js/background-tasks.js'),'utf8').replace('BackgroundTaskCenter.boot();','globalThis.TaskCenterClass=BackgroundTaskCenter;');
const context={URL,console:{warn(){}},setTimeout,clearTimeout};vm.createContext(context);vm.runInContext(source,context);
(async()=>{
 let requests=0,resolveResponse;let notifications=0;
 const win={location:{href:'https://fixture.invalid/so.php'},fetch:()=>{requests++;return new Promise(resolve=>resolveResponse=resolve);}};
 const center=new context.TaskCenterClass(win,{});center.tasks=[{id:'media:1',status:'running'}];center.render=()=>{};center.pruneSelection=()=>{};
 center.notify=()=>notifications++;center.refreshDriveIfRelevant=()=>{};
 const waiting=Array.from({length:25},()=>center.refresh());
 assert.equal(requests,1,'25 simultaneous polling/events must share one HTTP request');
 resolveResponse({ok:true,text:async()=>JSON.stringify({ok:true,tasks:[{id:'media:1',status:'completed'}]})});await Promise.all(waiting);
 assert.equal(notifications,1,'completion announced exactly once');assert.equal(center.tasks[0].status,'completed');
 const retry=center.refresh();assert.equal(requests,2,'new refresh available after completed request');resolveResponse({ok:false,status:503,text:async()=>'{"error":"fixture unavailable"}'});await retry;
 assert.equal(center.sourceErrors.endpoint,'fixture unavailable');
 const recovered=center.refresh();assert.equal(requests,3,'failed request releases refresh guard');resolveResponse({ok:true,text:async()=>JSON.stringify({ok:true,tasks:[]})});await recovered;
 assert.equal(center.tasks.length,0,'task deletion appears without page reload');

 const completed=Array.from({length:10},(_,i)=>({
   id:'done:'+i,kind:'sync',category:'Sincronización',title:'Tarea '+(i+1),
   status:'completed',detail:'Terminada',created_at:'2026-10-06T12:00:00Z',
   updated_at:'2026-10-06T12:01:00Z',actions:[]
 }));
 const listHtml=center.taskListHtml(completed);
 assert.match(listHtml,/Mostrando 10 tareas/,'task list exposes visible row count');
 assert.equal((listHtml.match(/class="bg-task-item"/g)||[]).length,10,'ten completed tasks render as ten visible cards');

 const originalTaskHtml=center.taskHtml.bind(center);
 center.taskHtml=(task)=>task.id==='broken' ? (()=>{throw new Error('fixture render failure');})() : originalTaskHtml(task);
 const resilient=center.taskListHtml([completed[0],{...completed[1],id:'broken'}]);
 assert.equal((resilient.match(/class="bg-task-item/g)||[]).length,2,'one malformed task cannot blank the whole list');
 assert.match(resilient,/bg-task-item-fallback/,'malformed task gets a visible fallback card');

 console.log('Background tasks refresh/render: bounded requests, recovery and visible terminal rows OK');
})().catch(e=>{console.error(e);process.exitCode=1;});
