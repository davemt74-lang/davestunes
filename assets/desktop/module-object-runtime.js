(() => {
  'use strict';

  const desktop = window.DaveTunesDesktop;
  if (!desktop) return;

  const objects = new Map();
  let layer = null;
  let controls = null;
  let activePointer = null;
  let topZ = 1;

  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

  const api = async fields => {
    const body = new URLSearchParams({ csrf_token: csrf(), ...fields });
    const response = await fetch('/desktop-objects.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
      body
    });
    const json = await response.json();
    if (!response.ok || !json.ok) throw new Error(json.error || 'Desktop object request failed.');
    return json;
  };

  const clamp = (value, min, max) => Math.max(min, Math.min(max, value));

  const rendererFor = object => desktop.getRegistry('objectType')?.get(object.type) || null;

  const setTransform = (node, object) => {
    node.style.left = (object.transform.x * 100) + '%';
    node.style.top = (object.transform.y * 100) + '%';
    node.style.zIndex = String(object.transform.z);
    node.style.transform = 'translate(-50%,-50%) rotate(' + object.transform.rotation + 'deg) scale(' + object.transform.scale + ')';
    node.dataset.pinned = object.pinned ? 'true' : 'false';
    node.dataset.revision = String(object.revision);
  };

  const fallbackContent = object => {
    const wrap = document.createElement('div');
    wrap.className = 'desktop-object-fallback';
    const kicker = document.createElement('span');
    kicker.className = 'desktop-object-kicker';
    kicker.textContent = object.type;
    const title = document.createElement('strong');
    title.textContent = object.label || object.key;
    wrap.append(kicker, title);
    if (object.resource?.type && object.resource?.id) {
      const resource = document.createElement('span');
      resource.className = 'desktop-object-resource';
      resource.textContent = object.resource.type + ' #' + object.resource.id;
      wrap.append(resource);
    }
    return wrap;
  };

  const contentFor = object => {
    const definition = rendererFor(object);
    if (!definition || typeof definition.render !== 'function') return fallbackContent(object);
    const rendered = definition.render(object, {
      runCommand: desktop.runCommand,
      emit: desktop.emit,
      selectObject: desktop.selectObject
    });
    return rendered instanceof Node ? rendered : fallbackContent(object);
  };

  const select = id => {
    const numericId = id ? Number(id) : null;
    for (const node of layer?.querySelectorAll('[data-desktop-object-id]') || []) {
      node.dataset.selected = Number(node.dataset.desktopObjectId) === numericId ? 'true' : 'false';
    }
    desktop.selectObject(numericId);
    renderControls();
  };

  const saveUpdate = async (object, changes) => {
    const fields = {
      action: 'update',
      id: String(object.id),
      expected_revision: String(object.revision)
    };
    for (const [key, value] of Object.entries(changes)) {
      if (key === 'payload') fields[key] = JSON.stringify(value ?? {});
      else fields[key] = typeof value === 'boolean' ? (value ? '1' : '0') : String(value);
    }
    try {
      const result = await api(fields);
      objects.set(result.object.id, result.object);
      renderObject(result.object);
      desktop.emit('object-updated', { object: result.object });
      return result.object;
    } catch (error) {
      desktop.emit('object-error', { error, objectId: object.id });
      await load();
      throw error;
    }
  };

  const bringFront = async object => {
    topZ = Math.min(100000, Math.max(topZ + 1, object.transform.z + 1));
    return saveUpdate(object, { z: topZ });
  };

  const removeObject = async object => {
    const result = await api({
      action: 'delete',
      id: String(object.id),
      expected_revision: String(object.revision)
    });
    objects.delete(object.id);
    layer?.querySelector('[data-desktop-object-id="' + object.id + '"]')?.remove();
    if (desktop.state.selectedObjectId === object.id) select(null);
    desktop.emit('object-deleted', { objectId: object.id });
    return result;
  };

  const onPointerDown = event => {
    if (event.button !== 0) return;
    const node = event.currentTarget;
    const id = Number(node.dataset.desktopObjectId || 0);
    const object = objects.get(id);
    if (!object) return;
    select(id);
    if (object.pinned || event.target.closest('[data-object-interactive]')) return;

    event.preventDefault();
    const rect = layer.getBoundingClientRect();
    activePointer = {
      id,
      pointerId: event.pointerId,
      node,
      rect,
      startX: event.clientX,
      startY: event.clientY,
      originX: object.transform.x,
      originY: object.transform.y
    };
    node.setPointerCapture?.(event.pointerId);
    node.dataset.dragging = 'true';
  };

  const onPointerMove = event => {
    if (!activePointer || event.pointerId !== activePointer.pointerId) return;
    const object = objects.get(activePointer.id);
    if (!object) return;
    const x = clamp(activePointer.originX + (event.clientX - activePointer.startX) / Math.max(1, activePointer.rect.width), 0, 1);
    const y = clamp(activePointer.originY + (event.clientY - activePointer.startY) / Math.max(1, activePointer.rect.height), 0, 1);
    object.transform.x = x;
    object.transform.y = y;
    setTransform(activePointer.node, object);
    desktop.emit('object-transform-preview', { object });
  };

  const onPointerUp = async event => {
    if (!activePointer || event.pointerId !== activePointer.pointerId) return;
    const drag = activePointer;
    activePointer = null;
    drag.node.dataset.dragging = 'false';
    drag.node.releasePointerCapture?.(event.pointerId);
    const object = objects.get(drag.id);
    if (!object) return;
    try {
      await saveUpdate(object, { x: object.transform.x, y: object.transform.y });
    } catch (_) {}
  };

  const renderObject = object => {
    if (!layer) return;
    topZ = Math.max(topZ, Number(object.transform.z || 1));
    let node = layer.querySelector('[data-desktop-object-id="' + object.id + '"]');
    if (!node) {
      node = document.createElement('article');
      node.className = 'desktop-spatial-object';
      node.dataset.desktopObjectId = String(object.id);
      node.tabIndex = 0;
      node.addEventListener('pointerdown', onPointerDown);
      node.addEventListener('pointermove', onPointerMove);
      node.addEventListener('pointerup', onPointerUp);
      node.addEventListener('pointercancel', onPointerUp);
      node.addEventListener('focus', () => select(object.id));
      layer.append(node);
    }
    node.replaceChildren(contentFor(object));
    setTransform(node, object);
    node.dataset.selected = desktop.state.selectedObjectId === object.id ? 'true' : 'false';
    node.setAttribute('aria-label', object.label || object.type + ' desktop object');
  };

  const button = (label, action, title = '') => {
    const node = document.createElement('button');
    node.type = 'button';
    node.className = 'desktop-object-control';
    node.textContent = label;
    if (title) node.title = title;
    node.dataset.objectInteractive = 'true';
    node.addEventListener('click', action);
    return node;
  };

  const renderControls = () => {
    if (!controls) return;
    controls.replaceChildren();
    const object = objects.get(Number(desktop.state.selectedObjectId || 0));
    controls.hidden = !object;
    if (!object) return;

    const title = document.createElement('span');
    title.className = 'desktop-object-control-title';
    title.textContent = object.label || object.type;
    controls.append(title);

    controls.append(
      button(object.pinned ? 'Unpin' : 'Pin', async () => {
        try { await saveUpdate(object, { pinned: !object.pinned }); renderControls(); } catch (_) {}
      }),
      button('↶', async () => {
        if (object.pinned) return;
        try { await saveUpdate(object, { rotation: clamp(object.transform.rotation - 5, -180, 180) }); renderControls(); } catch (_) {}
      }, 'Rotate left'),
      button('↷', async () => {
        if (object.pinned) return;
        try { await saveUpdate(object, { rotation: clamp(object.transform.rotation + 5, -180, 180) }); renderControls(); } catch (_) {}
      }, 'Rotate right'),
      button('−', async () => {
        if (object.pinned) return;
        try { await saveUpdate(object, { scale: clamp(object.transform.scale - 0.1, 0.25, 3) }); renderControls(); } catch (_) {}
      }, 'Scale down'),
      button('+', async () => {
        if (object.pinned) return;
        try { await saveUpdate(object, { scale: clamp(object.transform.scale + 0.1, 0.25, 3) }); renderControls(); } catch (_) {}
      }, 'Scale up'),
      button('Front', async () => {
        try { await bringFront(object); renderControls(); } catch (_) {}
      }),
      button('Delete', async () => {
        if (!window.confirm('Remove this object from your desktop?')) return;
        try { await removeObject(object); } catch (_) {}
      })
    );
  };

  const load = async () => {
    const response = await fetch('/desktop-objects.php', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const json = await response.json();
    if (!response.ok || !json.ok) throw new Error(json.error || 'Desktop objects could not be loaded.');
    objects.clear();
    topZ = 1;
    layer?.querySelectorAll('[data-desktop-object-id]').forEach(node => node.remove());
    for (const object of json.objects || []) {
      objects.set(object.id, object);
      renderObject(object);
    }
    if (desktop.state.selectedObjectId && !objects.has(Number(desktop.state.selectedObjectId))) desktop.selectObject(null);
    renderControls();
    desktop.emit('objects-loaded', { objects: [...objects.values()] });
    return [...objects.values()];
  };

  const createObject = async payload => {
    const result = await api({
      action: 'create',
      key: String(payload.key || ''),
      type: String(payload.type || ''),
      resource_type: payload.resourceType || '',
      resource_id: payload.resourceId || '',
      label: payload.label || '',
      x: payload.x ?? 0.5,
      y: payload.y ?? 0.5,
      rotation: payload.rotation ?? 0,
      scale: payload.scale ?? 1,
      z: payload.z ?? (topZ + 1),
      pinned: payload.pinned ? '1' : '0',
      payload: JSON.stringify(payload.payload || {})
    });
    objects.set(result.object.id, result.object);
    renderObject(result.object);
    select(result.object.id);
    desktop.emit('object-created', { object: result.object });
    return result.object;
  };

  const onRegistered = event => {
    if (event.detail?.type !== 'objectType') return;
    for (const object of objects.values()) if (object.type === event.detail.definition?.id) renderObject(object);
  };

  const onKeyDown = event => {
    const target = event.target;
    if (target instanceof HTMLElement && (target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName))) return;
    const object = objects.get(Number(desktop.state.selectedObjectId || 0));
    if (!object || object.pinned) return;

    let changes = null;
    if (event.key === '[') changes = { rotation: clamp(object.transform.rotation - 5, -180, 180) };
    else if (event.key === ']') changes = { rotation: clamp(object.transform.rotation + 5, -180, 180) };
    else if (event.key === '-' || event.key === '_') changes = { scale: clamp(object.transform.scale - 0.1, 0.25, 3) };
    else if (event.key === '+' || event.key === '=') changes = { scale: clamp(object.transform.scale + 0.1, 0.25, 3) };
    if (!changes) return;
    event.preventDefault();
    saveUpdate(object, changes).then(renderControls).catch(() => {});
  };

  desktop.registerCommand({
    id: 'object.create',
    run: async payload => createObject(payload)
  });
  desktop.registerCommand({
    id: 'object.update',
    run: async payload => {
      const object = objects.get(Number(payload.id || 0));
      if (!object) throw new Error('Desktop object was not found.');
      return saveUpdate(object, payload.changes || {});
    }
  });
  desktop.registerCommand({
    id: 'object.delete',
    run: async payload => {
      const object = objects.get(Number(payload.id || 0));
      if (!object) throw new Error('Desktop object was not found.');
      return removeObject(object);
    }
  });
  desktop.registerCommand({
    id: 'object.bring-front',
    run: async payload => {
      const object = objects.get(Number(payload.id || 0));
      if (!object) throw new Error('Desktop object was not found.');
      return bringFront(object);
    }
  });
  desktop.registerCommand({
    id: 'object.reset',
    run: async () => {
      const result = await api({ action: 'reset' });
      objects.clear();
      layer?.querySelectorAll('[data-desktop-object-id]').forEach(node => node.remove());
      select(null);
      desktop.emit('objects-reset', { deleted: result.deleted || 0 });
      return result;
    }
  });
  desktop.registerCommand({
    id: 'object.refresh',
    run: async () => load()
  });

  desktop.registerModule({
    id: 'spatial-object-runtime',
    mount: ctx => {
      layer = ctx.getLayer('objects');
      controls = document.querySelector('[data-desktop-object-controls]');
      const reset = document.querySelector('[data-desktop-reset]');
      if (!layer) throw new Error('Desktop object layer is missing.');

      const onLayerPointer = event => {
        if (event.target === layer) select(null);
      };
      const onReset = async () => {
        if (!window.confirm('Reset your spatial desktop layout?')) return;
        try { await desktop.runCommand('object.reset'); }
        catch (error) { desktop.emit('object-error', { error }); }
      };

      layer.addEventListener('pointerdown', onLayerPointer);
      reset?.addEventListener('click', onReset);
      document.addEventListener('davestunes:desktop:registered', onRegistered);
      document.addEventListener('keydown', onKeyDown);
      load().catch(error => desktop.emit('object-error', { error }));

      return () => {
        activePointer = null;
        layer?.removeEventListener('pointerdown', onLayerPointer);
        reset?.removeEventListener('click', onReset);
        document.removeEventListener('davestunes:desktop:registered', onRegistered);
        document.removeEventListener('keydown', onKeyDown);
      };
    }
  });
})();
