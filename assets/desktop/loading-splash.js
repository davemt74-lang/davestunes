(() => {
  'use strict';
  const overlay=document.querySelector('[data-desktop-splash]');
  if(!overlay)return;

  const mode=overlay.dataset.splashMode||'public';
  const pending=new Set(mode==='desktop'
    ? ['window','desktop','library','featured','objects']
    : ['window','content']);

  let released=false;
  const mark=key=>{
    pending.delete(key);
    if(!pending.size)release();
  };
  const release=()=>{
    if(released)return;
    released=true;
    const root=document.getElementById('dt-desktop-root');
    if(root)root.dataset.desktopReady='true';
    overlay.dataset.splashState='leaving';
    const done=()=>{
      overlay.remove();
      document.documentElement.classList.remove('desktop-loading');
      document.dispatchEvent(new CustomEvent('davestunes:desktop:splash-cleared'));
    };
    overlay.addEventListener('animationend',done,{once:true});
    setTimeout(done,900);
  };

  document.documentElement.classList.add('desktop-loading');

  if(document.readyState==='complete')mark('window');
  else window.addEventListener('load',()=>mark('window'),{once:true});

  if(mode==='public'){
    requestAnimationFrame(()=>requestAnimationFrame(()=>mark('content')));
  }else{
    const listen=(name,key)=>document.addEventListener('davestunes:desktop:'+name,()=>mark(key),{once:true});
    listen('ready','desktop');
    listen('library-loaded','library');
    listen('library-error','library');
    listen('featured-loaded','featured');
    listen('featured-error','featured');
    listen('objects-loaded','objects');
    listen('object-error','objects');
  }
})();