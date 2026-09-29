(() => {
  'use strict';
  const desktop = window.DaveTunesDesktop;
  if (!desktop) return;

  const player = () => window.DaveTunesPlayer;

  desktop.registerCommand({
    id: 'player.play-recording',
    run: async payload => {
      if (!player()) throw new Error('Player runtime is not ready.');
      return player().playRecording(Number(payload.recordingId), {
        sourceType: payload.sourceType || 'desktop',
        sourceId: payload.sourceId || null
      });
    }
  });

  desktop.registerCommand({
    id: 'player.replace-queue',
    run: async payload => {
      if (!player()) throw new Error('Player runtime is not ready.');
      return player().replaceQueue(Array.isArray(payload.recordingIds) ? payload.recordingIds : [], {
        sourceType: payload.sourceType || 'desktop',
        sourceId: payload.sourceId || null
      });
    }
  });

  desktop.registerCommand({
    id: 'player.pause',
    run: async () => {
      if (!player()) throw new Error('Player runtime is not ready.');
      player().pause();
    }
  });

  desktop.registerCommand({
    id: 'player.next',
    run: async () => {
      if (!player()) throw new Error('Player runtime is not ready.');
      return player().next();
    }
  });

  desktop.registerCommand({
    id: 'player.previous',
    run: async () => {
      if (!player()) throw new Error('Player runtime is not ready.');
      return player().previous();
    }
  });

  desktop.registerModule({
    id: 'player-bridge',
    mount: ({ emit }) => {
      const relay = event => emit('player-event', { name: event.type, detail: event.detail });
      const names = ['ready','restore','trackchange','play','pause','time','seek','queuechange','ended','error'];
      names.forEach(name => document.addEventListener('davestunes:player:' + name, relay));
      return () => names.forEach(name => document.removeEventListener('davestunes:player:' + name, relay));
    }
  });
})();
