import { Drive3DScene } from './drive3d-scene.js';
const status = document.querySelector('#status');
try {
    const THREE = await import('../three-lab/vendor/three.module.min.js');
    const { Reflector } = await import('../three-lab/vendor/Reflector.js');
    new Drive3DScene(THREE, Reflector);
} catch (error) {
    status.hidden = false;
    status.textContent = 'No fue posible iniciar WebGL 2. Activa la aceleración gráfica o vuelve a la vista clásica.';
    console.error('Drive 3D:', error);
}
