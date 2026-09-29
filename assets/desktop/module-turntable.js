(() => {
  'use strict';

  const desktop=window.DaveTunesDesktop;
  if(!desktop)return;

  let deck=null;
  let platter=null;
  let arm=null;
  let label=null;
  let title=null;
  let artist=null;
  let progress=null;
  let dropHint=null;

  const player=()=>window.DaveTunesPlayer;

  const safeCover=path=>{
    const value=String(path||'').trim();
    if(!value.startsWith('/')||value.includes('..')||!/^[A-Za-z0-9/_\.\-%]+$/.test(value))return '';
    return value;
  };

  const currentProgress=()=>{
    const audio=player()?.audio;
    if(!audio||!Number.isFinite(audio.duration)||audio.duration<=0)return 0;
    return Math.max(0,Math.min(1,(audio.currentTime||0)/audio.duration));
  };

  const updateMotion=()=>{
    if(!deck)return;
    const p=currentProgress();
    deck.style.setProperty('--turntable-progress',String(p));
    deck.dataset.turntablePlaying=player()?.audio&&!player().audio.paused?'true':'false';
    if(arm)arm.style.transform='rotate('+(14+p*28)+'deg)';
    if(progress){
      progress.max=1000;
      progress.value=Math.round(p*1000);
    }
  };

  const updateCurrent=()=>{
    if(!deck)return;
    const current=player()?.state?.current||null;
    deck.dataset.turntableLoaded=current?'true':'false';
    if(title)title.textContent=current?.title||'No record loaded';
    if(artist)artist.textContent=current?.artist_name||'Drop an album here or choose a track.';
    if(label){
      label.replaceChildren();
      const cover=safeCover(current?.cover_path);
      if(cover){
        const image=document.createElement('img');
        image.src=cover;
        image.alt='';
        image.draggable=false;
        label.append(image);
      }else{
        const mark=document.createElement('span');
        mark.textContent=current?.title?.slice(0,1).toUpperCase()||'♪';
        label.append(mark);
      }
    }
    updateMotion();
  };

  const toggle=async()=>{
    const p=player();
    if(!p||!p.state.current)return;
    if(p.audio.paused)await p.play();
    else p.pause();
  };

  const hydrateRelease=async releaseId=>{
    const url=new URL('/desktop-media.php',window.location.origin);
    url.searchParams.set('type','release');
    url.searchParams.set('id',String(releaseId));
    const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
    const json=await response.json();
    if(!response.ok||!json.ok)throw new Error(json.error||'Album is no longer available.');
    return json.media;
  };

  const loadRelease=async releaseId=>{
    const item=await hydrateRelease(releaseId);
    const ids=Array.isArray(item.playableTrackIds)?item.playableTrackIds.map(Number).filter(id=>id>0):[];
    if(!ids.length)throw new Error('This album has no playable tracks.');
    await desktop.runCommand('player.replace-queue',{recordingIds:ids,sourceType:'release',sourceId:item.id});
    await desktop.runCommand('player.play-recording',{recordingId:ids[0],sourceType:'release',sourceId:item.id});
    desktop.emit('turntable-release-loaded',{releaseId:item.id,recordingIds:ids});
    return item;
  };

  const isInsideDeck=(x,y)=>{
    if(!deck)return false;
    const rect=deck.getBoundingClientRect();
    return x>=rect.left&&x<=rect.right&&y>=rect.top&&y<=rect.bottom;
  };

  const onObjectDrop=event=>{
    const detail=event.detail||{};
    const object=detail.object||{};
    if(object.type!=='album-sleeve'||object.resource?.type!=='release'||!object.resource?.id)return;
    if(!isInsideDeck(Number(detail.clientX),Number(detail.clientY)))return;
    deck.dataset.turntableDrop='loading';
    if(dropHint)dropHint.textContent='Loading album…';
    loadRelease(Number(object.resource.id))
      .then(()=>{
        deck.dataset.turntableDrop='accepted';
        if(dropHint)dropHint.textContent='Spinning';
        setTimeout(()=>{if(deck)deck.dataset.turntableDrop='idle';if(dropHint)dropHint.textContent='Drop album to play';},650);
      })
      .catch(error=>{
        deck.dataset.turntableDrop='error';
        if(dropHint)dropHint.textContent='Album unavailable';
        desktop.emit('turntable-error',{error});
        setTimeout(()=>{if(deck)deck.dataset.turntableDrop='idle';if(dropHint)dropHint.textContent='Drop album to play';},1000);
      });
  };

  const onControl=async event=>{
    const action=event.currentTarget.dataset.turntableAction;
    try{
      if(action==='previous')await desktop.runCommand('player.previous');
      else if(action==='toggle')await toggle();
      else if(action==='next')await desktop.runCommand('player.next');
    }catch(error){desktop.emit('turntable-error',{error});}
  };

  const onSeek=event=>{
    const p=player();
    if(!p||!Number.isFinite(p.audio.duration)||p.audio.duration<=0)return;
    const fraction=Number(event.currentTarget.value||0)/1000;
    p.seek(p.audio.duration*Math.max(0,Math.min(1,fraction)));
    updateMotion();
  };

  desktop.registerCommand({
    id:'turntable.load-release',
    run:async payload=>loadRelease(Number(payload?.releaseId||0))
  });
  desktop.registerCommand({
    id:'turntable.toggle',
    run:async()=>toggle()
  });

  desktop.registerModule({
    id:'digital-vinyl-turntable',
    mount:ctx=>{
      deck=document.querySelector('[data-turntable]');
      platter=deck?.querySelector('[data-turntable-platter]')||null;
      arm=deck?.querySelector('[data-turntable-arm]')||null;
      label=deck?.querySelector('[data-turntable-label]')||null;
      title=deck?.querySelector('[data-turntable-title]')||null;
      artist=deck?.querySelector('[data-turntable-artist]')||null;
      progress=deck?.querySelector('[data-turntable-progress]')||null;
      dropHint=deck?.querySelector('[data-turntable-drop-hint]')||null;
      if(!deck)throw new Error('Turntable mount is missing.');

      const controls=[...deck.querySelectorAll('[data-turntable-action]')];
      controls.forEach(button=>button.addEventListener('click',onControl));
      progress?.addEventListener('input',onSeek);

      const onPlayer=()=>updateCurrent();
      const onTime=()=>updateMotion();
      document.addEventListener('davestunes:desktop:object-dropped',onObjectDrop);
      for(const name of ['ready','restore','trackchange','play','pause','ended']){
        document.addEventListener('davestunes:player:'+name,onPlayer);
      }
      document.addEventListener('davestunes:player:time',onTime);
      document.addEventListener('davestunes:player:seek',onTime);
      updateCurrent();

      return()=>{
        controls.forEach(button=>button.removeEventListener('click',onControl));
        progress?.removeEventListener('input',onSeek);
        document.removeEventListener('davestunes:desktop:object-dropped',onObjectDrop);
        for(const name of ['ready','restore','trackchange','play','pause','ended']){
          document.removeEventListener('davestunes:player:'+name,onPlayer);
        }
        document.removeEventListener('davestunes:player:time',onTime);
        document.removeEventListener('davestunes:player:seek',onTime);
      };
    }
  });
})();
