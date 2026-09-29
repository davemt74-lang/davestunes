(() => {
  'use strict';

  const desktop=window.DaveTunesDesktop;
  if(!desktop)return;

  const state={
    active:false,
    held:false,
    latched:false,
    progress:0,
    target:0,
    raf:0,
    scenes:[],
    data:null,
    loading:null,
    pointer:null,
    returnFocus:null
  };

  const reducedMotion=window.matchMedia('(prefers-reduced-motion: reduce)');

  const clamp=(value,min=0,max=1)=>Math.max(min,Math.min(max,value));

  const isTypingTarget=target=>{
    if(!(target instanceof HTMLElement))return false;
    return target.isContentEditable||/^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)||Boolean(target.closest('[contenteditable="true"]'));
  };

  const sceneEffects=()=>[...(desktop.getRegistry('effect')?.values() || [])]
    .filter(effect=>effect.category==='z-scroll-scene'&&typeof effect.render==='function')
    .sort((a,b)=>(a.order||0)-(b.order||0));

  const fetchView=async view=>{
    const url=new URL('/desktop-data.php',window.location.origin);
    url.searchParams.set('view',view);
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
    const json=await response.json();
    if(!response.ok||!json.ok)throw new Error(json.error||'Z-Scroll data request failed.');
    return json;
  };

  const loadData=async()=>{
    if(state.data)return state.data;
    if(state.loading)return state.loading;
    state.loading=Promise.all([
      fetchView('home'),
      fetchView('albums'),
      fetchView('artists'),
      fetchView('playlists'),
      fetchView('songs')
    ]).then(([home,albums,artists,playlists,songs])=>{
      state.data={home,albums,artists,playlists,songs};
      renderScenes();
      return state.data;
    }).catch(error=>{
      desktop.emit('zscroll-error',{error});
      throw error;
    }).finally(()=>{state.loading=null;});
    return state.loading;
  };

  const sceneContext=()=>Object.freeze({
    runCommand:desktop.runCommand,
    emit:desktop.emit,
    openLibrary:async(view,query='')=>{
      if(state.latched)setLatched(false);
      else{
        state.held=false;
        exit(true);
      }
      await desktop.runCommand('library.open-view',{view});
      if(query)await desktop.runCommand('library.search',{query});
    }
  });

  const renderScenes=()=>{
    const mount=document.querySelector('[data-zscroll-scenes]');
    if(!mount)return;
    mount.replaceChildren();
    const effects=sceneEffects();
    const total=Math.max(1,effects.reduce((sum,effect)=>sum+Math.max(1,Number(effect.weight)||1),0));
    let cursor=0;
    state.scenes=[];
    for(const effect of effects){
      const weight=Math.max(1,Number(effect.weight)||1);
      const start=cursor/total;
      cursor+=weight;
      const end=cursor/total;
      const wrapper=document.createElement('section');
      wrapper.className='z-scroll-scene';
      wrapper.dataset.zSceneId=effect.id;
      wrapper.setAttribute('aria-label',effect.label||effect.id);
      const rendered=effect.render(state.data||{},sceneContext());
      if(rendered instanceof Node)wrapper.append(rendered);
      mount.append(wrapper);
      state.scenes.push({effect,node:wrapper,start,end});
    }
    updateSceneStyles();
    renderMarkers();
  };

  const renderMarkers=()=>{
    const rail=document.querySelector('[data-zscroll-markers]');
    if(!rail)return;
    rail.replaceChildren();
    for(const scene of state.scenes){
      const button=document.createElement('button');
      button.type='button';
      button.className='z-scroll-marker';
      button.dataset.zSceneTarget=scene.effect.id;
      button.setAttribute('aria-label',scene.effect.label||scene.effect.id);
      button.addEventListener('click',()=>{
        state.target=(scene.start+scene.end)/2;
        if(reducedMotion.matches)state.progress=state.target;
        ensureAnimation();
      });
      rail.append(button);
    }
  };

  const updateSceneStyles=()=>{
    const p=state.progress;
    document.documentElement.style.setProperty('--dt-z-progress',String(p));
    const fill=document.querySelector('[data-zscroll-progress-fill]');
    if(fill)fill.style.transform='scaleX('+p+')';

    let activeId='';
    for(const scene of state.scenes){
      const center=(scene.start+scene.end)/2;
      const half=Math.max(0.001,(scene.end-scene.start)/2);
      const overlap=half*0.42;
      const distance=Math.abs(p-center);
      const opacity=clamp(1-(distance/(half+overlap)));
      const local=clamp((p-scene.start)/Math.max(0.001,scene.end-scene.start));
      scene.node.style.opacity=String(opacity);
      scene.node.style.transform='translate3d(0,'+((0.5-local)*34)+'px,0) scale('+(0.97+opacity*0.03)+')';
      scene.node.style.pointerEvents=opacity>0.62?'auto':'none';
      scene.node.setAttribute('aria-hidden',opacity>0.35?'false':'true');
      if(p>=scene.start&&p<=scene.end)activeId=scene.effect.id;
    }
    const root=document.getElementById('dt-desktop-root');
    if(root)root.dataset.zScene=activeId;
    for(const marker of document.querySelectorAll('[data-z-scene-target]')){
      marker.setAttribute('aria-current',marker.dataset.zSceneTarget===activeId?'step':'false');
    }
    desktop.emit('zscroll-progress',{progress:p,sceneId:activeId});
  };

  const animate=()=>{
    state.raf=0;
    if(!state.active)return;
    if(reducedMotion.matches){
      state.progress=state.target;
      updateSceneStyles();
      return;
    }
    const delta=state.target-state.progress;
    if(Math.abs(delta)<0.0004){
      state.progress=state.target;
      updateSceneStyles();
      return;
    }
    state.progress+=delta*0.16;
    updateSceneStyles();
    state.raf=requestAnimationFrame(animate);
  };

  const ensureAnimation=()=>{
    if(state.raf)return;
    state.raf=requestAnimationFrame(animate);
  };

  const enter=source=>{
    if(state.active)return;
    state.active=true;
    desktop.setMode('z-scroll');
    const layer=desktop.getLayer('z-scroll');
    layer?.setAttribute('aria-hidden','false');
    const stage=document.querySelector('[data-zscroll-stage]');
    stage?.setAttribute('aria-hidden','false');
    loadData().catch(()=>{});
    updateSceneStyles();
    ensureAnimation();
    desktop.emit('zscroll-enter',{source,progress:state.progress});
  };

  const exit=force=>{
    if(!state.active)return;
    if(!force&&(state.held||state.latched))return;
    state.active=false;
    if(state.raf)cancelAnimationFrame(state.raf);
    state.raf=0;
    desktop.setMode('desktop');
    desktop.getLayer('z-scroll')?.setAttribute('aria-hidden','true');
    document.querySelector('[data-zscroll-stage]')?.setAttribute('aria-hidden','true');
    desktop.emit('zscroll-exit',{progress:state.progress});
    if(state.latched===false&&state.returnFocus instanceof HTMLElement){
      state.returnFocus.focus({preventScroll:true});
      state.returnFocus=null;
    }
  };

  const setLatched=value=>{
    state.latched=Boolean(value);
    const toggle=document.querySelector('[data-zscroll-toggle]');
    toggle?.setAttribute('aria-pressed',state.latched?'true':'false');
    if(state.latched){
      state.returnFocus=document.activeElement instanceof HTMLElement?document.activeElement:toggle;
      enter('explore');
      requestAnimationFrame(()=>document.querySelector('[data-zscroll-close]')?.focus({preventScroll:true}));
    }else{
      exit(true);
      if(state.returnFocus instanceof HTMLElement)state.returnFocus.focus({preventScroll:true});
      state.returnFocus=null;
    }
  };

  const wheelAmount=event=>{
    let delta=event.deltaY;
    if(event.deltaMode===WheelEvent.DOM_DELTA_LINE)delta*=16;
    else if(event.deltaMode===WheelEvent.DOM_DELTA_PAGE)delta*=window.innerHeight;
    return delta;
  };

  const onWheel=event=>{
    if(!state.active)return;
    event.preventDefault();
    state.target=clamp(state.target+wheelAmount(event)*0.00065);
    if(reducedMotion.matches)state.progress=state.target;
    ensureAnimation();
  };

  const onKeyDown=event=>{
    if(event.code==='Escape'&&state.latched){
      event.preventDefault();
      setLatched(false);
      return;
    }
    if(event.code!=='KeyZ'||event.repeat||isTypingTarget(event.target)||event.ctrlKey||event.metaKey||event.altKey)return;
    state.held=true;
    enter('modifier');
  };

  const onKeyUp=event=>{
    if(event.code!=='KeyZ')return;
    state.held=false;
    if(!state.latched)exit(false);
  };

  const onBlur=()=>{
    state.held=false;
    if(!state.latched)exit(true);
  };

  const onVisibility=()=>{
    if(document.visibilityState!=='visible')onBlur();
  };

  const onPointerDown=event=>{
    if(!state.active||event.pointerType!=='touch'||event.target.closest('button,a,input,textarea,select'))return;
    state.pointer={id:event.pointerId,lastY:event.clientY};
    event.currentTarget.setPointerCapture?.(event.pointerId);
  };

  const onPointerMove=event=>{
    if(!state.pointer||event.pointerId!==state.pointer.id)return;
    event.preventDefault();
    const delta=state.pointer.lastY-event.clientY;
    state.pointer.lastY=event.clientY;
    state.target=clamp(state.target+delta/Math.max(400,window.innerHeight)*0.72);
    if(reducedMotion.matches)state.progress=state.target;
    ensureAnimation();
  };

  const onPointerEnd=event=>{
    if(!state.pointer||event.pointerId!==state.pointer.id)return;
    event.currentTarget.releasePointerCapture?.(event.pointerId);
    state.pointer=null;
  };

  desktop.registerCommand({
    id:'zscroll.enter',
    run:async payload=>{
      if(payload?.latched)setLatched(true);
      else enter(payload?.source||'command');
      return {progress:state.progress};
    }
  });
  desktop.registerCommand({
    id:'zscroll.exit',
    run:async()=>{
      state.held=false;
      if(state.latched)setLatched(false);
      else exit(true);
      return {progress:state.progress};
    }
  });
  desktop.registerCommand({
    id:'zscroll.set-progress',
    run:async payload=>{
      state.target=clamp(Number(payload?.progress)||0);
      if(reducedMotion.matches)state.progress=state.target;
      ensureAnimation();
      return {progress:state.target};
    }
  });

  desktop.registerModule({
    id:'z-scroll-runtime',
    mount:ctx=>{
      const stage=document.querySelector('[data-zscroll-stage]');
      const toggle=document.querySelector('[data-zscroll-toggle]');
      const close=document.querySelector('[data-zscroll-close]');
      if(!stage)throw new Error('Z-Scroll stage is missing.');

      const onToggle=()=>setLatched(!state.latched);
      const onClose=()=>setLatched(false);
      const onRegistered=event=>{
        if(event.detail?.type==='effect'&&event.detail.definition?.category==='z-scroll-scene')renderScenes();
      };
      const onPlayer=()=>{if(state.active)renderScenes();};

      toggle?.addEventListener('click',onToggle);
      close?.addEventListener('click',onClose);
      stage.addEventListener('pointerdown',onPointerDown);
      stage.addEventListener('pointermove',onPointerMove);
      stage.addEventListener('pointerup',onPointerEnd);
      stage.addEventListener('pointercancel',onPointerEnd);
      window.addEventListener('wheel',onWheel,{passive:false});
      window.addEventListener('keydown',onKeyDown);
      window.addEventListener('keyup',onKeyUp);
      window.addEventListener('blur',onBlur);
      document.addEventListener('visibilitychange',onVisibility);
      document.addEventListener('davestunes:desktop:registered',onRegistered);
      for(const name of ['trackchange','play','pause'])document.addEventListener('davestunes:player:'+name,onPlayer);

      renderScenes();
      return()=>{
        if(state.raf)cancelAnimationFrame(state.raf);
        toggle?.removeEventListener('click',onToggle);
        close?.removeEventListener('click',onClose);
        stage.removeEventListener('pointerdown',onPointerDown);
        stage.removeEventListener('pointermove',onPointerMove);
        stage.removeEventListener('pointerup',onPointerEnd);
        stage.removeEventListener('pointercancel',onPointerEnd);
        window.removeEventListener('wheel',onWheel);
        window.removeEventListener('keydown',onKeyDown);
        window.removeEventListener('keyup',onKeyUp);
        window.removeEventListener('blur',onBlur);
        document.removeEventListener('visibilitychange',onVisibility);
        document.removeEventListener('davestunes:desktop:registered',onRegistered);
        for(const name of ['trackchange','play','pause'])document.removeEventListener('davestunes:player:'+name,onPlayer);
      };
    }
  });
})();
