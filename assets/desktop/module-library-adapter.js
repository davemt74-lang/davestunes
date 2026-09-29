(() => {
  'use strict';

  const desktop = window.DaveTunesDesktop;
  if (!desktop) return;

  const state = {
    view: 'home',
    query: '',
    request: null,
    requestId: 0,
    payload: null,
  };

  const element = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = String(text);
    return node;
  };

  const badge = text => element('span', 'desktop-data-badge', text);

  const empty = (mount, title, copy = '') => {
    mount.replaceChildren();
    const box = element('div', 'library-empty');
    const inner = element('div');
    inner.append(element('strong', '', title));
    if (copy) inner.append(document.createTextNode(copy));
    box.append(inner);
    mount.append(box);
  };

  const albumCard = (item, ctx) => {
    const card = element('article', 'desktop-album-card');
    card.dataset.releaseId = String(item.id);

    const art = element('div', 'desktop-album-art');
    if (item.coverUrl) {
      const image = document.createElement('img');
      image.src = item.coverUrl;
      image.alt = '';
      image.loading = 'lazy';
      art.append(image);
    } else {
      art.append(element('span', '', item.title.slice(0, 1).toUpperCase()));
    }

    const copy = element('div', 'desktop-card-copy');
    copy.append(element('strong', '', item.title));
    copy.append(element('span', 'desktop-card-meta', item.artist?.name || ''));
    const badges = element('div', 'desktop-data-badges');
    if (item.owned) badges.append(badge('Owned'));
    else if (item.available) badges.append(badge('Access'));
    if (item.saved) badges.append(badge('Saved'));
    if (item.releaseType) badges.append(badge(item.releaseType));
    copy.append(badges);

    const actions = element('div', 'desktop-card-actions');
    const place = element('button', 'desktop-card-button secondary', 'Place');
    place.type = 'button';
    place.title = 'Place this album on your desktop';
    place.addEventListener('click', async event => {
      event.stopPropagation();
      try {
        await ctx.runCommand('media.place-release', { releaseId: item.id });
      } catch (error) { ctx.emit('media-object-error', { error }); }
    });
    const open = element('button', 'desktop-card-button', 'Open');
    open.type='button';
    open.addEventListener('click',event=>{event.stopPropagation();window.location.assign(item.profileUrl||('/album.php?release='+item.id));});
    actions.append(place,open);
    if(item.experienceAvailable){
      const experience=element('button','desktop-card-button','Experience');
      experience.type='button';
      experience.addEventListener('click',event=>{event.stopPropagation();window.location.assign(item.experienceUrl||('/album-experience.php?release='+item.id));});
      actions.append(experience);
    }

    if (item.playableTrackIds?.length) {
      const play = element('button', 'desktop-card-button', 'Play');
      play.type = 'button';
      play.addEventListener('click', async event => {
        event.stopPropagation();
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
        } catch (error) { ctx.emit('library-error', { error }); }
      });
      actions.append(play);
    }
    card.append(art, copy, actions);
    return card;
  };

  const songRow = (item, ctx) => {
    const row = element('div', 'desktop-song-row');
    row.dataset.recordingId = String(item.id);
    const copy = element('div', 'desktop-card-copy');
    copy.append(element('strong', '', item.title));
    const subtitle = [item.artist?.name, item.versionLabel].filter(Boolean).join(' · ');
    copy.append(element('span', 'desktop-card-meta', subtitle));
    const badges = element('div', 'desktop-data-badges');
    if (item.owned) badges.append(badge('Owned'));
    else if (item.available) badges.append(badge('Access'));
    if (item.saved) badges.append(badge('Saved'));
    copy.append(badges);
    row.append(copy);
    if (item.playable) {
      const play = element('button', 'desktop-card-button', 'Play');
      play.type = 'button';
      play.addEventListener('click', async () => {
        try {
          await ctx.runCommand('player.play-recording', { recordingId: item.id, sourceType: 'library' });
        } catch (error) { ctx.emit('library-error', { error }); }
      });
      row.append(play);
    }
    return row;
  };

  const artistCard = (item, ctx) => {
    const card = element('article', 'desktop-artist-card');
    card.dataset.artistId = String(item.id);
    const avatar = element('div', 'desktop-artist-avatar');
    if (item.profileImageUrl) {
      const image = document.createElement('img');
      image.src = item.profileImageUrl;
      image.alt = '';
      image.loading = 'lazy';
      avatar.append(image);
    } else {
      avatar.append(element('span', '', item.name.slice(0, 1).toUpperCase()));
    }
    const copy = element('div', 'desktop-card-copy');
    copy.append(element('strong', '', item.name));
    copy.append(element('span', 'desktop-card-meta', item.location || ('@' + item.slug)));
    if (item.followed) copy.append(badge('Following'));
    const actions=element('div','desktop-card-actions');
    const place=element('button','desktop-card-button secondary','Place');
    place.type='button';place.addEventListener('click',event=>{event.stopPropagation();ctx.runCommand('media.place-artist',{artistId:item.id}).catch(error=>ctx.emit('media-object-error',{error}));});
    const open=element('button','desktop-card-button','Open');
    open.type='button';open.addEventListener('click',event=>{event.stopPropagation();window.location.assign(item.profileUrl||('/artist.php?artist='+encodeURIComponent(item.slug||String(item.id))));});
    actions.append(place,open);
    if(item.experienceAvailable){
      const experience=element('button','desktop-card-button','Experience');
      experience.type='button';experience.addEventListener('click',event=>{event.stopPropagation();window.location.assign(item.experienceUrl||('/artist-experience.php?artist='+item.id));});
      actions.append(experience);
    }
    card.append(avatar, copy, actions);
    return card;
  };

  const recentRow = (item, ctx) => {
    const row = element('button', 'desktop-recent-row');
    row.type = 'button';
    const copy = element('span', 'desktop-card-copy');
    copy.append(element('strong', '', item.title));
    copy.append(element('span', 'desktop-card-meta', item.artistName));
    row.append(copy, badge(item.accessMode));
    row.addEventListener('click', async () => {
      try { await ctx.runCommand('player.play-recording', { recordingId: item.recordingId, sourceType: 'recent' }); }
      catch (error) { ctx.emit('library-error', { error }); }
    });
    return row;
  };

  const collectionCard = (item, ctx) => {
    const card = element('article', 'desktop-collection-card');
    const copy = element('div', 'desktop-card-copy');
    copy.append(element('strong', '', item.name));
    const detail = item.type === 'playlist'
      ? ((item.itemCount || 0) + ' songs')
      : ((item.itemCount || 0) + ' items');
    copy.append(element('span', 'desktop-card-meta', detail));
    if (item.description) copy.append(element('span', 'desktop-card-description', item.description));
    card.append(copy);

    if (item.playableTrackIds?.length) {
      const play = element('button', 'desktop-card-button', 'Play');
      play.type = 'button';
      play.addEventListener('click', async () => {
        try {
          await ctx.runCommand('player.replace-queue', {
            recordingIds: item.playableTrackIds,
            sourceType: item.type,
            sourceId: item.id
          });
          await ctx.runCommand('player.play-recording', {
            recordingId: item.playableTrackIds[0],
            sourceType: item.type,
            sourceId: item.id
          });
        } catch (error) { ctx.emit('library-error', { error }); }
      });
      card.append(play);
    }
    return card;
  };

  const renderItems = (mount, items, ctx, kind) => {
    mount.replaceChildren();
    if (!items?.length) {
      empty(mount, 'Nothing here yet.', state.query ? 'Try a different search.' : 'Your library will grow here.');
      return;
    }
    const grid = element('div', kind === 'songs' || kind === 'recent' ? 'desktop-data-list' : 'desktop-data-grid');
    for (const item of items) {
      if (kind === 'albums') grid.append(albumCard(item, ctx));
      else if (kind === 'artists') grid.append(artistCard(item, ctx));
      else if (kind === 'songs') grid.append(songRow(item, ctx));
      else if (kind === 'recent') grid.append(recentRow(item, ctx));
      else grid.append(collectionCard(item, ctx));
    }
    mount.append(grid);
  };

  const renderHome = (mount, data, ctx) => {
    mount.replaceChildren();
    if (data.hero) {
      const hero = element('section', 'desktop-library-hero');
      const label = element('span', 'desktop-hero-label', data.hero.owned ? 'Owned album' : data.hero.available ? 'Available now' : 'From your library');
      const title = element('strong', '', data.hero.title);
      const artist = element('span', 'desktop-card-meta', data.hero.artist?.name || '');
      hero.append(label, title, artist);
      if (data.hero.playableTrackIds?.length) {
        const play = element('button', 'desktop-card-button', 'Play album');
        play.type = 'button';
        play.addEventListener('click', async () => {
          try {
            await ctx.runCommand('player.replace-queue', { recordingIds: data.hero.playableTrackIds, sourceType: 'release', sourceId: data.hero.id });
            await ctx.runCommand('player.play-recording', { recordingId: data.hero.playableTrackIds[0], sourceType: 'release', sourceId: data.hero.id });
          } catch (error) { ctx.emit('library-error', { error }); }
        });
        hero.append(play);
      }
      mount.append(hero);
    }

    for (const section of data.sections || []) {
      if (!section.items?.length) continue;
      const block = element('section', 'desktop-data-section');
      const heading = element('div', 'desktop-section-heading');
      heading.append(element('h2', '', section.title));
      block.append(heading);
      const holder = element('div');
      block.append(holder);
      if (section.id === 'artists') renderItems(holder, section.items, ctx, 'artists');
      else if (section.id === 'recent') renderItems(holder, section.items, ctx, 'recent');
      else renderItems(holder, section.items, ctx, 'albums');
      mount.append(block);
    }

    if (!mount.childElementCount) empty(mount, 'Your desktop is ready.', 'Add or save music to start building your collection.');
  };

  const render = (ctx, payload) => {
    const mount = document.querySelector('[data-desktop-mount="library-content"]');
    if (!mount) return;
    if (payload.view === 'home') renderHome(mount, payload.data, ctx);
    else renderItems(mount, payload.data?.items || [], ctx, payload.view);
  };

  const setBusy = busy => {
    const root = document.getElementById('dt-desktop-root');
    const mount = document.querySelector('[data-desktop-mount="library-content"]');
    if (root) root.dataset.libraryBusy = busy ? 'true' : 'false';
    if (mount) mount.setAttribute('aria-busy', busy ? 'true' : 'false');
  };

  const load = async (ctx, view = state.view, query = state.query) => {
    state.view = view;
    state.query = query;
    state.request?.abort();
    const controller = new AbortController();
    state.request = controller;
    const requestId = ++state.requestId;
    setBusy(true);
    try {
      const url = new URL('/desktop-data.php', window.location.origin);
      url.searchParams.set('view', state.view);
      if (state.query) url.searchParams.set('q', state.query);
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        signal: controller.signal
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Library request failed.');
      if (requestId !== state.requestId) return null;
      state.payload = payload;
      render(ctx, payload);
      ctx.emit('library-loaded', { view: state.view, query: state.query, payload });
      return payload;
    } catch (error) {
      if (error?.name === 'AbortError') return null;
      const mount = document.querySelector('[data-desktop-mount="library-content"]');
      if (mount) empty(mount, 'Library could not load.', 'Try again.');
      ctx.emit('library-error', { error });
      throw error;
    } finally {
      if (requestId === state.requestId) setBusy(false);
    }
  };

  desktop.registerCommand({
    id: 'library.open-view',
    run: async (payload, ctx) => load(ctx, String(payload.view || 'home'), state.query)
  });

  desktop.registerCommand({
    id: 'library.search',
    run: async (payload, ctx) => load(ctx, state.view, String(payload.query || ''))
  });

  desktop.registerCommand({
    id: 'library.refresh',
    run: async (_payload, ctx) => load(ctx, state.view, state.query)
  });

  desktop.registerModule({
    id: 'canonical-library-adapter',
    mount: ctx => {
      const tabs = [...document.querySelectorAll('[data-library-view]')];
      const search = document.querySelector('[data-library-search]');
      let searchTimer = null;

      const activate = view => {
        for (const tab of tabs) tab.setAttribute('aria-selected', tab.dataset.libraryView === view ? 'true' : 'false');
      };

      const onTab = event => {
        const view = event.currentTarget.dataset.libraryView || 'home';
        activate(view);
        load(ctx, view, state.query).catch(() => {});
      };

      const onSearch = () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => load(ctx, state.view, search?.value || '').catch(() => {}), 240);
      };

      tabs.forEach(tab => tab.addEventListener('click', onTab));
      search?.addEventListener('input', onSearch);
      activate(state.view);
      load(ctx).catch(() => {});

      return () => {
        state.request?.abort();
        clearTimeout(searchTimer);
        tabs.forEach(tab => tab.removeEventListener('click', onTab));
        search?.removeEventListener('input', onSearch);
      };
    }
  });
})();
