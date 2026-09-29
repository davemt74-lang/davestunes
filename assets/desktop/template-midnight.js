(() => {
  'use strict';
  const desktop = window.DaveTunesDesktop;
  if (!desktop) return;

  desktop.registerTemplate({
    id: 'midnight-desk',
    label: 'Midnight Desk',
    version: 1,
    tokens: {
      '--dt-desk-base': '#101214',
      '--dt-desk-highlight': '#282b2f',
      '--dt-desk-edge': '#050607',
      '--dt-panel': 'rgba(15,17,20,.82)',
      '--dt-panel-line': 'rgba(255,255,255,.10)',
      '--dt-panel-text': '#f4f1e8',
      '--dt-panel-muted': '#999d9f',
      '--dt-object-shadow': '0 28px 60px rgba(0,0,0,.38)',
      '--dt-system-glow': '0 18px 70px rgba(0,0,0,.58)'
    }
  });
})();
