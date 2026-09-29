(() => {
  'use strict';

  const listeners = new Map();
  const audio = new Audio();
  audio.preload = 'metadata';

  const uuidv4 = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(v => v.toString(16).padStart(2, '0')).join('');
    return hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
  };

  const sessionKey = (() => {
    const storageKey = 'davestunes.player.session';
    let value = localStorage.getItem(storageKey);
    if (!value) {
      value = 'browser:' + uuidv4();
      localStorage.setItem(storageKey, value);
    }
    return value;
  })();

  const state = {
    sessionKey,
    current: null,
    queue: [],
    queueRevision: 0,
    playToken: null,
    lastHeartbeatAt: 0,
    volume: 1,
    ready: false,
  };

  const emit = (name, detail = {}) => {
    document.dispatchEvent(new CustomEvent('davestunes:player:' + name, { detail }));
    (listeners.get(name) || []).forEach(fn => {
      try { fn(detail); } catch (_) {}
    });
  };

  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

  const api = async (fields) => {
    const body = new URLSearchParams({ session_key: state.sessionKey, csrf_token: csrf(), ...fields });
    const response = await fetch('/player-state.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body
    });
    const json = await response.json();
    if (!response.ok || !json.ok) throw new Error(json.error || 'Player request failed.');
    return json;
  };

  const loadSession = async () => {
    const response = await fetch('/player-state.php?session=' + encodeURIComponent(state.sessionKey), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });
    const json = await response.json();
    if (!response.ok || !json.ok) throw new Error(json.error || 'Player state could not be loaded.');
    state.queue = json.queue || [];
    state.queueRevision = Number(json.session?.queue_revision || 0);
    state.volume = Math.max(0, Math.min(1, Number(json.session?.volume ?? 1)));
    state.current = json.current || null;
    audio.volume = state.volume;

    if (state.current?.stream_url) {
      const resumeAt = Math.max(0, Number(json.session?.position_ms || 0) / 1000);
      audio.src = state.current.stream_url;
      audio.addEventListener('loadedmetadata', () => {
        if (Number.isFinite(audio.duration)) audio.currentTime = Math.min(resumeAt, Math.max(0, audio.duration - 0.1));
        render();
      }, { once: true });
    }
    render();
    emit('restore', { state: { ...state }, session: json.session });
  };

  const persistState = async (playbackState) => {
    if (!state.current) return;
    try {
      const result = await api({
        action: 'state',
        recording_id: String(state.current.id),
        playback_state: playbackState,
        position_ms: String(Math.round(audio.currentTime * 1000)),
        volume: String(audio.volume),
      });
      state.volume = Number(result.session?.volume ?? state.volume);
    } catch (error) { emit('error', { error }); }
  };

  const heartbeat = async (completed = false) => {
    if (!state.playToken || !state.current) return;
    const now = Date.now();
    const delta = state.lastHeartbeatAt ? Math.max(0, now - state.lastHeartbeatAt) : 0;
    state.lastHeartbeatAt = now;
    try {
      await api({
        action: 'heartbeat',
        play_token: state.playToken,
        position_ms: String(Math.round(audio.currentTime * 1000)),
        listened_delta_ms: String(delta),
        completed: completed ? '1' : '',
      });
    } catch (error) { emit('error', { error }); }
  };

  const render = () => {
    const dock = document.getElementById('dt-player-dock');
    if (!dock) return;
    const title = dock.querySelector('[data-player-title]');
    const artist = dock.querySelector('[data-player-artist]');
    const toggle = dock.querySelector('[data-player-toggle]');
    const progress = dock.querySelector('[data-player-progress]');
    dock.hidden = !state.current;
    if (title) title.textContent = state.current?.title || 'Nothing playing';
    if (artist) artist.textContent = state.current?.artist_name || '';
    if (toggle) toggle.textContent = audio.paused ? 'Play' : 'Pause';
    if (progress) {
      progress.max = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 1;
      progress.value = audio.currentTime || 0;
    }
  };

  const beginListen = async (recordingId, options = {}) => {
    const playToken = uuidv4();
    const begun = await api({
      action: 'begin_listen',
      recording_id: String(recordingId),
      play_token: playToken,
      source_type: options.sourceType || '',
      source_id: options.sourceId ? String(options.sourceId) : '',
    });
    if (!begun.current?.stream_url) throw new Error('No playable media is available.');
    state.current = begun.current;
    state.playToken = playToken;
    state.lastHeartbeatAt = Date.now();
    return begun.current;
  };

  const playRecording = async (recordingId, options = {}) => {
    if (state.playToken) await heartbeat(false);
    const current = await beginListen(recordingId, options);
    audio.src = current.stream_url;
    audio.volume = state.volume;
    await audio.play();
    await persistState('playing');
    render();
    emit('trackchange', { current: state.current });
    return state.current;
  };

  const resume = async () => {
    if (!state.current) return null;
    if (!state.playToken) {
      const resumeAt = audio.currentTime;
      await beginListen(Number(state.current.id), { sourceType: 'resume' });
      if (!audio.src || !audio.src.endsWith(state.current.stream_url)) audio.src = state.current.stream_url;
      if (Number.isFinite(resumeAt)) {
        const setPosition = () => { audio.currentTime = Math.max(0, resumeAt); };
        if (audio.readyState >= 1) setPosition();
        else audio.addEventListener('loadedmetadata', setPosition, { once: true });
      }
    }
    await audio.play();
    await persistState('playing');
    return state.current;
  };

  const replaceQueue = async (recordingIds, options = {}) => {
    const result = await api({
      action: 'queue',
      recording_ids: JSON.stringify(recordingIds),
      source_type: options.sourceType || '',
      source_id: options.sourceId ? String(options.sourceId) : '',
      expected_queue_revision: String(state.queueRevision),
    });
    state.queue = result.queue || [];
    state.queueRevision = Number(result.session?.queue_revision || state.queueRevision + 1);
    emit('queuechange', { queue: state.queue, revision: state.queueRevision });
    return state.queue;
  };

  const next = async () => {
    if (!state.queue.length) return null;
    const currentId = Number(state.current?.id || 0);
    const index = state.queue.findIndex(item => Number(item.recording_id) === currentId);
    const target = state.queue[index + 1] || state.queue[0];
    return target ? playRecording(Number(target.recording_id), { sourceType: target.source_type, sourceId: target.source_id }) : null;
  };

  const previous = async () => {
    if (audio.currentTime > 4) {
      audio.currentTime = 0;
      await persistState(audio.paused ? 'paused' : 'playing');
      return state.current;
    }
    if (!state.queue.length) return null;
    const currentId = Number(state.current?.id || 0);
    const index = state.queue.findIndex(item => Number(item.recording_id) === currentId);
    const target = state.queue[index > 0 ? index - 1 : state.queue.length - 1];
    return target ? playRecording(Number(target.recording_id), { sourceType: target.source_type, sourceId: target.source_id }) : null;
  };

  audio.addEventListener('play', () => { render(); emit('play', { current: state.current }); });
  audio.addEventListener('pause', () => { render(); persistState('paused'); emit('pause', { current: state.current }); });
  audio.addEventListener('timeupdate', () => { render(); emit('time', { currentTime: audio.currentTime, duration: audio.duration }); });
  audio.addEventListener('ended', async () => {
    await heartbeat(true);
    state.playToken = null;
    emit('ended', { current: state.current });
    try { await next(); } catch (error) { emit('error', { error }); }
  });
  audio.addEventListener('error', () => emit('error', { error: new Error('Audio playback failed.') }));

  setInterval(() => {
    if (!audio.paused && state.playToken) heartbeat(false);
  }, 15000);

  document.addEventListener('click', async event => {
    const play = event.target.closest('[data-play-recording]');
    if (play) {
      event.preventDefault();
      try {
        await playRecording(Number(play.dataset.playRecording), {
          sourceType: play.dataset.sourceType || '',
          sourceId: Number(play.dataset.sourceId || 0) || null
        });
      } catch (error) { emit('error', { error }); }
      return;
    }
    const action = event.target.closest('[data-player-action]')?.dataset.playerAction;
    if (!action) return;
    event.preventDefault();
    try {
      if (action === 'toggle') audio.paused ? await resume() : audio.pause();
      if (action === 'next') await next();
      if (action === 'previous') await previous();
    } catch (error) { emit('error', { error }); }
  });

  document.addEventListener('input', event => {
    const progress = event.target.closest('[data-player-progress]');
    if (progress && Number.isFinite(audio.duration)) {
      audio.currentTime = Number(progress.value || 0);
      emit('seek', { currentTime: audio.currentTime });
    }
  });

  window.DaveTunesPlayer = Object.freeze({
    audio,
    state,
    playRecording,
    replaceQueue,
    next,
    previous,
    play: resume,
    pause: () => audio.pause(),
    seek: seconds => {
      audio.currentTime = Math.max(0, Number(seconds) || 0);
      persistState(audio.paused ? 'paused' : 'playing');
    },
    setVolume: volume => {
      state.volume = Math.max(0, Math.min(1, Number(volume) || 0));
      audio.volume = state.volume;
      persistState(audio.paused ? 'paused' : 'playing');
    },
    on: (name, fn) => {
      const bucket = listeners.get(name) || [];
      bucket.push(fn);
      listeners.set(name, bucket);
      return () => listeners.set(name, bucket.filter(item => item !== fn));
    },
  });

  loadSession()
    .catch(error => emit('error', { error }))
    .finally(() => {
      state.ready = true;
      render();
      emit('ready', { player: window.DaveTunesPlayer });
    });
})();
