(() => {
  'use strict';

  const themeClasses = [
    'theme-neon-green',
    'theme-neon-blue',
    'theme-neon-red',
    'theme-neon-yellow'
  ];
  const modeClasses = ['theme-dark', 'theme-light'];
  const visionClasses = [
    'vision-normal',
    'vision-myopia',
    'vision-presbyopia',
    'vision-protanopia',
    'vision-deuteranopia',
    'vision-tritanopia'
  ];
  const defaults = {
    theme: 'theme-neon-green',
    mode: 'theme-dark',
    vision: 'vision-normal',
    ascii: true
  };

  function storedState() {
    try {
      const raw = localStorage.getItem('ui-theme-state');
      const parsed = raw ? JSON.parse(raw) : {};
      return { ...defaults, ...(parsed && typeof parsed === 'object' ? parsed : {}) };
    } catch (_) {
      return { ...defaults };
    }
  }

  function apply() {
    const body = document.body;
    if (!body) return;

    const state = storedState();
    const allowedTheme = themeClasses.includes(state.theme) ? state.theme : defaults.theme;
    const allowedMode = modeClasses.includes(state.mode) ? state.mode : defaults.mode;
    const allowedVision = visionClasses.includes(state.vision) ? state.vision : defaults.vision;

    body.classList.add('ui-theme');
    [...themeClasses, ...modeClasses, ...visionClasses].forEach((className) => {
      body.classList.remove(className);
    });

    body.classList.add(allowedTheme, allowedMode, allowedVision);
    body.classList.toggle('ascii-on', Boolean(state.ascii));
  }

  window.ArcadeCloudApplyStoredTheme = apply;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply, { once: true });
  } else {
    apply();
  }
})();
