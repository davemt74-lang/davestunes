(() => {
  'use strict';

  const desktop=window.DaveTunesDesktop;
  if(!desktop)return;

  const el=(tag,className='',text='')=>{
    const node=document.createElement(tag);
    if(className)node.className=className;
    if(text!=='')node.textContent=String(text);
    return node;
  };

  const renderItem=(item,ctx)=>{
    const content=item.content||{};
    const card=el('article','featured-card');
    const art=el('div','featured-card-art');
    const cover=content.coverUrl||'';
    if(cover){
      const image=document.createElement('img');
      image.src=cover; image.alt=''; image.loading='lazy'; image.draggable=false;
      art.append(image);
    }else art.append(el('span','',(content.title||'♪').slice(0,1).toUpperCase()));

    const copy=el('div','featured-card-copy');
    copy.append(
      el('span','',item.type==='release'?'Featured album':'Featured song'),
      el('strong','',item.headline||content.title||'Featured'),
      el('span','',content.artist?.name||'')
    );
    if(item.body)copy.append(el('p','',item.body));

    const actions=el('div','featured-card-actions');
    if(item.type==='release'){
      if(content.playableTrackIds?.length){
        const play=el('button','primary','Play');
        play.type='button';
        play.addEventListener('click',async()=>{
          try{
            await ctx.runCommand('player.replace-queue',{recordingIds:content.playableTrackIds,sourceType:'featured',sourceId:item.id});
            await ctx.runCommand('player.play-recording',{recordingId:content.playableTrackIds[0],sourceType:'featured',sourceId:item.id});
          }catch(error){ctx.emit('featured-error',{error,item});}
        });
        actions.append(play);
      }
      const add=el('button','','Add to Desktop');
      add.type='button';
      add.addEventListener('click',async()=>{
        try{
          await ctx.runCommand('media.place-release',{releaseId:content.id});
          add.textContent='On Desktop';
          add.disabled=true;
          ctx.emit('featured-added-to-desktop',{item,releaseId:content.id});
        }catch(error){
          if(String(error?.message||'').toLowerCase().includes('already')){add.textContent='On Desktop';add.disabled=true;return;}
          ctx.emit('featured-error',{error,item});
        }
      });
      actions.append(add);
    }else if(content.playable){
      const play=el('button','primary','Play song');
      play.type='button';
      play.addEventListener('click',()=>ctx.runCommand('player.play-recording',{
        recordingId:content.id,sourceType:'featured',sourceId:item.id
      }).catch(error=>ctx.emit('featured-error',{error,item})));
      actions.append(play);
    }

    card.append(art,copy,actions);
    return card;
  };

  const renderNewsItem=item=>{
    const card=el('article','featured-card featured-news-card');
    const art=el('div','featured-card-art');
    if(item.image_url){
      const image=document.createElement('img'); image.src=item.image_url; image.alt=''; image.loading='lazy'; art.append(image);
    }else art.append(el('span','','N'));
    const copy=el('div','featured-card-copy');
    copy.append(el('span','','News & Notes'),el('strong','',item.headline||'Update'));
    if(item.body_text)copy.append(el('p','',item.body_text));
    const actions=el('div','featured-card-actions');
    const open=el('button','primary',item.link_label||'Read');
    open.type='button';
    open.addEventListener('click',()=>{
      const target=item.link_url||('/news.php?slug='+encodeURIComponent(item.slug||''));
      window.location.assign(target);
    });
    actions.append(open);
    card.append(art,copy,actions);
    return card;
  };

  const load=async ctx=>{
    const mount=document.querySelector('[data-desktop-featured]');
    if(!mount)return [];
    const response=await fetch('/desktop-featured.php',{credentials:'same-origin',headers:{Accept:'application/json'}});
    const json=await response.json();
    if(!response.ok||!json.ok)throw new Error(json.error||'Featured content could not be loaded.');
    const items=Array.isArray(json.items)?json.items:[];
    const news=Array.isArray(json.news)?json.news:[];
    mount.replaceChildren();
    mount.hidden=!items.length&&!news.length;
    if(!items.length&&!news.length)return {items:[],news:[]};

    const shell=el('div','featured-desktop-shell');
    const heading=el('div','featured-desktop-heading');
    const starter=ctx.root?.dataset?.personalizationState==='starter';
    heading.append(
      el('strong','',starter?'Start here':'Featured'),
      el('span','',starter?'Featured music while your Desktop learns what you like':'Discovery · your saved Desktop stays yours')
    );
    const rail=el('div','featured-desktop-rail');
    for(const item of items)rail.append(renderItem(item,ctx));
    shell.append(heading,rail);
    if(news.length){
      const newsHeading=el('div','featured-desktop-heading featured-news-heading');
      newsHeading.append(el('strong','','News & Notes'),el('span','','From Dave\'s Tunes'));
      const newsRail=el('div','featured-desktop-rail featured-news-rail');
      for(const item of news)newsRail.append(renderNewsItem(item));
      shell.append(newsHeading,newsRail);
    }
    mount.append(shell);
    ctx.emit('featured-loaded',{items,news});
    return {items,news};
  };

  desktop.registerCommand({id:'featured.refresh',run:async(_payload,ctx)=>load(ctx)});
  desktop.registerModule({
    id:'featured-content',
    mount:ctx=>{
      load(ctx).catch(error=>ctx.emit('featured-error',{error}));
      return()=>{};
    }
  });
})();
