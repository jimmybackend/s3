/* Client-only device and location card for the currently viewed node.
 * Coordinates never leave the browser; no automatic geolocation requests.
 */
(() => {
  'use strict';
  const card = document.querySelector('#nodeWindow [data-device-location]');
  if (!card) return;
  const platform = card.querySelector('[data-device-platform]');
  const status = card.querySelector('[data-device-location-status]');
  const button = card.querySelector('[data-device-location-request]');
  const map = card.querySelector('[data-device-location-map]');
  const ua = navigator.userAgent || '';
  const android = /Android/i.test(ua);
  const nativeApp = android && /ArcadeCloudAndroidApp\/1\b/.test(ua);
  const mobile = android || /iPhone|iPad|iPod/i.test(ua);
  platform.textContent = nativeApp ? 'Aplicación ArcadeCloud Android · Fondo personalizado independiente en este teléfono'
    : android ? 'Navegador Android · Fondo personalizado independiente en este teléfono'
    : (mobile ? 'Dispositivo móvil' : 'Navegador de escritorio');
  button?.addEventListener('click', () => {
    if (!navigator.geolocation || !window.isSecureContext) {
      status.textContent = 'Este dispositivo o conexión no permite consultar la ubicación.';
      return;
    }
    button.disabled = true;
    status.textContent = 'Solicitando autorización al dispositivo…';
    navigator.geolocation.getCurrentPosition(({ coords }) => {
      const latitude = Number(coords.latitude);
      const longitude = Number(coords.longitude);
      const accuracy = Math.round(Number(coords.accuracy) || 0);
      status.textContent = 'Tu ubicación: ' + latitude.toFixed(5) + ', ' + longitude.toFixed(5)
        + ' · precisión aproximada: ' + accuracy + ' m';
      const destination = 'https://www.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude);
      map.href = destination;
      map.hidden = false;
      button.disabled = false;
    }, (error) => {
      const message = error.code === 1 ? 'Permiso de ubicación denegado.'
        : error.code === 2 ? 'No se pudo obtener una ubicación fiable.'
        : 'La consulta de ubicación agotó el tiempo.';
      status.textContent = message;
      map.hidden = true;
      button.disabled = false;
    }, { enableHighAccuracy: false, maximumAge: 60000, timeout: 12000 });
  });
})();
