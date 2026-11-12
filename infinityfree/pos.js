'use strict';
const $=id=>document.getElementById(id);
let csrfToken='', data={tables:[],products:[],sets:[],casts:[]};
const api=async(route,method='GET',body=null)=>{
 const headers={Accept:'application/json'};if(body!==null)headers['Content-Type']='application/json';if(method!=='GET')headers['X-CSRF-Token']=csrfToken;
 const [routePath,routeQuery]=route.split('?',2);const url='api.php?r='+encodeURIComponent(routePath)+(routeQuery?'&'+routeQuery:'');const res=await fetch(url,{method,credentials:'same-origin',headers,body:body===null?undefined:JSON.stringify(body)});
 const json=await res.json().catch(()=>({error:'応答を読み取れません'}));if(!res.ok)throw new Error(json.error||'HTTP '+res.status);return json;
};
const say=v=>{$('message').textContent=typeof v==='string'?v:JSON.stringify(v,null,2)};
const key=()=>crypto.randomUUID();
const dateNow=()=>{const d=new Date();return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10)};
const fill=(id,rows,label)=>{const el=$(id);el.replaceChildren();for(const r of rows){const o=document.createElement('option');o.value=r.id;o.textContent=label(r);el.append(o)}};
async function reload(){
 const [tables,products,sets,casts,settings]=await Promise.all(['tables','products','sets','casts','settings'].map(r=>api(r)));
 data={tables:tables.data,products:products.data,sets:sets.data,casts:casts.data};
 fill('product',data.products,p=>p.product_name+' ¥'+p.selling_price);fill('setmenu',data.sets,s=>s.name+' / '+s.duration_minutes+'分 ¥'+s.price);
 fill('cast',data.casts,c=>c.cast_name);fill('attcast',data.casts,c=>c.cast_name);fill('paycast',data.casts,c=>c.cast_name);
 fill('editproduct',data.products,p=>p.product_name);fill('editset',data.sets,s=>s.name);fill('editcast',data.casts,c=>c.cast_name);fill('edittable',data.tables,t=>t.table_name);
 $('tables').replaceChildren();
 for(const t of data.tables){const b=document.createElement('button');b.className='table '+t.status;b.textContent=t.table_name+'\n'+t.status+(t.session_id?'\n#'+t.session_id:'');b.onclick=()=>{if(t.session_id){$('session').value=t.session_id;$('ordersession').value=t.session_id;$('billsession').value=t.session_id;say(t.table_name+' / セッション '+t.session_id)}else{$('tableid').value=t.id}};$('tables').append(b)}
 const s=settings.data||{};for(const [id,k] of [['taxenabled','tax_enabled'],['serviceenabled','service_enabled'],['servicetaxable','service_taxable']])$(id).checked=!!Number(s[k]);
 for(const [id,k] of [['taxrate','tax_rate'],['taxmode','tax_mode'],['servicerate','service_rate'],['servicemode','service_mode'],['servicefixed','service_fixed_amount'],['servicebasis','service_basis'],['salesroundmode','rounding_mode'],['roundminutes','work_rounding_minutes'],['roundmode','work_rounding_mode']])if(s[k]!==undefined)$(id).value=s[k];
}
async function run(fn){try{say(await fn());await reload()}catch(e){say(e.message)}}
$('refresh').onclick=()=>run(reload);
$('seat').onclick=()=>run(async()=>{const r=await api('sessions','POST',{table_id:+$('tableid').value,customer_count:+$('people').value,idempotency_key:key()});const sid=r.data?.session_id??r.data?.id;if(sid){$('session').value=sid;$('ordersession').value=sid;$('billsession').value=sid}return r});
$('addset').onclick=()=>run(async()=>api('sessions/'+$('session').value+'/sets','POST',{set_menu_id:+$('setmenu').value,idempotency_key:key()}));
$('order').onclick=()=>run(async()=>{const ids=[...$('cast').selectedOptions].map(o=>+o.value);const raw=$('backrates').value.trim();const rates=raw?raw.split(',').map(x=>Number(x.trim())):ids.map(()=>100/Math.max(ids.length,1));if(rates.length!==ids.length||rates.some(x=>!Number.isFinite(x)||x<0||x>100)||rates.reduce((a,b)=>a+b,0)>100.00001)throw Error('配分率を選択キャスト数と合わせ、合計100%以下にしてください');const allocations=ids.map((id,i)=>({cast_id:id,allocation_rate:rates[i]}));return api('orders','POST',{session_id:+$('ordersession').value,idempotency_key:key(),items:[{product_id:+$('product').value,quantity:+$('qty').value,allocations}]})});
$('bill').onclick=()=>run(async()=>{const b=await api('sessions/'+$('billsession').value+'/bill');$('payamount').value=b.balance;say(b);return b});
$('pay').onclick=()=>run(async()=>{const r=await api('payments','POST',{session_id:+$('billsession').value,payment_method:$('method').value,amount:+$('payamount').value,idempotency_key:key()});if(r.payment_id)$('paymentid').value=r.payment_id;return r});
$('refund').onclick=()=>run(async()=>{if(!confirm('返金を登録しますか？'))return;return api('payments/'+$('paymentid').value+'/refund','POST',{amount:+$('refundamount').value,reason:$('refundreason').value,idempotency_key:key()})});
$('clockin').onclick=()=>run(async()=>api('attendance/clock-in','POST',{cast_id:+$('attcast').value}));
$('clockout').onclick=()=>run(async()=>api('attendance/clock-out','POST',{cast_id:+$('attcast').value}));
$('confirmPayroll').onclick=()=>run(async()=>api('casts/'+$('paycast').value+'/payroll/confirm?business_date='+encodeURIComponent($('paydate').value),'POST',{}));
$('expensedate').value=dateNow();$('addexpense').onclick=()=>run(async()=>api('expenses','POST',{business_date:$('expensedate').value,category:$('expensecategory').value,amount:+$('expenseamount').value,description:$('expensedesc').value}));
$('close').onclick=()=>run(async()=>{if(!confirm('日締めを実行しますか？未精算・未退勤・入金差異があれば停止します。'))return;return api('closings/run','POST',{business_date:$('closedate').value})});
$('savesettings').onclick=()=>run(async()=>api('settings','PUT',{tax_enabled:$('taxenabled').checked,tax_rate:+$('taxrate').value,tax_mode:$('taxmode').value,service_enabled:$('serviceenabled').checked,service_rate:+$('servicerate').value,service_mode:$('servicemode').value,service_fixed_amount:+$('servicefixed').value,service_taxable:$('servicetaxable').checked,service_basis:$('servicebasis').value,rounding_mode:$('salesroundmode').value,work_rounding_minutes:+$('roundminutes').value,work_rounding_mode:$('roundmode').value}));
$('addtable').onclick=()=>run(async()=>api('tables','POST',{table_name:$('newtable').value,capacity:+$('capacity').value||4}));
$('addproduct').onclick=()=>run(async()=>api('products','POST',{product_name:$('newproduct').value,selling_price:+$('newprice').value,back_rate:+$('newback').value,back_type:$('newbacktype').value}));
$('addset').dataset.action='session-set';
$('addmasterset').onclick=()=>run(async()=>api('sets','POST',{name:$('newset').value,duration_minutes:+$('newduration').value,price:+$('newsetprice').value,is_extension:$('extension').checked}));
$('addcast').onclick=()=>run(async()=>api('casts','POST',{cast_code:$('newcastcode').value,cast_name:$('newcastname').value,hourly_wage:+$('newwage').value}));
$('updateproduct').onclick=()=>run(async()=>{const p=data.products.find(x=>x.id==$('editproduct').value);if(!p)throw Error('商品を選択してください');return api('products/'+p.id,'PUT',{product_name:$('editproductname').value||p.product_name,selling_price:+($('editproductprice').value||p.selling_price),back_rate:+($('editproductback').value||p.back_rate),back_type:$('editproducttype').value,is_active:true})});
$('stopproduct').onclick=()=>run(async()=>{const p=data.products.find(x=>x.id==$('editproduct').value);if(!p||!confirm('商品を利用停止しますか？'))return;return api('products/'+p.id,'PUT',{product_name:p.product_name,selling_price:+p.selling_price,back_rate:+p.back_rate,back_type:p.back_type,is_active:false})});
$('editproduct').onchange=()=>{const p=data.products.find(x=>x.id==$('editproduct').value);if(p){$('editproductname').value=p.product_name;$('editproductprice').value=p.selling_price;$('editproductback').value=p.back_rate;$('editproducttype').value=p.back_type}};
$('edittable').onchange=()=>{const t=data.tables.find(x=>x.id==$('edittable').value);if(t)$('edittablename').value=t.table_name};
$('updatetable').onclick=()=>run(async()=>{const t=data.tables.find(x=>x.id==$('edittable').value);if(!t)throw Error('卓を選択してください');return api('tables/'+t.id,'PUT',{table_name:$('edittablename').value,capacity:t.capacity,is_active:true})});
$('stopTable').onclick=()=>run(async()=>{const t=data.tables.find(x=>x.id==$('edittable').value);if(!t||!confirm('卓を利用停止しますか？'))return;return api('tables/'+t.id,'PUT',{table_name:t.table_name,capacity:t.capacity,is_active:false})});
$('editset').onchange=()=>{const s=data.sets.find(x=>x.id==$('editset').value);if(s){$('editsetname').value=s.name;$('editsetduration').value=s.duration_minutes;$('editsetprice').value=s.price;$('editextension').checked=!!Number(s.is_extension)}};
$('updateset').onclick=()=>run(async()=>{const s=data.sets.find(x=>x.id==$('editset').value);if(!s)throw Error('セットを選択してください');return api('sets/'+s.id,'PUT',{name:$('editsetname').value,duration_minutes:+$('editsetduration').value,price:+$('editsetprice').value,is_extension:$('editextension').checked,is_active:true})});
$('stopset').onclick=()=>run(async()=>{const s=data.sets.find(x=>x.id==$('editset').value);if(!s||!confirm('セットを利用停止しますか？'))return;return api('sets/'+s.id,'PUT',{name:s.name,duration_minutes:+s.duration_minutes,price:+s.price,is_extension:!!Number(s.is_extension),is_active:false})});
$('editcast').onchange=()=>{const c=data.casts.find(x=>x.id==$('editcast').value);if(c){$('editcastcode').value=c.cast_code;$('editcastname').value=c.cast_name;$('editcastwage').value=c.hourly_wage}};
$('updatecast').onclick=()=>run(async()=>{const c=data.casts.find(x=>x.id==$('editcast').value);if(!c)throw Error('キャストを選択してください');return api('casts/'+c.id,'PUT',{cast_code:$('editcastcode').value,cast_name:$('editcastname').value,hourly_wage:+$('editcastwage').value,is_active:true})});
$('stopcast').onclick=()=>run(async()=>{const c=data.casts.find(x=>x.id==$('editcast').value);if(!c||!confirm('キャストを利用停止しますか？'))return;return api('casts/'+c.id,'PUT',{cast_code:c.cast_code,cast_name:c.cast_name,hourly_wage:+c.hourly_wage,is_active:false})});
async function showAttendance(){const r=await api('attendance');$('attendanceList').textContent=JSON.stringify(r.data,null,2)}
$('refreshAttendance').onclick=()=>run(showAttendance);
$('savebreak').onclick=()=>run(async()=>{const r=await api('attendance/break','POST',{attendance_id:+$('attendanceid').value,break_minutes:+$('breakminutes').value});await showAttendance();return r});
$('paydate').value=dateNow();$('closedate').value=dateNow();
(async()=>{try{const t=await api('csrf');csrfToken=t.csrf;await reload();say('POSに接続しました。')}catch(e){say(e.message)}})();
