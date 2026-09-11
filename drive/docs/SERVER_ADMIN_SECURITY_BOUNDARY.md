# Frontera de seguridad del administrador web

Este documento complementa `SUPERADMIN_SERVER_SETTINGS.md` y deja una regla explícita para futuras contribuciones:

- `superadmin` no equivale a shell root;
- la interfaz sólo puede administrar capacidades nombradas y revisables;
- cualquier nueva variable de entorno debe añadirse explícitamente a las allowlists de aplicación y helper;
- credenciales MySQL, AWS/IAM, sudoers, firewall, Nginx, systemd y rutas arbitrarias quedan fuera del panel web;
- el helper privilegiado no acepta comandos shell, rutas suministradas por el navegador ni nombres de variables arbitrarios;
- los secretos configurados no deben volver al navegador, logs o auditoría;
- las mutaciones de configuración requieren sesión superadmin, CSRF y reautenticación con contraseña actual;
- crear/renombrar la identidad FederationCloud requiere sesión superadmin + CSRF y sólo puede actuar sobre la ruta de identidad configurada por el operador del servidor.

La instalación del helper requiere una acción root inicial porque un proceso web no debe poder otorgarse privilegios por sí mismo. Una vez realizada esa preparación, la operación cotidiana permitida puede hacerse desde ArcadeCloud Drive sin SSH.
