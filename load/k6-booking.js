import http from 'k6/http';
import {sleep,check} from 'k6';
import {Counter,Trend} from 'k6/metrics';
const base=__ENV.BASE_URL || 'http://localhost:8080';
const type=__ENV.TICKET_TYPE_ID, prefix=__ENV.USER_PREFIX, password=__ENV.LOAD_TEST_PASSWORD;
if(!type || !prefix || !password) throw new Error('Set TICKET_TYPE_ID, USER_PREFIX, LOAD_TEST_PASSWORD and use an isolated load database.');
const unexpected=new Counter('unexpected_errors'), booked=new Counter('booked'), soldOut=new Counter('sold_out'), queueExpired=new Counter('queue_expired'), waitTime=new Trend('queue_wait_ms');
export const options={
    scenarios:{buyers:{executor:'per-vu-iterations',vus:Number(__ENV.VUS||100),iterations:1,maxDuration:__ENV.MAX_DURATION||'10m'}},
    thresholds:{unexpected_errors:['count==0'],http_req_duration:['p(95)<2000']},
};
function csrf(response){return response.html().find('input[name="_token"]').first().attr('value');}
function fail(message){unexpected.add(1);console.error(message);}
export default function(){
    unexpected.add(0);
    const email=prefix+__VU+'@example.test';
    let response=http.get(base+'/login',{tags:{name:'GET login'}}), token=csrf(response);
    if(response.status!==200||!token){fail('Cannot load login');return;}
    response=http.post(base+'/login',{_token:token,email,password},{redirects:0,tags:{name:'POST login'}});
    if(response.status!==302 || String(response.headers.Location||'').includes('/login')){fail('Login failed: '+response.status);return;}
    response=http.get(base+'/waiting/'+type,{tags:{name:'GET waiting'}}); token=csrf(response);
    const requestKey=response.html().find('input[name="request_key"]').first().attr('value');
    if(response.status!==200||!token||!requestKey){fail('Cannot load waiting room');return;}
    response=http.post(base+'/waiting/'+type+'/join',{_token:token},{headers:{Accept:'application/json'},tags:{name:'POST join'}});
    if(response.status!==200){fail('Join failed: '+response.status);return;}
    let state=response.json(), capability=state.queue_token; const began=Date.now();
    if(!capability){fail('No queue capability');return;}
    while(state.state==='waiting'){
        sleep(4+Math.random()*2);
        response=http.post(base+'/api/admission/status',null,{headers:{Authorization:'Bearer '+capability,Accept:'application/json'},tags:{name:'POST poll'}});
        if(response.status!==200){fail('Poll failed: '+response.status);return;}
        state=response.json();
        if(Date.now()-began>540000){fail('Waiting room timeout');return;}
    }
    if(state.state!=='admitted'){queueExpired.add(1);return;}
    waitTime.add(Date.now()-began);
    response=http.post(base+'/ticket-types/'+type+'/orders',{_token:token,queue_token:capability,request_key:requestKey,quantity:1,attendee_name:'Load '+__VU,phone:'0912345678',contact_email:email},{redirects:0,headers:{Accept:'application/json'},tags:{name:'POST booking'}});
    if(response.status===302 && /\/orders\/[0-9a-f-]{36}$/.test(String(response.headers.Location||''))){booked.add(1);}
    else if(response.status===422 && JSON.stringify(response.json('errors.booking')||[]).includes('名額不足')){soldOut.add(1);}
    else if(response.status===429){queueExpired.add(1);}
    else{fail('Booking failed unexpectedly: '+response.status);}
}
export function handleSummary(data){return {'load-results.json':JSON.stringify(data,null,2)};}
