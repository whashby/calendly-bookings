const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const callbacks=[],calls=[],nodes={};let charts=0;
const fixtures={
 health:{calendly_api:'Last sync succeeded',last_sync:'2026-10-04T21:30:00Z',last_attempt:'2026-10-04T21:30:00Z',errors:{},schedules:{master:{enabled:false},scheduled_events:{enabled:true,frequency_label:'Every 5 Minutes',next_run:1791149400,last_success:'2026-10-04T21:30:00Z'},invitees:{enabled:false},event_types:{enabled:false},locations:{enabled:false}},timezone:'America/Barbados',wp_cron_disabled:false},
 availability:[{name:'<script>bad</script>',slots:['2026-10-05T14:15:00Z']}],
 integrity:{missing_uuid:[],duplicates:[]},
 revenue:{period:'1M',total_revenue:65,currency:'USD',revenue_basis:'Net lines less refunds',events:[{name:'Fixture',revenue:65,last_booking:'2026-10-04T21:30:00Z'}],excluded_currency_orders:0},
 performance:[{name:'Fixture',bookings:1,revenue:65}],
 'recent-bookings':[{invitee:'Fixture',event_name:'Fixture',scheduled:'2026-10-04T21:30:00Z',status:'completed'}],
 trends:[{day:'2026-10-03',count:1}]
};
const document={hidden:false,addEventListener:(event,fn)=>callbacks.push(fn),createElement:()=>({textContent:'',get innerHTML(){return this.textContent.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}}),getElementById:id=>nodes[id]||(nodes[id]={innerHTML:'',textContent:'',classList:{add(){},remove(){}},setAttribute(){},hasChildNodes(){return false;},querySelectorAll(){return [];},addEventListener(){},getContext(){return {};},parentElement:{querySelector(){return null;}},after(){}})};
const context={document,console,URL,URLSearchParams,Date,Intl,Promise,Number,CB_REST:{root:'http://localhost/wp-json/calendly-bookings/v1/',nonce:'fixture',timezone:'America/Barbados',utc_offset:-240,currency:'USD'},window:{location:{href:'http://localhost/wp-admin/index.php'},setInterval(){},alert(){}},Chart:class{constructor(){charts++;}destroy(){}},fetch:async(url,options)=>{calls.push({url:String(url),options});const key=new URL(url).pathname.split('/').pop();return {ok:true,json:async()=>fixtures[key]||{status:'success',message:'Sync complete'}};}};
vm.createContext(context);vm.runInContext(fs.readFileSync('includes/admin/assets/dashboard-widgets.js','utf8'),context);vm.runInContext(fs.readFileSync('includes/admin/assets/dashboard-charts.js','utf8'),context);
(async()=>{
 assert.match(context.formatLocalTime('2026-10-04T21:30:00Z'),/5:30\s*PM/i);console.log('PASS: Browser formatter displays afternoon sync as PM in site timezone');
 context.CB_REST.timezone='-04:00';assert.match(context.formatLocalTime('2026-10-04T21:30:00Z'),/5:30\s*PM/i);context.CB_REST.timezone='America/Barbados';console.log('PASS: Fixed-offset WordPress timezone is supported');
 assert.equal(context.formatLocalTime(null),'Never');assert.equal(context.formatLocalTime('Never'),'Unknown');console.log('PASS: Missing and invalid times do not display Invalid Date');
 callbacks.forEach(fn=>fn());await new Promise(setImmediate);
 assert.equal(calls.length,7);assert.equal(charts,1);console.log('PASS: All seven widgets load once and only one chart is created');
 assert.match(nodes['cb-widget-health'].innerHTML,/Every 5 Minutes/);assert.match(nodes['cb-widget-health'].innerHTML,/5:30\s*PM/i);console.log('PASS: Health widget renders schedule frequency and afternoon sync');
 assert.ok(!nodes['cb-widget-availability'].innerHTML.includes('<script>'));assert.match(nodes['cb-widget-availability'].innerHTML,/&lt;script&gt;/);console.log('PASS: Widget text is escaped');
 await context.apiFetch('dashboard/sync',{method:'POST'});assert.equal(calls.at(-1).options.method,'POST');assert.equal(calls.at(-1).options.headers['X-WP-Nonce'],'fixture');console.log('PASS: Manual sync sends POST and authenticated nonce');
 context.fetch=async()=>({ok:false,json:async()=>({message:'Fixture failure'})});await assert.rejects(context.apiFetch('dashboard/health'),/Fixture failure/);console.log('PASS: API failure rejects rather than reporting success');
 await context.renderHealthWidget();assert.equal(nodes['cb-widget-health'].textContent,'Fixture failure');console.log('PASS: Widget displays request failure');
 context.CB_REST.root='http://localhost/?rest_route=/calendly-bookings/v1/';context.fetch=async url=>{const u=new URL(url);assert.equal(u.searchParams.get('rest_route'),'/calendly-bookings/v1/dashboard/revenue');assert.equal(u.searchParams.get('months'),'3');return{ok:true,json:async()=>({})};};await context.apiFetch('dashboard/revenue?months=3');console.log('PASS: Plain-permalink REST URLs preserve route and filters');
 console.log('10 client widget checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
