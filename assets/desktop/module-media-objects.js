(() => {
  'use strict';

  const desktop=window.DaveTunesDesktop;
  if(!desktop)return;

  const hydration=new Map();

  const el=(tag,className='',text='')=>{
    const node=document.createElement(tag);
    if(className)node.className=className;
    if(text!=='')node.textContent=String(text);
    return node;
  };

  const fetchRelease=async id=>{
    const key='release:'+Number(id);
    const existing=hydration.get(key);
    if(existing?.status==='loading')return existing.promise;
    const promise=(async()=>{
      const url=new URL('/desktop-media.php',window.location.origin);
      url.searchParams.set('type','release');
      url.searchParams.set('id',String(Number(id)));
      const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
      const json=await response.json();
      if(!response.ok||!json.ok)throw new Error(json.error||'Album is no longer available.');
      return json.media;
    })().finally(()=>hydration.delete(key));
    hydration.set(key,{status:'loading',promise});
    return promise;
  };

  const fetchArtist=async id=>{
    const key='artist:'+Number(id);
    const existing=hydration.get(key);
    if(existing?.status==='loading')return existing.promise;
    const promise=(async()=>{
      const url=new URL('/desktop-media.php',window.location.origin);
      url.searchParams.set('type','artist');
      url.searchParams.set('id',String(Number(id)));
      const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
      const json=await response.json();
      if(!response.ok||!json.ok)throw new Error(json.error||'Artist is no longer available.');
      return json.media;
    })().finally(()=>hydration.delete(key));
    hydration.set(key,{status:'loading',promise});
    return promise;
  };

  const renderDenied=mount=>{
    mount.replaceChildren();
    const sleeve=el('div','media-object-sleeve denied');
    sleeve.append(el('span','media-object-kicker','Unavailable'),el('strong','','This album is no longer available.'));
    mount.append(sleeve);
  };

  const renderRelease=async(object,mount,ctx)=>{
    const releaseId=Number(object.resource?.id||0);
    if(!releaseId){renderDenied(mount);return;}
    try{
      const item=await fetchRelease(releaseId);
      if(!mount.isConnected)return;
      mount.replaceChildren();
      const sleeve=el('div','media-object-sleeve');
      const art=el('div','media-object-art');
      if(item.coverUrl){
        const image=document.createElement('img');
        image.src=item.coverUrl;
        image.alt='';
        image.draggable=false;
        art.append(image);
      }else art.append(el('span','',item.title?.slice(0,1).toUpperCase()||'♪'));

      const copy=el('div','media-object-copy');
      copy.append(el('span','media-object-kicker',item.releaseType||'release'));
      copy.append(el('strong','',item.title||'Album'));
      copy.append(el('span','media-object-artist',item.artist?.name||''));

      const actions=el('div','media-object-actions');
      actions.dataset.objectInteractive='true';
      const open=el('button','media-object-button','Open');
      open.type='button';
      open.dataset.objectInteractive='true';
      open.addEventListener('click',event=>{
        event.stopPropagation();
        window.location.assign(item.profileUrl||('/album.php?release='+releaseId));
      });
      actions.append(open);
      if(item.experienceAvailable){
        const experience=el('button','media-object-button','Experience');
        experience.type='button';
        experience.dataset.objectInteractive='true';
        experience.addEventListener('click',event=>{
          event.stopPropagation();
          window.location.assign(item.experienceUrl||('/album-experience.php?release='+releaseId));
        });
        actions.append(experience);
      }

      if(item.playableTrackIds?.length){
        const play=el('button','media-object-button primary','Play');
        play.type='button';
        play.dataset.objectInteractive='true';
        play.addEventListener('click',async event=>{
          event.stopPropagation();
          try{
            await ctx.runCommand('player.replace-queue',{
              recordingIds:item.playableTrackIds,sourceType:'release',sourceId:item.id
            });
            await ctx.runCommand('player.play-recording',{
              recordingId:item.playableTrackIds[0],sourceType:'release',sourceId:item.id
            });
          }catch(error){ctx.emit('media-object-error',{error});}
        });
        actions.prepend(play);
      }

      sleeve.addEventListener('dblclick',event=>{
        if(event.target.closest('button'))return;
        if(!item.playableTrackIds?.length)return;
        ctx.runCommand('player.play-recording',{
          recordingId:item.playableTrackIds[0],sourceType:'release',sourceId:item.id
        }).catch(error=>ctx.emit('media-object-error',{error}));
      });

      sleeve.append(art,copy,actions);
      mount.append(sleeve);
    }catch(_){if(mount.isConnected)renderDenied(mount);}
  };

  const renderArtist=async(object,mount,ctx)=>{
    const artistId=Number(object.resource?.id||0);
    if(!artistId){renderDenied(mount);return;}
    try{
      const item=await fetchArtist(artistId);
      if(!mount.isConnected)return;
      mount.replaceChildren();
      const card=el('div','media-object-sleeve artist-shortcut-card');
      const art=el('div','media-object-art artist-shortcut-art');
      if(item.profileImageUrl){
        const image=document.createElement('img');
        image.src=item.profileImageUrl;image.alt='';image.draggable=false;art.append(image);
      }else art.append(el('span','',item.name?.slice(0,1).toUpperCase()||'?'));
      const copy=el('div','media-object-copy');
      copy.append(el('span','media-object-kicker','Artist'),el('strong','',item.name||'Artist'),el('span','media-object-artist',item.location||('@'+(item.slug||''))));
      const actions=el('div','media-object-actions');actions.dataset.objectInteractive='true';
      const open=el('button','media-object-button primary','Open');
      open.type='button';open.dataset.objectInteractive='true';
      open.addEventListener('click',event=>{event.stopPropagation();window.location.assign(item.profileUrl||('/artist.php?artist='+encodeURIComponent(item.slug||String(artistId))));});
      actions.append(open);
      if(item.experienceAvailable){
        const experience=el('button','media-object-button','Experience');
        experience.type='button';experience.dataset.objectInteractive='true';
        experience.addEventListener('click',event=>{event.stopPropagation();window.location.assign(item.experienceUrl||('/artist-experience.php?artist='+artistId));});
        actions.append(experience);
      }
      card.append(art,copy,actions);mount.append(card);
    }catch(_){if(mount.isConnected)renderDenied(mount);}
  };

  desktop.registerObjectType({
    id:'album-sleeve',
    label:'Album Sleeve',
    render:(object,ctx)=>{
      const mount=el('div','media-object-mount');
      const loading=el('div','media-object-sleeve loading');
      loading.append(el('span','media-object-kicker','Album'),el('strong','','Loading…'));
      mount.append(loading);
      renderRelease(object,mount,ctx);
      return mount;
    }
  });

  desktop.registerObjectType({
    id:'artist-shortcut',
    label:'Artist Shortcut',
    render:(object,ctx)=>{
      const mount=el('div','media-object-mount');
      const loading=el('div','media-object-sleeve loading');
      loading.append(el('span','media-object-kicker','Artist'),el('strong','','Loading…'));
      mount.append(loading);renderArtist(object,mount,ctx);return mount;
    }
  });

  desktop.registerCommand({
    id:'media.place-release',
    run:async payload=>{
      const id=Number(payload?.releaseId||0);
      if(!Number.isInteger(id)||id<1)throw new Error('Release id is required.');
      const x=Math.max(.12,Math.min(.78,Number(payload?.x??(.18+(id%5)*.12))));
      const y=Math.max(.18,Math.min(.82,Number(payload?.y??(.25+(id%4)*.14))));
      return desktop.runCommand('object.create',{
        key:'release:'+id,
        type:'album-sleeve',
        resourceType:'release',
        resourceId:id,
        label:'Album',
        x,y,
        rotation:Number(payload?.rotation??(((id%7)-3)*2.4)),
        scale:Number(payload?.scale??1),
        payload:{schema:'album-sleeve-v1'}
      });
    }
  });

  desktop.registerCommand({
    id:'media.place-artist',
    run:async payload=>{
      const id=Number(payload?.artistId||0);
      if(!Number.isInteger(id)||id<1)throw new Error('Artist id is required.');
      const x=Math.max(.12,Math.min(.82,Number(payload?.x??(.16+(id%6)*.11))));
      const y=Math.max(.16,Math.min(.82,Number(payload?.y??(.2+(id%5)*.13))));
      return desktop.runCommand('object.create',{
        key:'artist:'+id,type:'artist-shortcut',resourceType:'artist',resourceId:id,label:'Artist',
        x,y,rotation:Number(payload?.rotation??0),scale:Number(payload?.scale??1),payload:{schema:'artist-shortcut-v1'}
      });
    }
  });

  desktop.registerCommand({
    id:'media.refresh-release',
    run:async payload=>{
      const id=Number(payload?.releaseId||0);
      if(id>0)hydration.delete('release:'+id);else hydration.clear();
      await desktop.runCommand('object.refresh');
      return {releaseId:id||null};
    }
  });

  desktop.registerCommand({
    id:'media.refresh-artist',
    run:async payload=>{
      const id=Number(payload?.artistId||0);
      if(id>0)hydration.delete('artist:'+id);else hydration.clear();
      await desktop.runCommand('object.refresh');
      return {artistId:id||null};
    }
  });

  desktop.registerModule({
    id:'media-object-experience',
    mount:ctx=>{
      const onLibraryLoaded=()=>hydration.clear();
      const onVisibility=()=>{if(document.visibilityState==='visible')hydration.clear();};
      document.addEventListener('davestunes:desktop:library-loaded',onLibraryLoaded);
      document.addEventListener('visibilitychange',onVisibility);
      return()=>{
        document.removeEventListener('davestunes:desktop:library-loaded',onLibraryLoaded);
        document.removeEventListener('visibilitychange',onVisibility);
      };
    }
  });
})();
