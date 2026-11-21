# Producción: seguridad, datos personales y operación

Guía para dejar **Una Barbería Muy Chimbita** lista para clientes reales. Complementa a
`deploy/README.md` (cómo desplegar en VPS o Render). Todo lo marcado **[PENDIENTE]** necesita una
decisión del negocio, una credencial o un servicio externo que no está en el código.

---

## 1. Lista de verificación antes de abrir al público

| # | Qué | Dónde | Estado en el código |
|---|-----|-------|---------------------|
| 1 | `APP_ENV=production`, `APP_DEBUG=false` | `.env` del servidor | Por defecto debug apagado |
| 2 | `APP_KEY` propio (nunca el de desarrollo) | `php artisan key:generate --show` | — |
| 3 | HTTPS con certificado válido | Caddy (VPS) o Render (automático) | Redirección http→https y HSTS en `ForceHttps` |
| 4 | Cambiar o borrar `admin@barberia.com` si existe | Panel → Barberos / tinker | El seeder ya no corre en producción |
| 5 | `php artisan migrate --force` (incluye `consent_records`) | Servidor | Migración solo aditiva |
| 6 | Correo saliente (SMTP) para recuperar contraseña | `MAIL_*` en `.env` | **[PENDIENTE]** credenciales |
| 7 | Monitor externo a `https://DOMINIO/salud` | UptimeRobot / Better Stack | Endpoint listo |
| 8 | Alertas críticas (opcional) | `LOG_STACK=daily,slack`, `LOG_SLACK_WEBHOOK_URL` | Canal listo, solo `critical` |
| 9 | Datos legales del titular | `LEGAL_OWNER`, `LEGAL_NIT`, `LEGAL_EMAIL` | **[PENDIENTE]** datos |
| 10 | Revisión jurídica de los textos legales | `/aviso-legal`, `/politica-de-privacidad`, `/cookies` | **[PENDIENTE]** abogado |
| 11 | Cachés de Laravel | `php artisan config:cache route:cache view:cache` | — |
| 12 | Scheduler corriendo (`schedule:work` o cron) | supervisord | `/salud` avisa si se detiene |

---

## 2. Base de datos

**Motor real:** local usa MySQL; el despliegue en Render usa **PostgreSQL (Neon)**; el VPS con
docker-compose usa MySQL. El código funciona en los tres (pruebas en SQLite).

**Usuario con privilegios mínimos (MySQL):** la aplicación no necesita ser `root`.

```sql
CREATE USER 'barberia_app'@'%' IDENTIFIED BY '<contraseña larga y aleatoria>';
GRANT SELECT, INSERT, UPDATE, DELETE ON barberia.* TO 'barberia_app'@'%';
-- Solo para migrar (otro usuario, usado a mano durante el despliegue):
CREATE USER 'barberia_migra'@'%' IDENTIFIED BY '<otra contraseña>';
GRANT ALL PRIVILEGES ON barberia.* TO 'barberia_migra'@'%';
```

En PostgreSQL/Neon: crear un rol con `CONNECT` y `SELECT, INSERT, UPDATE, DELETE` sobre las tablas
del esquema, y usar el rol propietario solo para migrar.

**Acceso remoto:** la base nunca debe escuchar en internet. En docker-compose, MySQL no publica
puertos; en Neon, restringir por IP permitida si el plan lo ofrece.

**Integridad y concurrencia (comprobado):**
- Citas: `Appointment::reserveSlot` (transacción + `SELECT … FOR UPDATE` del barbero). Prueba real
  con MySQL: 10 procesos simultáneos al mismo horario → 1 cita, 9 rechazos (`tests/concurrency/race.php`).
- Turnos: número calculado dentro de la transacción + llave única `(barber_id, turn_date, turn_number)`.
- Estados: reglas de transición en `Appointment::TRANSITIONS` y `Turn::TRANSITIONS`.

**Copias de seguridad:**
- VPS: `mysqldump` diario (ver `deploy/README.md` §7). Guardar las copias **fuera** del servidor
  (otro proveedor) y cifradas (`gpg --symmetric`), porque contienen datos personales.
- Neon: tiene historial de restauración según el plan; revisar cuántos días cubre.
- **Probar la restauración** al menos una vez al trimestre en una base aparte:
  `mysql -u root barberia_restaurada < respaldo.sql` y abrir el panel apuntando a ella.

---

## 3. Secretos

- `.env` nunca entra a git (`.gitignore`) y queda fuera de `public/`.
- Escaneo sin imprimir valores: `node scripts/check-secrets.mjs` (archivos) y
  `node scripts/check-secrets.mjs --history` (todo el historial). Resultado al 2026-11-21: limpio;
  las coincidencias en `deploy/*.example` y `docs/WHATSAPP.md` son ejemplos, no claves reales.
- En CI (`.github/workflows/calidad.yml`) corre además **Gitleaks** sobre todo el historial.
- Claves públicas por diseño (pueden ir al navegador): `VAPID_PUBLIC_KEY`, `GOOGLE_MAPS_API_KEY`
  (**restringirla por dominio** en Google Cloud), `ANALYTICS_GA4_ID`.
- Secretos de servidor: `APP_KEY`, `DB_*`, `VAPID_PRIVATE_KEY`, `EVOLUTION_APIKEY`,
  `EVOLUTION_WEBHOOK_SECRET`, `WHATSAPP_CLOUD_TOKEN`, `MAIL_PASSWORD`, `LOG_SLACK_WEBHOOK_URL`.
- **Si una clave se filtra:** borrarla de git no basta. Rotarla en el proveedor (generar otra,
  actualizar `.env`, reiniciar) y revisar los registros del proveedor.
- Un gestor de secretos (Doppler, Vault…) no se justifica a esta escala: las variables de entorno de
  Render o el `.env` del VPS con permisos `600` son suficientes.

---

## 4. Protección contra abuso y costos

**Límites por IP ya activos (Laravel `throttle`):**

| Operación | Límite |
|-----------|--------|
| Inicio de sesión | 10/min por IP + bloqueo tras 5 fallos por correo+IP |
| Recuperar contraseña | 3 cada 10 min |
| Crear cita / turno | 10/min |
| Consultar horarios | 60/min |
| Opiniones | 5 cada 10 min |
| Seguimiento / cancelar | 60/min y 10/min |
| Suscripción push | 20/min |
| Errores del navegador | 20/min |

Tope de tamaño: Caddy corta peticiones de más de 120 MB; PHP admite archivos de hasta 110 MB
(`deploy/php/barberia.ini`).

**Esto no es protección DDoS.** Un ataque de red satura el servidor antes de llegar a Laravel.
Opciones: poner el dominio detrás de **Cloudflare** (plan gratuito: DDoS de red y reglas WAF básicas)
o usar la protección del proveedor. Render filtra tráfico en su borde, pero no documenta garantías
para el plan gratuito. **[PENDIENTE]** decidir si se usa Cloudflare.

**Costos — qué revisar en cada proveedor** (los valores cambian; confirmar en su página de precios):
- **Render:** el plan gratuito se duerme y tiene horas mensuales limitadas; los pagos son fijos por
  instancia. No cobra por tráfico en exceso en el plan gratuito, sino que corta el servicio.
- **Neon (PostgreSQL):** límites de almacenamiento y horas de cómputo en el plan gratuito.
- **Google Maps:** la clave de JavaScript puede generar cargos. Poner **cuota diaria** en Google
  Cloud (límite real) además de la alerta de presupuesto (que solo avisa, no frena).
- **WhatsApp:** Evolution API (WhatsApp Web) no cobra por mensaje, pero puede bloquear el número si
  envía masivamente. La API oficial de Meta cobra por conversación.
- **Web Push:** gratis (lo entregan Google, Apple y Mozilla).
- **Google Analytics 4:** gratis.

Una alerta de presupuesto **no** impide un cargo: usar cuotas o límites duros donde existan.

---

## 5. Cabeceras y navegador

- Aplicadas: HSTS (en HTTPS), `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`,
  `Permissions-Policy` (sin cámara, micrófono, ubicación ni pagos) y una CSP mínima
  (`base-uri`, `object-src`, `frame-ancestors`, `form-action`).
- CSP completa en modo **observación** (`Content-Security-Policy-Report-Only`): no bloquea; muestra
  en la consola lo que bloquearía. **Para aplicarla** hay que mover los `<script>` en línea de las
  vistas a archivos o darles un `nonce`, y luego quitar `'unsafe-inline'` de `script-src`.
- CORS: Laravel no abre CORS a otros sitios (no hay API pública). Si algún día se publica una API,
  configurar `config/cors.php` con los orígenes exactos.
- Cookies de sesión: `HttpOnly`, `SameSite=Lax` y `Secure` en producción.

---

## 6. Archivos y fotos

- Solo JPG, PNG, WebP, AVIF y GIF (`App\Rules\ImageUpload`); SVG rechazado (puede llevar JavaScript).
- Se borra la ubicación GPS de las fotos JPEG al subirlas (`App\Support\ImageSanitizer`), conservando
  la orientación. El servidor no tiene GD ni Imagick, así que **no se redimensionan** al subir:
  subir fotos ya optimizadas o instalar `gd` en la imagen Docker para hacerlo automático. **[PENDIENTE]**
- Nombres aleatorios (`store()`), guardados en `storage/app/public`, solo los sube el administrador.
- Caddy nunca ejecuta PHP dentro de `/storage`, `/images`, `/videos`, `/css`, `/js`, `/vendor`.
- **Derechos de imagen:** las fotos donde se reconozca a un cliente necesitan su autorización para
  publicarse. **[PENDIENTE]** definir cómo se pide (formato firmado o mensaje de WhatsApp guardado).

---

## 7. Datos personales: inventario, conservación y acceso

| Dato | Dónde | Quién accede | Conservación |
|------|-------|--------------|--------------|
| Nombre, teléfono, correo, notas de reservas | tablas `appointments`, `turns` | Administrador | Mientras sirva al servicio y la contabilidad **[PENDIENTE]** fijar plazo |
| Opiniones | `reviews` | Público (aprobadas) y administrador | Hasta que el titular pida retirarla |
| Suscripciones push | `push_subscriptions` | Sistema | Se borran al desactivarlas o al fallar (410) |
| Conversaciones de WhatsApp | `whatsapp_*` | Administrador | **[PENDIENTE]** fijar plazo |
| Autorizaciones (Ley 1581) | `consent_records` | Administrador | Mientras exista el dato tratado + plazo de prescripción |
| Registro técnico | `storage/logs/laravel-*.log` | Administrador del servidor | `LOG_DAILY_DAYS` (14) |
| Auditoría del panel | `storage/logs/auditoria-*.log` | Administrador del servidor | 90 días |
| Errores del navegador | `storage/logs/navegador-*.log` | Administrador del servidor | 14 días |

Los registros no guardan contraseñas, tokens completos ni textos de mensajes; los teléfonos van
enmascarados (`***4567`).

**Derechos de los titulares:** se atienden por correo o WhatsApp (ver la política de privacidad):
consultas en máximo 10 días hábiles y reclamos en 15. La baja de los avisos de WhatsApp es automática
(el cliente escribe `BAJA`). Borrar los datos de un cliente hoy es manual desde el panel.
**[PENDIENTE]** herramienta en el panel para exportar o borrar los datos de un teléfono.

---

## 8. Puntos legales pendientes de decisión del negocio

1. **Pagos anticipados:** el sistema envía la cuenta por WhatsApp para consignar. Falta definir
   plazos y forma de devolución. El texto publicado remite a la Ley 1480 sin fijar porcentajes.
2. **Retracto (art. 47, Ley 1480):** no procede si la cita es dentro de los cinco días siguientes
   o si el servicio ya empezó. Validar con abogado el caso de citas lejanas pagadas por adelantado.
3. **Registro Nacional de Bases de Datos (SIC):** verificar si el negocio está obligado a
   inscribirse (depende de los activos totales de la empresa).
4. Datos del titular (razón social, NIT, correo para datos personales).

Los textos legales del sitio deben **revisarse por un profesional** antes de considerarse definitivos.

---

## 9. Despliegue, actualización y reversión

- Rama de trabajo, pruebas en CI y luego integración a `main`.
- Actualizar en el VPS: `git pull && docker compose up -d --build`; en Render: push a la rama
  conectada.
- **Antes de migrar en producción:** respaldo de la base. Las migraciones nuevas deben ser aditivas
  (agregar tablas o columnas), nunca borrar datos.
- **Reversión:** volver al commit anterior (`git revert` o redeploy del deploy anterior en Render).
  Si una migración aditiva molesta, se puede dejar: el código anterior la ignora.
- Separación de entornos: desarrollo (`.env` local), pruebas (`barberia_concurrencia` y la vista
  previa `barberia-pruebas`) y producción (variables del proveedor). Nunca apuntar pruebas a la base real.

---

## 10. Notificaciones: lo que la plataforma permite y lo que no

- **Web Push** llega con el navegador cerrado en Android (Chrome, Edge, Firefox) y en escritorio
  si el navegador sigue ejecutándose en segundo plano. **No hay garantía de entrega.**
- **iPhone/iPad:** solo si la página se agregó a la pantalla de inicio (iOS 16.4 o superior) y se
  dieron permisos desde la app instalada.
- **Sonido propio:** el sonido lo decide el sistema operativo; la web no puede imponer uno. Con la
  página abierta sí puede sonar un aviso dentro de la página. Para un aviso con sonido garantizado
  haría falta una app nativa o el canal de WhatsApp (ya integrado).
- Prevención de duplicados (`dedupe_key` único), máximo 3 intentos, vencimiento por aviso y borrado
  de suscripciones vencidas (HTTP 404/410).

---

## 11. Escalabilidad

A la escala actual (un local, decenas de reservas al día) basta un solo servidor. Con cientos de
usuarios simultáneos, los primeros cuellos de botella serían:
1. **Sesiones y caché en base de datos** → pasar `SESSION_DRIVER` y `CACHE_STORE` a Redis.
2. **Un solo proceso de PHP en planes pequeños** → subir `pm.max_children` de php-fpm según la RAM.
3. **Conexiones a la base** → Neon tiene pooler; en MySQL, vigilar `max_connections`.
4. **Fotos** → servirlas desde un CDN (Cloudflare) en vez del servidor.

No se justifican microservicios ni Kubernetes. Las pruebas de carga solo deben hacerse sobre un
entorno propio de pruebas, nunca sobre producción.

---

## 12. Autenticación: estado y siguientes pasos

- Solo los administradores activos inician sesión en el panel (los barberos no tienen acceso).
- Contraseñas con hash bcrypt (Laravel); recuperación con enlace de un solo uso que vence en
  60 minutos y cierra las demás sesiones; mínimo 10 caracteres con letras y números.
- **[PENDIENTE] Doble factor (2FA)** para el administrador: recomendable antes de tener más de un
  administrador. Opción liviana: TOTP con `pragmarx/google2fa` (nueva dependencia, requiere decisión).
