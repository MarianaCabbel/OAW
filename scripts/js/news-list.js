(function(){
  function revealContent(){
    document.body && document.body.classList.remove('preload-news');
  }
  document.readyState==='loading'
    ? document.addEventListener('DOMContentLoaded',revealContent)
    : revealContent();
  
  function bindModal(openId,closeId,modalId){
    var open=document.getElementById(openId);
    var close=document.getElementById(closeId);
    var modal=document.getElementById(modalId);
    if(!open||!close||!modal) return;
    open.addEventListener('click',function(){modal.classList.add('is-open');});
    close.addEventListener('click',function(){modal.classList.remove('is-open');});
    modal.addEventListener('click',function(e){e.target===modal&&modal.classList.remove('is-open');});
  }

  bindModal('openSettings','closeSettings','settingsModal');
  bindModal('openFilters','closeFilters','filtersModal');
})();
