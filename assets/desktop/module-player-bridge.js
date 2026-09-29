(() => {
  'use strict';

  const desktop=window.DaveTunesDesktop;
  if(!desktop)return;

  const canonical=()=>window.DaveTunesPlayer;
  let lastSnapshot=null;

  const cloneCurrent=current=>current?{
    id:Number(current.id||0),
    artistId:Number(current.artist_id||0),
    title:String(current.title||''),
    versionLabel:String(current.version_label||''),
    durationMs:current.duration_ms==null?null:Number(current.duration_ms),
    artistName:String(current.artist_name||''),
    coverPath:String(current.cover_path||''),
    accessMode:String(current.access_mode||'none')
  }:null;

  const snapshot=()=>{
    const p=canonical();
    if(!p){
      return Object.freeze({
        ready:false,current:null,queue:[],queueRevision:0,currentIndex:-1,queueLength:0,
        isPlaying:false,positionSeconds:0,durationSeconds:0,progress:0,volume:1,
        source:Object.freeze({type:'',id:null}),canPrevious:false,canNext:false
      });
    }
    const current=cloneCurrent(p.state?.current||null);
    const queue=Array.isArray(p.state?.queue)?p.state.queue.map(item=>({
      recordingId:Number(item.recording_id||0),
      position:Number(item.queue_position||0),
      title:String(item.title||''),
      versionLabel:String(item.version_label||''),
      artistName:String(item.artist_name||''),
      sourceType:String(item.source_type||''),
      sourceId:item.source_id==null?null:Number(item.source_id)
    })):[];
    const currentId=Number(current?.id||0);
    const currentIndex=queue.findIndex(item=>item.recordingId===currentId);
    const sourceItem=currentIndex>=0?queue[currentIndex]:null;
    const duration=Number.isFinite(p.audio?.duration)&&p.audio.duration>0?p.audio.duration:0;
    const position=duration>0?Math.max(0,Math.min(duration,Number(p.audio?.currentTime||0))):Math.max(0,Number(p.audio?.currentTime||0));
    const progress=duration>0?Math.max(0,Math.min(1,position/duration)):0;
    const volume=Math.max(0,Math.min(1,Number(p.audio?.volume??p.state?.volume??1)));
    return Object.freeze({
      ready:Boolean(p.state?.ready),
      current,
      queue:Object.freeze(queue),
      queueRevision:Number(p.state?.queueRevision||0),
      currentIndex,
      queueLength:queue.length,
      isPlaying:Boolean(current&&p.audio&&!p.audio.paused),
      positionSeconds:position,
      durationSeconds:duration,
      progress,
      volume,
      source:Object.freeze({
        type:String(sourceItem?.sourceType||''),
        id:sourceItem?.sourceId??null
      }),
      canPrevious:Boolean(current),
      canNext:queue.length>0
    });
  };

  const publish=reason=>{
    lastSnapshot=snapshot();
    desktop.state.playerSnapshot=lastSnapshot;
    desktop.emit('player-snapshot',{reason,snapshot:lastSnapshot});
    return lastSnapshot;
  };

  const requirePlayer=()=>{
    const p=canonical();
    if(!p)throw new Error('Player runtime is not ready.');
    return p;
  };

  const after=async(reason,operation)=>{
    const result=await operation();
    publish(reason);
    return result;
  };

  desktop.registerCommand({
    id:'player.play-recording',
    run:async payload=>after('command:play-recording',()=>requirePlayer().playRecording(Number(payload.recordingId),{
      sourceType:payload.sourceType||'desktop',
      sourceId:payload.sourceId||null
    }))
  });

  desktop.registerCommand({
    id:'player.replace-queue',
    run:async payload=>after('command:replace-queue',()=>requirePlayer().replaceQueue(
      Array.isArray(payload.recordingIds)?payload.recordingIds:[],
      {sourceType:payload.sourceType||'desktop',sourceId:payload.sourceId||null}
    ))
  });

  desktop.registerCommand({
    id:'player.play',
    run:async()=>after('command:play',()=>requirePlayer().play())
  });

  desktop.registerCommand({
    id:'player.pause',
    run:async()=>after('command:pause',async()=>requirePlayer().pause())
  });

  desktop.registerCommand({
    id:'player.toggle',
    run:async()=>{
      const p=requirePlayer();
      return after('command:toggle',()=>p.audio.paused?p.play():Promise.resolve(p.pause()));
    }
  });

  desktop.registerCommand({
    id:'player.next',
    run:async()=>after('command:next',()=>requirePlayer().next())
  });

  desktop.registerCommand({
    id:'player.previous',
    run:async()=>after('command:previous',()=>requirePlayer().previous())
  });

  desktop.registerCommand({
    id:'player.seek',
    run:async payload=>{
      const p=requirePlayer();
      p.seek(Math.max(0,Number(payload.seconds)||0));
      return publish('command:seek');
    }
  });

  desktop.registerCommand({
    id:'player.seek-fraction',
    run:async payload=>{
      const p=requirePlayer();
      const current=snapshot();
      if(current.durationSeconds<=0)return current;
      const fraction=Math.max(0,Math.min(1,Number(payload.fraction)||0));
      p.seek(current.durationSeconds*fraction);
      return publish('command:seek-fraction');
    }
  });

  desktop.registerCommand({
    id:'player.set-volume',
    run:async payload=>{
      requirePlayer().setVolume(Math.max(0,Math.min(1,Number(payload.volume)||0)));
      return publish('command:set-volume');
    }
  });

  desktop.registerCommand({
    id:'player.snapshot',
    run:async()=>publish('command:snapshot')
  });

  desktop.registerModule({
    id:'player-bridge',
    mount:({emit})=>{
      const relay=event=>{
        const reason=event.type.replace('davestunes:player:','');
        emit('player-event',{name:event.type,detail:event.detail});
        publish(reason);
      };
      const names=['ready','restore','trackchange','play','pause','time','seek','queuechange','ended','error'];
      names.forEach(name=>document.addEventListener('davestunes:player:'+name,relay));
      publish('bridge-mounted');
      return()=>names.forEach(name=>document.removeEventListener('davestunes:player:'+name,relay));
    }
  });

  window.DaveTunesPlayerBridge=Object.freeze({
    getSnapshot:()=>lastSnapshot||snapshot()
  });
})();
