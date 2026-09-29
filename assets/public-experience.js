(() => {
  'use strict';
  const desktop=window.DaveTunesDesktop;
  const bootNode=document.getElementById('dt-public-experience-boot');
  if(!desktop||!bootNode)return;

  let boot={};
  try{boot=JSON.parse(bootNode.textContent||'{}');}catch(_){}

  const fail=message=>{
    const mount=document.querySelector('[data-zscroll-scenes]');
    if(mount){
      mount.replaceChildren();
      const section=document.createElement('section');
      section.className='z-scroll-scene';
      section.style.opacity='1';
      const box=document.createElement('div');
      box.className='experience-scene-runtime';
      const title=document.createElement('h2');
      title.textContent='Experience unavailable';
      const copy=document.createElement('p');
      copy.className='experience-text';
      copy.textContent=String(message||'This experience could not be loaded.');
      box.append(title,copy);section.append(box);mount.append(section);
    }
    desktop.emit('experience-error',{error:new Error(String(message||'Experience unavailable'))});
  };

  const start=async()=>{
    const url=String(boot.deliveryUrl||'');
    const expectedHash=String(boot.sha256||'');
    const expectedVersion=Number(boot.versionNumber||0);
    if(!url||!/^[a-f0-9]{64}$/.test(expectedHash)||expectedVersion<1){
      fail('Published experience identity is invalid.');
      return;
    }
    try{
      const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
      if(response.status===304)return;
      const json=await response.json();
      if(!response.ok||!json.ok)throw new Error(json.error||'Published experience could not be loaded.');
      if(String(json.sha256||'')!==expectedHash||Number(json.versionNumber||0)!==expectedVersion){
        throw new Error('Published experience identity did not match the requested version.');
      }
      desktop.state.experienceManifest=json.manifest||null;
      desktop.state.experienceHash=json.sha256||'';
      desktop.state.experienceDataMode='authored-only';
      desktop.emit('experience-loaded',{
        manifest:desktop.state.experienceManifest,
        sha256:desktop.state.experienceHash,
        versionNumber:json.versionNumber
      });
      await desktop.runCommand('zscroll.enter',{latched:true,source:String(boot.source||'public-experience')});
    }catch(error){
      fail(error?.message||'Published experience could not be loaded.');
    }
  };

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>setTimeout(start,0),{once:true});
  else setTimeout(start,0);
})();