(() => {
    const root=document.getElementById('waiting-room'); if(!root)return;
    const join=document.getElementById('join-queue'), leave=document.getElementById('leave-queue'), submit=document.getElementById('submit-booking');
    const message=document.getElementById('queue-message'), detail=document.getElementById('queue-detail'), tokenField=document.getElementById('queue-token');
    const storageKey='tickethub-queue-'+root.dataset.type;
    let token=sessionStorage.getItem(storageKey)||'', timer=null, until=0, polling=false, failures=0;
    async function request(url, bearer=false) {
        const response=await fetch(url,{method:'POST',credentials:bearer?'omit':'same-origin',headers:{Accept:'application/json',...(bearer?{Authorization:'Bearer '+token}:{'X-CSRF-TOKEN':root.dataset.csrf})}});
        let data; try{data=await response.json();}catch{throw new Error('服務暫時無法使用，請稍後重試。');}
        if(!response.ok){const e=new Error(data.message||(data.state==='conflict'?'你正在候位另一種票券，請先離開原候位室。':data.state==='full'?'候位人數已滿，請稍後再試。':'請稍候重試。'));e.status=response.status;throw e;}
        return data;
    }
    function stop(){clearTimeout(timer);timer=null;submit.disabled=true;until=0;}
    function render(data) {
        submit.disabled=true; leave.hidden=false;
        if(data.queue_token){token=data.queue_token;sessionStorage.setItem(storageKey,token);tokenField.value=token;}
        const state=data.state;
        if(state==='admitted'){message.textContent='已取得報名資格';until=performance.now()+Math.max(0,data.expires_ms-data.server_ms);submit.disabled=false;join.hidden=true;}
        else if(state==='waiting'){message.textContent=`候位中，目前第 ${data.position} 位`;detail.textContent='依序分批放行，請勿重複整理頁面。';join.hidden=true;until=0;}
        else if(state==='processing'){message.textContent='訂單處理中，請先查看「我的訂單」。';detail.textContent='請勿重複送出；如連線中斷，稍後確認結果。';until=0;join.hidden=true;}
        else {message.textContent='候位已結束或資格已逾期。';detail.textContent='如果剛才送出過報名，請先查閱我的訂單。';stop();token='';sessionStorage.removeItem(storageKey);join.hidden=false;leave.hidden=true;return;}
        clearTimeout(timer);timer=setTimeout(poll,4000+Math.random()*2500);
    }
    async function poll(){if(!token||polling)return;polling=true;try{render(await request(root.dataset.status,true));failures=0;}catch(e){submit.disabled=true;message.textContent=e.message;if(e.status===403){stop();sessionStorage.removeItem(storageKey);token='';join.hidden=false;leave.hidden=true;}else{clearTimeout(timer);timer=setTimeout(poll,Math.min(30000,5000*2**Math.min(failures++,3)));}}finally{polling=false;}}
    join.addEventListener('click',async()=>{join.disabled=true;try{render(await request(root.dataset.join));}catch(e){message.textContent=e.message;}finally{join.disabled=false;}});
    leave.addEventListener('click',async()=>{try{const data=await request(root.dataset.leave,true);render(data);}catch(e){message.textContent=e.message;}});
    document.getElementById('queued-booking').addEventListener('submit',e=>{if(submit.disabled || !until || performance.now()>=until){e.preventDefault();return;}submit.disabled=true;clearTimeout(timer);});
    setInterval(()=>{if(until){const seconds=Math.max(0,Math.ceil((until-performance.now())/1000));detail.textContent=`請在 ${seconds} 秒內送出報名。`;if(!seconds)submit.disabled=true;}},250);
    if(token){tokenField.value=token;join.hidden=true;poll();}
})();
