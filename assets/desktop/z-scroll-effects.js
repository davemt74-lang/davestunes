(() => {
  'use strict';

  const desktop = window.DaveTunesDesktop;
  if (!desktop) return;

  const el = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = String(text);
    return node;
  };

  const artwork = item => {
    const art = el('div', 'z-scroll-artwork');
    if (item?.coverUrl) {
      const image = document.createElement('img');
      image.src = item.coverUrl;
      image.alt = '';
      image.loading = 'lazy';
      art.append(image);
    } else {
      art.append(el('span', '', (item?.title || '♪').slice(0, 1).toUpperCase()));
    }
    return art;
  };

  const playAlbumButton = (item, ctx) => {
    const button = el('button', 'z-scroll-action', 'Play');
    button.type = 'button';
    button.disabled = !item?.playableTrackIds?.length;
    button.addEventListener('click', async event => {
      event.stopPropagation();
      if (!item?.playableTrackIds?.length) return;
      try {
        await ctx.runCommand('player.replace-queue', {
          recordingIds: item.playableTrackIds,
          sourceType: 'release',
          sourceId: item.id
        });
        await ctx.runCommand('player.play-recording', {
          recordingId: item.playableTrackIds[0],
          sourceType: 'release',
          sourceId: item.id
        });
      } catch (error) { ctx.emit('zscroll-error', { error }); }
    });
    return button;
  };

  const albumTile = (item, ctx) => {
    const tile = el('article', 'z-scroll-album');
    tile.append(artwork(item));
    const copy = el('div', 'z-scroll-copy');
    copy.append(el('strong', '', item.title || 'Untitled'));
    copy.append(el('span', '', item.artist?.name || ''));
    tile.append(copy, playAlbumButton(item, ctx));
    return tile;
  };

  desktop.registerEffect({
    id: 'zscroll.now-playing',
    category: 'z-scroll-scene',
    label: 'Now Playing',
    order: 10,
    weight: 18,
    render: (data, ctx) => {
      const scene = el('div', 'z-scroll-now');
      const player = ctx.playerSnapshot || null;
      const current = player?.current || null;
      const vinyl = el('div', 'z-scroll-vinyl');
      const label = el('div', 'z-scroll-vinyl-label', current?.title?.slice(0, 1).toUpperCase() || '♪');
      vinyl.append(label);
      const copy = el('div', 'z-scroll-scene-copy');
      copy.append(el('span', 'z-scroll-eyebrow', 'Now Playing'));
      copy.append(el('h1', '', current?.title || 'Your music, in motion.'));
      copy.append(el('p', '', current?.artistName || 'Start a track, then hold Z and scroll through your library.'));
      if (current) {
        const controls = el('div', 'z-scroll-actions');
        const previous = el('button', 'z-scroll-action', 'Previous');
        const toggle = el('button', 'z-scroll-action primary', player?.isPlaying ? 'Pause' : 'Play');
        const next = el('button', 'z-scroll-action', 'Next');
        previous.type = toggle.type = next.type = 'button';
        previous.addEventListener('click', () => ctx.runCommand('player.previous').catch(error => ctx.emit('zscroll-error',{error})));
        toggle.addEventListener('click', () => {
          ctx.runCommand('player.toggle').catch(error => ctx.emit('zscroll-error',{error}));
        });
        next.addEventListener('click', () => ctx.runCommand('player.next').catch(error => ctx.emit('zscroll-error',{error})));
        controls.append(previous,toggle,next);
        copy.append(controls);
      }
      scene.append(vinyl,copy);
      return scene;
    }
  });

  desktop.registerEffect({
    id: 'zscroll.albums',
    category: 'z-scroll-scene',
    label: 'Albums',
    order: 20,
    weight: 22,
    render: (data, ctx) => {
      const scene = el('div', 'z-scroll-collection');
      const copy = el('div', 'z-scroll-scene-copy');
      copy.append(el('span','z-scroll-eyebrow','Your Albums'));
      copy.append(el('h2','','Pull the shelf toward you.'));
      copy.append(el('p','','Owned, available, and saved releases stay attached to the same canonical library state.'));
      const rail = el('div','z-scroll-album-rail');
      for (const item of (data.albums?.data?.items || []).slice(0,8)) rail.append(albumTile(item,ctx));
      if (!rail.childElementCount) rail.append(el('p','z-scroll-empty','No albums in your collection yet.'));
      scene.append(copy,rail);
      return scene;
    }
  });

  desktop.registerEffect({
    id: 'zscroll.artists',
    category: 'z-scroll-scene',
    label: 'Artists',
    order: 30,
    weight: 18,
    render: (data, ctx) => {
      const scene = el('div','z-scroll-collection');
      const copy = el('div','z-scroll-scene-copy');
      copy.append(el('span','z-scroll-eyebrow','Artists'));
      copy.append(el('h2','','Move through the people behind the records.'));
      const rail = el('div','z-scroll-artist-rail');
      for (const artist of (data.artists?.data?.items || []).slice(0,10)) {
        const card = el('button','z-scroll-artist');
        card.type='button';
        const avatar = el('span','z-scroll-artist-avatar',artist.name?.slice(0,1).toUpperCase() || '?');
        if (artist.profileImageUrl) {
          avatar.replaceChildren();
          const image=document.createElement('img');
          image.src=artist.profileImageUrl; image.alt=''; image.loading='lazy';
          avatar.append(image);
        }
        card.append(avatar,el('strong','',artist.name || 'Artist'),el('span','',artist.followed?'Following':'In your library'));
        card.addEventListener('click',()=>ctx.openLibrary('artists',artist.name || ''));
        rail.append(card);
      }
      if(!rail.childElementCount)rail.append(el('p','z-scroll-empty','Artists will gather here as your library grows.'));
      scene.append(copy,rail);
      return scene;
    }
  });

  desktop.registerEffect({
    id: 'zscroll.playlists',
    category: 'z-scroll-scene',
    label: 'Playlists',
    order: 40,
    weight: 18,
    render: (data, ctx) => {
      const scene=el('div','z-scroll-collection');
      const copy=el('div','z-scroll-scene-copy');
      copy.append(el('span','z-scroll-eyebrow','Playlists'));
      copy.append(el('h2','','Collections built for a moment.'));
      const rail=el('div','z-scroll-playlist-rail');
      for(const item of (data.playlists?.data?.items || []).slice(0,8)){
        const card=el('article','z-scroll-playlist');
        card.append(el('strong','',item.name || 'Playlist'),el('span','',(item.itemCount || 0)+' songs'));
        if(item.description)card.append(el('p','',item.description));
        if(item.playableTrackIds?.length){
          const play=el('button','z-scroll-action','Play');
          play.type='button';
          play.addEventListener('click',async()=>{
            try{
              await ctx.runCommand('player.replace-queue',{recordingIds:item.playableTrackIds,sourceType:'playlist',sourceId:item.id});
              await ctx.runCommand('player.play-recording',{recordingId:item.playableTrackIds[0],sourceType:'playlist',sourceId:item.id});
            }catch(error){ctx.emit('zscroll-error',{error});}
          });
          card.append(play);
        }
        rail.append(card);
      }
      if(!rail.childElementCount)rail.append(el('p','z-scroll-empty','Create a playlist and it will appear here.'));
      scene.append(copy,rail);
      return scene;
    }
  });

  desktop.registerEffect({
    id: 'zscroll.discovery',
    category: 'z-scroll-scene',
    label: 'Recent & Discover',
    order: 50,
    weight: 14,
    render: (data, ctx) => {
      const scene=el('div','z-scroll-split');
      const home=data.home?.data || {};
      const recent=(home.sections || []).find(section=>section.id==='recent')?.items || [];
      const discover=(home.sections || []).find(section=>section.id==='discover')?.items || [];
      const recentPanel=el('section','z-scroll-panel');
      recentPanel.append(el('span','z-scroll-eyebrow','Recently Played'),el('h2','','Where you just were.'));
      const recentList=el('div','z-scroll-mini-list');
      for(const item of recent.slice(0,6)){
        const button=el('button','z-scroll-mini-row');
        button.type='button';
        button.append(el('strong','',item.title || 'Track'),el('span','',item.artistName || ''));
        button.addEventListener('click',()=>ctx.runCommand('player.play-recording',{recordingId:item.recordingId,sourceType:'recent'}).catch(error=>ctx.emit('zscroll-error',{error})));
        recentList.append(button);
      }
      recentPanel.append(recentList);
      const discoverPanel=el('section','z-scroll-panel');
      discoverPanel.append(el('span','z-scroll-eyebrow','Discover'),el('h2','','Something beyond the shelf.'));
      const discoverList=el('div','z-scroll-mini-list');
      for(const item of discover.slice(0,5)){
        const row=el('div','z-scroll-mini-row');
        row.append(el('strong','',item.title || 'Release'),el('span','',item.artist?.name || ''));
        discoverList.append(row);
      }
      discoverPanel.append(discoverList);
      scene.append(recentPanel,discoverPanel);
      return scene;
    }
  });

  desktop.registerEffect({
    id: 'zscroll.library',
    category: 'z-scroll-scene',
    label: 'Full Library',
    order: 60,
    weight: 10,
    render: (data, ctx) => {
      const scene=el('div','z-scroll-library-end');
      const copy=el('div','z-scroll-scene-copy');
      copy.append(el('span','z-scroll-eyebrow','Full Library'));
      copy.append(el('h2','','Drop back into the working surface.'));
      copy.append(el('p','','Search, filter, edit collections, or return to the spatial Desktop without interrupting playback.'));
      const actions=el('div','z-scroll-actions');
      for(const [label,view] of [['Albums','albums'],['Songs','songs'],['Playlists','playlists']]){
        const button=el('button',view==='albums'?'z-scroll-action primary':'z-scroll-action',label);
        button.type='button';
        button.addEventListener('click',()=>ctx.openLibrary(view,''));
        actions.append(button);
      }
      copy.append(actions);
      const count=el('div','z-scroll-library-count');
      count.append(el('strong','',String(data.albums?.data?.items?.length || 0)),el('span','','albums loaded'));
      scene.append(copy,count);
      return scene;
    }
  });
})();
