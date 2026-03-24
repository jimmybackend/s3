  const toggleBtn = document.getElementById('toggleMediaBtn');
  const mediaFloating = document.getElementById('mediaFloating');

  if (toggleBtn && mediaFloating) {
    toggleBtn.addEventListener('click', () => {
      if (mediaFloating.style.display === 'none') {
        mediaFloating.style.display = 'block';
        toggleBtn.textContent = 'Ocultar reproductor';
      } else {
        mediaFloating.style.display = 'none';
        toggleBtn.textContent = 'Mostrar reproductor';
      }
    });
  }