# Frontera de seguridad del administrador web

Este documento complementa `SUPERADMIN_SERVER_SETTINGS.md` y deja una regla explícita para futuras contribuciones:

- `superadmin` no equivale a shell root;
- la interfaz sólo puede administrar capacidades nombradas y revisables;
- cualquier nueva variable de entorno debe añadirse explícitamente a las definiciones de aplicación y a la allowlist del helper;
- el panel sí administra las variables de aplicación necesarias para FederationCloud, SMTP, MySQL y AWS definidas por ArcadeCloud;
- Base de datos y AWS se escriben como grupos atómicos; DB además se prueba antes de persistirse;
- sudoers, firewall, Nginx, systemd, rutas arbitrarias y comandos del sistema permanecen fuera del panel;
- el helper privilegiado no acepta comandos shell, rutas suministradas por el navegador ni nombres de variables arbitrarios;
- los secretos configurados no deben volver al navegador, logs o auditoría;
- las mutaciones de configuración requieren sesión superadmin, CSRF y reautenticación con contraseña actual;
- crear/renombrar la identidad FederationCloud requiere sesión superadmin + CSRF y sólo puede actuar sobre la ruta de identidad configurada por el operador del servidor.

La instalación del helper requiere una acción root inicial porque un proceso web no puede instalar su propia herramienta privilegiada. Una vez realizada esa preparación, la operación cotidiana declarada por ArcadeCloud puede hacerse desde el panel Servidor.
