function editarTxt(key) {
  const extensionesEditables = [
    'txt', 'text', 'log', 'jas', 'md',
    'md', 'markdown',
    'html', 'htm', 'css', 'scss', 'less',
    'js', 'mjs', 'cjs', 'jas', 'jsx',
    'ts', 'tsx',
    'php', 'phtml', 'inc',
    'py', 'rb', 'java', 'c', 'h', 'cpp', 'hpp', 'cs', 'go', 'rs', 'swift', 'kt', 'kts',
    'json', 'jsonl',
    'xml', 'yaml', 'yml', 'toml', 'ini', 'conf', 'cfg',
    'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1',
    'sql', 'csv', 'tsv',
    'srt', 'vtt', 'inf',
    'vue'
  ];

  const nombre = String(key || '').split('/').pop().toLowerCase();
  const ext = nombre.includes('.') ? nombre.split('.').pop() : '';

  const archivosEspeciales = [
    'dockerfile',
    'makefile',
    '.env',
    '.gitignore',
    '.htaccess'
  ];

  // PDFs siguen usando visor
  if (ext === 'pdf') {
    verPDF(key);
    return;
  }

  // Validación
  if (!extensionesEditables.includes(ext) && !archivosEspeciales.includes(nombre)) {
    alert('Este tipo de archivo no es editable desde el navegador.');
    return;
  }

  // Abrir editor Monaco en nueva pestaña
  const url = 'editor.php?archivo=' + encodeURIComponent(key);
  window.open(url, '_blank', 'noopener');
}