(() => {
  'use strict';

  const REGISTRY_TYPES = Object.freeze(['module','effect','objectType','command','template']);
  const registries = Object.fromEntries(REGISTRY_TYPES.map(type => [type, new Map()]));
  const mountedModules = new Map();

  const state = {
    mode: 'desktop',
    templateId: null,
    selectedObjectId: null,
    playerSnapshot: null,
    ready: false,
  };

  const emit = (name, detail = {}) => {
    document.dispatchEvent(new CustomEvent('davestunes:desktop:' + name, { detail }));
  };

  const assertDefinition = (type, definition) => {
    if (!REGISTRY_TYPES.includes(type)) throw new Error('Unsupported desktop registry type: ' + type);
    if (!definition || typeof definition !== 'object') throw new Error(type + ' definition must be an object.');
    if (!/^[a-z0-9][a-z0-9._:-]{1,79}$/i.test(String(definition.id || ''))) throw new Error(type + ' definition requires a stable id.');
    if (registries[type].has(definition.id)) throw new Error(type + ' is already registered: ' + definition.id);
  };

  const register = (type, definition) => {
    assertDefinition(type, definition);
    const frozen = Object.freeze({ ...definition });
    registries[type].set(frozen.id, frozen);
    emit('registered', { type, definition: frozen });
    if (state.ready && type === 'module') mountModule(frozen.id);
    if (state.ready && type === 'template' && !state.templateId) {
      const root = document.getElementById('dt-desktop-root');
      if (root?.dataset.defaultTemplate === frozen.id) applyTemplate(frozen.id);
    }
    return frozen;
  };

  const getLayer = (name) => document.querySelector('[data-desktop-layer="' + name + '"]');

  const context = () => Object.freeze({
    root: document.getElementById('dt-desktop-root'),
    getLayer,
    state,
    emit,
    runCommand,
    getRegistry: type => registries[type] || null,
  });

  const mountModule = id => {
    const definition = registries.module.get(id);
    if (!definition || mountedModules.has(id)) return mountedModules.get(id) || null;
    if (typeof definition.mount !== 'function') {
      mountedModules.set(id, null);
      return null;
    }
    const cleanup = definition.mount(context()) || null;
    mountedModules.set(id, cleanup);
    emit('module-mounted', { id });
    return cleanup;
  };

  const unmountModule = id => {
    if (!mountedModules.has(id)) return;
    const cleanup = mountedModules.get(id);
    if (typeof cleanup === 'function') {
      try { cleanup(); } catch (_) {}
    }
    mountedModules.delete(id);
    emit('module-unmounted', { id });
  };

  const runCommand = async (id, payload = {}) => {
    const command = registries.command.get(id);
    if (!command || typeof command.run !== 'function') throw new Error('Desktop command is not registered: ' + id);
    emit('command-start', { id, payload });
    try {
      const result = await command.run(payload, context());
      emit('command-complete', { id, payload, result });
      return result;
    } catch (error) {
      emit('command-error', { id, payload, error });
      throw error;
    }
  };

  const applyTemplate = id => {
    const template = registries.template.get(id);
    if (!template) throw new Error('Desktop template is not registered: ' + id);
    const root = document.getElementById('dt-desktop-root');
    if (!root) throw new Error('Desktop root is missing.');
    const tokens = template.tokens || {};
    for (const [name, value] of Object.entries(tokens)) {
      if (!/^--dt-[a-z0-9-]+$/i.test(name)) continue;
      root.style.setProperty(name, String(value));
    }
    root.dataset.desktopTemplate = id;
    state.templateId = id;
    emit('template-changed', { id, template });
    return template;
  };

  const setMode = mode => {
    if (!['desktop','z-scroll'].includes(mode)) throw new Error('Unsupported desktop mode: ' + mode);
    const root = document.getElementById('dt-desktop-root');
    if (!root) return;
    root.dataset.desktopMode = mode;
    state.mode = mode;
    emit('mode-changed', { mode });
  };

  const selectObject = objectId => {
    state.selectedObjectId = objectId || null;
    emit('selection-changed', { objectId: state.selectedObjectId });
  };

  const api = Object.freeze({
    state,
    registerModule: definition => register('module', definition),
    registerEffect: definition => register('effect', definition),
    registerObjectType: definition => register('objectType', definition),
    registerCommand: definition => register('command', definition),
    registerTemplate: definition => register('template', definition),
    getRegistry: type => registries[type] || null,
    getLayer,
    mountModule,
    unmountModule,
    runCommand,
    applyTemplate,
    setMode,
    selectObject,
    emit,
  });

  window.DaveTunesDesktop = api;

  const boot = () => {
    const root = document.getElementById('dt-desktop-root');
    if (!root) return;
    const bootNode = document.getElementById('dt-desktop-boot');
    if (bootNode) {
      try { root.dataset.userId = String(JSON.parse(bootNode.textContent || '{}').user?.id || ''); }
      catch (_) {}
    }
    for (const id of registries.module.keys()) mountModule(id);
    const defaultTemplate = root.dataset.defaultTemplate;
    if (defaultTemplate && registries.template.has(defaultTemplate)) applyTemplate(defaultTemplate);
    setMode('desktop');
    state.ready = true;
    emit('ready', { desktop: api });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
