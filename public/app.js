document.querySelectorAll('[data-countdown]').forEach(el => {
    const target=Date.parse(el.dataset.countdown), base=Date.parse(el.dataset.serverNow), start=performance.now();
    function render(){const seconds=Math.max(0,Math.ceil((target-base-(performance.now()-start))/1000));
        el.textContent=seconds ? `${Math.floor(seconds/3600)} 時 ${Math.floor(seconds%3600/60)} 分 ${seconds%60} 秒` : '時間已到，請重新整理頁面確認狀態';}
    render(); setInterval(render,1000);
});
document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',e=>{if(!confirm(form.dataset.confirm))e.preventDefault();}));
