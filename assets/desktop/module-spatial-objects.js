(() => {
  'use strict';

  const desktop = window.DaveTunesDesktop;
  if (!desktop) return;

  const state = {
    layoutKey: 'primary',
    revision: 0,
    objects: new Map(),
    selectedUuid: null,
    layer: null,
    toolbar: null,
    loading: false,
  };

  const el = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = String(text);
    return node;
  };

  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

  const request = async (fields = null) => {
    if (fields === null) {
      const url = new URL('/desktop-layout.php', window.location.origin);
      url.searchParams.set('layout', state.layoutKey);
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const json = await response.json();
      if (!response.ok || !json.ok) throw Object.assign(new Error(json.error || 'Desktop layout request failed.'), { conflict: response.status === 409 });
      return json;
    }

    const body = new URLSearchParams({
      layout_key: state.layoutKey,
      csrf_token: csrf(),
      ...Object.fromEntries(Object.entries(fields).map(([key, value]) => [key, value == null ? '' : String(value)]))
    });
    const response = await fetch('/desktop-layout.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json' },
      body
    });
    const json = await response.json();
    if (!response.ok || !json.ok) throw Object.assign(new Error(json.error || 'Desktop layout request failed.'), { conflict: response.status === 409 || Boolean(json.conflict) });
    return json;
  };

  const safeImage = (url, className = '') => {
    if (!url || typeof url !== 'string' || !url.startsWith('/')) return null;
    const image = document.createElement('img');
    image.src = url;
    image.alt = '';
    image.draggable = false;
    if (className) image.className = className;
    return image;
  };

  const releaseRenderer = object => {
    const resource = object.resource;
    const root = el('div', 'spatial-release');
    const art = el('div', 'spatial-release-art');
    const image = safeImage(resource?.coverUrl || '');
    if (image) art.append(image);
    else art.append(el('span', '', (resource?.title || '?').slice(0, 1).toUpperCase()));
    const copy = el('div', 'spatial-object-copy');
    copy.append(el('strong', '', resource?.title || 'Unavailable album'));
    copy.append(el('span', '', resource?.artist?.name || ''));
    root.append(art, copy);
    return root;
  };

  const recordingRenderer = object => {
    const root = el('div', 'spatial-recording');
    root.append(el('span', 'spatial-recording-icon', '♪'));
    const copy = el('div', 'spatial-object-copy');
    copy.append(el('strong', '', object.resource?.title || 'Unavailable song'));
    copy.append(el('span', '', object.resource?.artist?.name || ''));
    root.append(copy);
    return root;
  };

  const artistRenderer = object => {
    const root = el('div', 'spatial-artist');
    const avatar = el('div', 'spatial-artist-avatar');
    const image = safeImage(object.resource?.profileImageUrl || '');
    if (image) avatar.append(image);
    else avatar.append(el('span', '', (object.resource?.name || '?').slice(0, 1).toUpperCase()));
    const copy = el('div', 'spatial-object-copy');
    copy.append(el('strong', '', object.resource?.name || 'Unavailable artist'));
    copy.append(el('span', '', object.resource?.slug ? '@' + object.resource.slug : ''));
    root.append(avatar, copy);
    return root;
  };

  const collectionRenderer = object => {
    const root = el('div', 'spatial-collection');
    root.append(el('span', 'spatial-collection-icon', object.type === 'playlist' ? '≡' : '▥'));
    const copy = el('div', 'spatial-object-copy');
    copy.append(el('strong', '', object.resource?.name || ('Unavailable ' + object.type)));
    copy.append(el('span', '', String(object.resource?.itemCount || 0) + (object.type === 'playlist' ? ' songs' : ' items')));
    root.append(copy);
    return root;
  };

  const noteRenderer = object => {
    const root = el('div', 'spatial-note spatial-note-' + (object.payload?.color || 'yellow'));
    const textarea = document.createElement('textarea');
    textarea.className = 'spatial-note-editor';
    textarea.value = object.payload?.text || '';
    textarea.maxLength = 4000;
    textarea.setAttribute('aria-label', 'Desktop note');
    root.append(textarea);
    return root;
  };

  const photoRenderer = object => {
    const root = el('figure', 'spatial-photo');
    const image = safeImage(object.payload?.imageUrl || '');
    if (image) root.append(image);
    else root.append(el('div', 'spatial-photo-missing', 'Photo unavailable'));
    if (object.payload?.caption) root.append(el('figcaption', '', object.payload.caption));
    return root;
  };

  const registerTypes = () => {
    [
      { id: 'release', label: 'Album', className: 'desktop-object-release', render: releaseRenderer },
      { id: 'recording', label: 'Song', className: 'desktop-object-recording', render: recordingRenderer },
      { id: 'artist', label: 'Artist', className: 'desktop-object-artist', render: artistRenderer },
      { id: 'crate', label: 'Crate', className: 'desktop-object-crate', render: collectionRenderer },
      { id: 'playlist', label: 'Playlist', className: 'desktop-object-playlist', render: collectionRenderer },
      { id: 'note', label: 'Note', className: 'desktop-object-note', render: noteRenderer },
      { id: 'photo', label: 'Photo', className: 'desktop-object-photo', render: photoRenderer },
    ].forEach(definition => desktop.registerObjectType(definition));
  };

  const playableIds = object => {
    if (object.type === 'release' || object.type === 'playlist') return object.resource?.playableTrackIds || [];
    if (object.type === 'recording') return object.resource?.playable ? [object.resource.id] : [];
    if (object.type === 'crate') {
      const ids = [];
      for (const item of object.resource?.items || []) {
        if (item.type === 'release') ids.push(...(item.playableTrackIds || []));
        else if (item.type === 'recording' && item.playable) ids.push(item.id);
      }
      return [...new Set(ids)];
    }
    return [];
  };

  const activateObject = async (object, ctx) => {
    const ids = playableIds(object);
    if (!ids.length) return;
    try {
      await ctx.runCommand('player.replace-queue', { recordingIds: ids, sourceType: object.type, sourceId: object.resourceId });
      await ctx.runCommand('player.play-recording', { recordingId: ids[0], sourceType: object.type, sourceId: object.resourceId });
    } catch (error) {
      ctx.emit('object-error', { error, object });
    }
  };

  const applyTransform = (node, object) => {
    node.style.left = (object.x * 100) + '%';
    node.style.top = (object.y * 100) + '%';
    node.style.zIndex = String(object.z);
    node.style.transform = 'rotate(' + object.rotation + 'deg) scale(' + object.scale + ')';
    node.classList.toggle('is-locked', Boolean(object.locked));
    node.classList.toggle('is-selected', object.uuid === state.selectedUuid);
  };

  const updateToolbar = ctx => {
    if (!state.toolbar) return;
    const object = state.selectedUuid ? state.objects.get(state.selectedUuid) : null;
    state.toolbar.hidden = !object;
    if (!object) return;
    const label = state.toolbar.querySelector('[data-object-toolbar-label]');
    const lock = state.toolbar.querySelector('[data-object-toolbar-lock]');
    if (label) label.textContent = object.resource?.title || object.resource?.name || (object.type === 'note' ? 'Note' : object.type);
    if (lock) lock.textContent = object.locked ? 'Unlock' : 'Lock';
  };

  const select = (uuid, ctx) => {
    state.selectedUuid = uuid || null;
    desktop.selectObject(state.selectedUuid);
    for (const [key, object] of state.objects) {
      const node = state.layer?.querySelector('[data-object-uuid="' + CSS.escape(key) + '"]');
      if (node) node.classList.toggle('is-selected', key === state.selectedUuid);
    }
    updateToolbar(ctx);
  };

  const acceptObjectResponse = (json, ctx) => {
    state.revision = Number(json.layoutRevision ?? state.revision);
    if (json.object) {
      state.objects.set(json.object.uuid, json.object);
      renderOne(json.object, ctx);
      select(json.object.uuid, ctx);
    }
    return json.object || null;
  };

  const transformObject = async (object, patch, ctx) => {
    try {
      const json = await request({
        action: 'transform',
        object_uuid: object.uuid,
        expected_version: object.version,
        x: patch.x ?? object.x,
        y: patch.y ?? object.y,
        rotation: patch.rotation ?? object.rotation,
        scale: patch.scale ?? object.scale,
        bring_to_front: patch.bringToFront ? '1' : ''
      });
      return acceptObjectResponse(json, ctx);
    } catch (error) {
      if (error.conflict) await load(ctx);
      ctx.emit('object-error', { error, object });
      throw error;
    }
  };

  const wireDrag = (node, object, ctx) => {
    let drag = null;
    let frame = 0;

    const onMove = event => {
      if (!drag || event.pointerId !== drag.pointerId) return;
      const dx = (event.clientX - drag.startX) / Math.max(1, drag.layerWidth);
      const dy = (event.clientY - drag.startY) / Math.max(1, drag.layerHeight);
      drag.x = Math.max(0, Math.min(0.92, drag.baseX + dx));
      drag.y = Math.max(0, Math.min(0.92, drag.baseY + dy));
      if (!frame) frame = requestAnimationFrame(() => {
        frame = 0;
        const preview = { ...object, x: drag.x, y: drag.y, z: drag.previewZ };
        applyTransform(node, preview);
      });
    };

    const finish = async event => {
      if (!drag || event.pointerId !== drag.pointerId) return;
      const completed = drag;
      drag = null;
      node.releasePointerCapture?.(event.pointerId);
      node.removeEventListener('pointermove', onMove);
      node.removeEventListener('pointerup', finish);
      node.removeEventListener('pointercancel', finish);
      if (frame) cancelAnimationFrame(frame);
      frame = 0;
      try {
        await transformObject(object, { x: completed.x, y: completed.y, bringToFront: true }, ctx);
      } catch (_) {
        renderAll(ctx);
      }
    };

    node.addEventListener('pointerdown', event => {
      if (event.button !== 0) return;
      if (event.target.closest('textarea,button,a,input,select')) return;
      select(object.uuid, ctx);
      if (object.locked) return;
      event.preventDefault();
      const rect = state.layer.getBoundingClientRect();
      const maxZ = Math.max(1, ...[...state.objects.values()].map(item => Number(item.z || 0))) + 1;
      drag = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        baseX: object.x,
        baseY: object.y,
        x: object.x,
        y: object.y,
        layerWidth: rect.width,
        layerHeight: rect.height,
        previewZ: maxZ,
      };
      node.setPointerCapture?.(event.pointerId);
      node.addEventListener('pointermove', onMove);
      node.addEventListener('pointerup', finish);
      node.addEventListener('pointercancel', finish);
    });
  };

  const wireEditablePayload = (node, object, ctx) => {
    if (object.type !== 'note') return;
    const textarea = node.querySelector('.spatial-note-editor');
    if (!textarea) return;
    textarea.addEventListener('focus', () => select(object.uuid, ctx));
    textarea.addEventListener('blur', async () => {
      const text = textarea.value.slice(0, 4000);
      if (text === (object.payload?.text || '')) return;
      try {
        const json = await request({
          action: 'update_payload',
          object_uuid: object.uuid,
          expected_version: object.version,
          payload_json: JSON.stringify({ text, color: object.payload?.color || 'yellow' })
        });
        acceptObjectResponse(json, ctx);
      } catch (error) {
        if (error.conflict) await load(ctx);
        ctx.emit('object-error', { error, object });
      }
    });
  };

  function renderOne(object, ctx) {
    if (!state.layer) return;
    const previous = state.layer.querySelector('[data-object-uuid="' + CSS.escape(object.uuid) + '"]');
    previous?.remove();

    const type = desktop.getRegistry('objectType')?.get(object.type);
    const node = el('article', 'desktop-spatial-object ' + (type?.className || ''));
    node.dataset.objectUuid = object.uuid;
    node.tabIndex = 0;
    node.setAttribute('aria-label', type?.label || object.type);
    if (object.unavailable) node.append(el('div', 'spatial-unavailable', 'This item is no longer available.'));
    else if (type?.render) node.append(type.render(object, ctx));
    else node.append(el('div', 'spatial-unavailable', object.type));

    applyTransform(node, object);
    node.addEventListener('click', event => {
      if (!event.target.closest('textarea,button,a,input,select')) select(object.uuid, ctx);
    });
    node.addEventListener('dblclick', event => {
      if (event.target.closest('textarea,button,a,input,select')) return;
      activateObject(object, ctx);
    });
    node.addEventListener('keydown', event => {
      if (event.key === 'Enter' && !event.target.closest('textarea')) activateObject(object, ctx);
    });
    wireDrag(node, object, ctx);
    wireEditablePayload(node, object, ctx);
    state.layer.append(node);
  }

  const renderAll = ctx => {
    if (!state.layer) return;
    state.layer.querySelectorAll('.desktop-spatial-object').forEach(node => node.remove());
    [...state.objects.values()].sort((a,b) => a.z - b.z).forEach(object => renderOne(object, ctx));
    updateToolbar(ctx);
  };

  const load = async ctx => {
    if (state.loading) return;
    state.loading = true;
    try {
      const json = await request();
      state.revision = Number(json.layout?.revision || 0);
      state.objects = new Map((json.objects || []).map(object => [object.uuid, object]));
      if (state.selectedUuid && !state.objects.has(state.selectedUuid)) state.selectedUuid = null;
      renderAll(ctx);
      ctx.emit('objects-loaded', { revision: state.revision, objects: [...state.objects.values()] });
    } catch (error) {
      ctx.emit('object-error', { error });
      throw error;
    } finally {
      state.loading = false;
    }
  };

  const createToolbar = ctx => {
    const system = desktop.getLayer('system');
    if (!system) return null;
    const toolbar = el('div', 'desktop-object-toolbar');
    toolbar.hidden = true;
    const label = el('strong', 'desktop-object-toolbar-label');
    label.dataset.objectToolbarLabel = '1';
    const front = el('button', 'desktop-chip', 'Front');
    front.type = 'button';
    front.dataset.objectToolbarFront = '1';
    const lock = el('button', 'desktop-chip', 'Lock');
    lock.type = 'button';
    lock.dataset.objectToolbarLock = '1';
    const remove = el('button', 'desktop-chip desktop-chip-danger', 'Remove');
    remove.type = 'button';
    remove.dataset.objectToolbarRemove = '1';
    toolbar.append(label, front, lock, remove);

    front.addEventListener('click', async () => {
      const object = state.objects.get(state.selectedUuid);
      if (!object || object.locked) return;
      try { await transformObject(object, { bringToFront: true }, ctx); } catch (_) {}
    });

    lock.addEventListener('click', async () => {
      const object = state.objects.get(state.selectedUuid);
      if (!object) return;
      try {
        const json = await request({
          action: 'set_lock',
          object_uuid: object.uuid,
          expected_version: object.version,
          locked: object.locked ? '' : '1'
        });
        acceptObjectResponse(json, ctx);
      } catch (error) {
        if (error.conflict) await load(ctx);
        ctx.emit('object-error', { error, object });
      }
    });

    remove.addEventListener('click', async () => {
      const object = state.objects.get(state.selectedUuid);
      if (!object || !window.confirm('Remove this object from your Desktop?')) return;
      try {
        const json = await request({ action: 'remove', object_uuid: object.uuid, expected_version: object.version });
        state.revision = Number(json.layoutRevision ?? state.revision);
        state.objects.delete(object.uuid);
        state.selectedUuid = null;
        renderAll(ctx);
        desktop.selectObject(null);
        ctx.emit('object-removed', { object });
      } catch (error) {
        if (error.conflict) await load(ctx);
        ctx.emit('object-error', { error, object });
      }
    });

    system.append(toolbar);
    return toolbar;
  };

  const addObject = async (payload, ctx) => {
    const count = state.objects.size;
    const x = payload.x ?? (0.06 + ((count * 0.103) % 0.58));
    const y = payload.y ?? (0.12 + ((count * 0.137) % 0.60));
    const rotation = payload.rotation ?? (((count * 7) % 19) - 9);
    try {
      const json = await request({
        action: 'create',
        object_type: payload.objectType,
        resource_id: payload.resourceId ?? '',
        x, y, rotation,
        scale: payload.scale ?? 1,
        pinned: payload.pinned ? '1' : '',
        payload_json: JSON.stringify(payload.payload || {}),
        expected_layout_revision: state.revision
      });
      const object = acceptObjectResponse(json, ctx);
      ctx.emit('object-created', { object });
      return object;
    } catch (error) {
      if (error.conflict) await load(ctx);
      ctx.emit('object-error', { error });
      throw error;
    }
  };

  registerTypes();

  desktop.registerCommand({ id: 'desktop.add-object', run: (payload, ctx) => addObject(payload, ctx) });
  desktop.registerCommand({ id: 'desktop.reload-objects', run: (_payload, ctx) => load(ctx) });
  desktop.registerCommand({
    id: 'desktop.reset-layout',
    run: async (_payload, ctx) => {
      const json = await request({ action: 'reset', expected_layout_revision: state.revision });
      state.revision = Number(json.layoutRevision || state.revision);
      state.objects.clear();
      state.selectedUuid = null;
      renderAll(ctx);
      desktop.selectObject(null);
      ctx.emit('layout-reset', { removedCount: Number(json.removedCount || 0) });
      return json;
    }
  });

  desktop.registerModule({
    id: 'spatial-object-runtime',
    mount: ctx => {
      state.layer = desktop.getLayer('objects');
      if (!state.layer) return null;
      state.toolbar = createToolbar(ctx);

      const noteButton = document.querySelector('[data-desktop-action="new-note"]');
      const resetButton = document.querySelector('[data-desktop-action="reset-layout"]');

      const newNote = () => addObject({
        objectType: 'note',
        payload: { text: 'New note', color: 'yellow' },
        scale: 1
      }, ctx).catch(() => {});

      const reset = async () => {
        if (!window.confirm('Reset your Desktop layout? Your music stays in your library.')) return;
        try { await desktop.runCommand('desktop.reset-layout'); }
        catch (error) {
          if (error.conflict) await load(ctx);
          ctx.emit('object-error', { error });
        }
      };

      noteButton?.addEventListener('click', newNote);
      resetButton?.addEventListener('click', reset);
      load(ctx).catch(() => {});

      return () => {
        noteButton?.removeEventListener('click', newNote);
        resetButton?.removeEventListener('click', reset);
        state.toolbar?.remove();
        state.toolbar = null;
        state.layer?.querySelectorAll('.desktop-spatial-object').forEach(node => node.remove());
        state.layer = null;
      };
    }
  });
})();
