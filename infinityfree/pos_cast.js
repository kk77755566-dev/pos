'use strict';
const $=id=>document.getElementById(id);
let csrfToken='';
async function api(route,method='GET',body=null){
 const headers={Accept:'application/json'};
 if(body!==null)headers['Content-Type']='application/json';
 if(method!=='GET')headers['X-CSRF-Token']=csrfToken;
 const res=await fetch('api.php?r='+encodeURIComponent(route),{method,credentials:'same-origin',headers,body:body===null?undefined:JSON.stringify(body)});
 const json=await res.json().catch(()=>({error:'応答を読み取れません'}));
 if(!res.ok)throw new Error(json.error||'HTTP '+res.status);
 return json;
}
function show(el,value){$(el).textContent=JSON.stringify(value,null,2)}
async function reload(){const me=await api('cast/me');show('castAttendance',me.attendance);show('castPayroll',me.payroll)}
async function run(fn){try{await fn();await reload()}catch(e){alert(e.message)}}
(async()=>{try{const token=await api('csrf');csrfToken=token.csrf;$('castClockIn').onclick=()=>run(()=>api('cast/clock-in','POST',{}));$('castClockOut').onclick=()=>run(()=>api('cast/clock-out','POST',{}));$('castRefresh').onclick=()=>run(reload);await reload()}catch(e){alert(e.message)}})();
