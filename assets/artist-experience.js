(() => {
  'use strict';
  const desktop=window.DaveTunesDesktop;
  const bootNode=document.getElementById('dt-artist-experience-boot');
  if(!desktop||!bootNode)return;
  let boot={};
  try{boot=JSON.parse(bootNode.textContent||'{}');}catch(_){}
  const start=()=>{
    desktop.state.experienceManifest=boot.manifest||null;
    desktop.state.experienceHash=boot.sha256||'';
    desktop.emit('experience-loaded',{manifest:desktop.state.experienceManifest,sha256:desktop.state.experienceHash});
    desktop.runCommand('zscroll.enter',{latched:true,source:'artist-experience'}).catch(error=>desktop.emit('experience-error',{error}));
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>setTimeout(start,0),{once:true});
  else setTimeout(start,0);
})();