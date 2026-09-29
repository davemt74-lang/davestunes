(() => {
  'use strict';

  const desktop=window.DaveTunesDesktop;
  if(!desktop)return;

  const safeUrl=value=>{
    const url=String(value||'').trim();
    if(!url.startsWith('/')||url.includes('..')||!/^[A-Za-z0-9/_\.\-%]+$/.test(url))return '';
    return url;
  };

  const el=(tag,className='',text='')=>{
    const node=document.createElement(tag);
    if(className)node.className=className;
    if(text!=='')node.textContent=String(text);
    return node;
  };

  const renderLayer=(layer,ctx)=>{
    const settings=layer?.settings||{};
    const type=String(layer?.type||'');
    if(type==='heading'){
      return el('h2','experience-layer experience-heading',String(settings.text||''));
    }
    if(type==='text'){
      return el('p','experience-layer experience-text',String(settings.text||''));
    }
    if(type==='image'){
      const wrap=el('div','experience-layer experience-image');
      const src=safeUrl(settings.src);
      if(src){
        const image=document.createElement('img');
        image.src=src;
        image.alt=String(settings.alt||'');
        image.loading='lazy';
        wrap.append(image);
      }
      return wrap;
    }
    if(type==='button'){
      const button=el('button','experience-layer experience-button',String(settings.label||'Continue'));
      button.type='button';
      const command=String(settings.command||'');
      if(command)button.addEventListener('click',()=>ctx.runCommand(command,settings.payload||{}).catch(error=>ctx.emit('experience-error',{error})));
      return button;
    }
    const unknown=el('div','experience-layer experience-unknown');
    unknown.dataset.experienceLayerType=type;
    return unknown;
  };

  const renderScene=(scene,ctx)=>{
    const root=el('div','experience-scene-runtime');
    root.dataset.experienceSceneKey=String(scene.key||'');
    const settings=scene.settings||{};
    if(settings.eyebrow)root.append(el('span','z-scroll-eyebrow',String(settings.eyebrow)));
    if(settings.title)root.append(el('h2','',String(settings.title)));
    for(const layer of scene.layers||[]){
      const rendered=renderLayer(layer,ctx);
      if(rendered){ rendered.dataset.experienceLayerKey=String(layer.key||''); root.append(rendered); }
    }
    return root;
  };

  const descriptors=manifest=>(manifest?.scenes||[])
    .filter(scene=>scene&&scene.enabled!==false)
    .map(scene=>({
      id:'experience.'+String(scene.key||'scene'),
      label:String(scene.title||scene.key||'Scene'),
      weight:Math.max(.1,Number(scene.weight)||1),
      render:(_data,ctx)=>renderScene(scene,ctx),
      update:(node,progress)=>{
        const lib=window.DaveTunesEffectsLibrary;
        if(!lib)return;
        lib.applyStack(node,scene.settings?.effects||[],progress);
        for(const layer of scene.layers||[]){
          const target=node.querySelector('[data-experience-layer-key="'+String(layer.key||'')+'"]');
          if(target)lib.applyStack(target,layer.settings?.effects||[],progress);
        }
      }
    }));

  const fetchExperience=async(ownerType,ownerId,key='default')=>{
    const url=new URL('/experience.php',window.location.origin);
    url.searchParams.set('owner_type',String(ownerType||''));
    url.searchParams.set('owner_id',String(Number(ownerId)||0));
    url.searchParams.set('key',String(key||'default'));
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
    const json=await response.json();
    if(!response.ok||!json.ok)throw new Error(json.error||'Experience could not be loaded.');
    return json;
  };

  window.DaveTunesExperienceRuntime=Object.freeze({descriptors,renderScene});

  desktop.registerCommand({
    id:'experience.load',
    run:async payload=>{
      const loaded=await fetchExperience(payload?.ownerType,payload?.ownerId,payload?.key||'default');
      desktop.state.experienceManifest=loaded.manifest||null;
      desktop.state.experienceHash=loaded.sha256||'';
      desktop.emit('experience-loaded',{manifest:desktop.state.experienceManifest,sha256:desktop.state.experienceHash});
      return loaded;
    }
  });

  desktop.registerCommand({
    id:'experience.clear',
    run:async()=>{
      desktop.state.experienceManifest=null;
      desktop.state.experienceHash='';
      desktop.emit('experience-cleared',{});
      return {cleared:true};
    }
  });

  desktop.registerModule({
    id:'experience-runtime',
    mount:()=>()=>{}
  });
})();
