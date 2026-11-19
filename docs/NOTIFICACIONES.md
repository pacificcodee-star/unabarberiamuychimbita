# Notificaciones automáticas y fila de turnos

Una Barbería Muy Chimbita · Laravel 12 · Web Push estándar (claves VAPID) + WhatsApp opcional

## 1. Puesta en marcha (una sola vez)

Ejecuta en la carpeta del proyecto:

```
composer require minishlink/web-push
php artisan migrate
php artisan barberia:push-keys
```

El último comando muestra tres líneas (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`). Cópialas en tu `.env`, pon tu correo real en `VAPID_SUBJECT` y ejecuta `php artisan config:clear`.

- Si `barberia:push-keys` falla en Windows, usa `npx web-push generate-vapid-keys` y copia las dos claves a mano.
- Genera las claves una sola vez. Si las cambias, los clientes ya suscritos dejan de recibir avisos.
- La clave privada es secreta: vive solo en `.env`, que ya está en `.gitignore`.

## 2. Qué debe quedar encendido

| Dónde | Comando |
|---|---|
| Tu computador | `php artisan serve` y, en otra terminal, `php artisan schedule:work` |
| Servidor (cron) | `* * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1` |

El programador ejecuta `barberia:tick` cada minuto. Ese comando avanza la fila y envía los avisos y recordatorios. Sin el programador encendido no hay avance automático ni recordatorios.

No hay temporizadores en memoria: todas las horas están en la base de datos. Si el servidor se reinicia, la siguiente pasada continúa donde iba. Los avisos tienen una precisión de hasta 1 minuto.

## 3. Cómo funciona la fila

Cada barbero de "orden de llegada" tiene su propia fila.

1. El cliente toma el turno (sin cuenta). Recibe su número y un enlace personal de seguimiento.
2. Si el barbero está libre, el turno pasa a **Llamado** y el cliente recibe "puedes pasar". Tiene 10 minutos para llegar.
3. A los 10 minutos (o antes, si el barbero lo marca "En atención") empieza a contar la duración del servicio.
4. Al cumplirse la duración, el turno se cierra solo, se registra el ingreso y se llama al siguiente.
5. El que queda de primero en la espera recibe "eres el siguiente".

Ajustes en `.env`: `TURN_GRACE_MINUTES=10`, `TURN_AUTO_QUEUE=true` (ponlo en `false` para volver al manejo manual).

Los botones del panel siguen funcionando: "Llamar siguiente", cambiar estado y anular. Si un cliente no llegó, anula su turno para que no quede como ingreso.

Cancelaciones:
- El cliente puede cancelar desde su enlace mientras el servicio no haya empezado. Los demás se adelantan y sus horas se recalculan.
- Un turno que ya empezó solo lo puede anular la barbería desde el panel.

## 4. Citas

Al agendar: confirmación inmediata y recordatorios 1 hora antes, 15 minutos antes y a la hora. Si la cita se reprograma, los recordatorios viejos se cancelan y se crean los nuevos. Si se cancela, se cancelan todos.

## 5. Compatibilidad

| Dispositivo | Funciona | Notas |
|---|---|---|
| Android (Chrome, Edge, Firefox, Samsung) | Sí | Llega con el navegador cerrado. |
| iPhone / iPad | Sí, desde iOS 16.4 | Solo si la página se agregó a la pantalla de inicio desde Safari y se abre desde ese ícono. |
| Windows (Chrome, Edge, Firefox) | Sí | Chrome y Edge siguen recibiendo en segundo plano; Firefox necesita estar abierto. |
| Mac (Safari 16+, Chrome, Edge, Firefox) | Sí | Chrome y Firefox necesitan estar abiertos. |

No hay entrega garantizada si el dispositivo está apagado, sin internet, con el permiso revocado o con ahorro de batería agresivo. Cada aviso tiene caducidad: un "puedes pasar" que no se pudo entregar en 15 minutos ya no se entrega.

Web Push exige HTTPS. `localhost` y `127.0.0.1` son la única excepción, así que en tu computador funciona; para probar en el celular necesitas la página publicada con HTTPS (o un túnel como `cloudflared` o `ngrok`).

## 6. Pruebas

Automáticas: `php artisan test` (fila automática, cancelaciones, recordatorios, duplicados, suscripciones inválidas).

Manual en el computador:
1. Enciende `php artisan serve` y `php artisan schedule:work`.
2. Abre `http://127.0.0.1:8000/reservar` en Chrome o Edge y toma un turno.
3. Entra a "Ver mi turno y activar avisos" y toca **Activar avisos**. Debe llegar la confirmación.
4. Cierra la pestaña. Toma un segundo turno con el mismo barbero desde otra ventana y espera: al cumplirse los tiempos llega "puedes pasar".
5. Para probar rápido, crea un servicio de 1 minuto y pon `TURN_GRACE_MINUTES=1`.

Qué revisar si algo no llega: la tabla `client_notifications` (columnas `status` y `last_error`) y `storage/logs/laravel.log`.

## 7. WhatsApp (Evolution API, canal activo por defecto)

Los avisos salen por **Evolution API** (número normal del negocio, QR, sin plantillas de Meta).
Guía completa, panel de conexión, chat y bot: [`docs/WHATSAPP.md`](WHATSAPP.md).

Resumen: pon `WHATSAPP_DRIVER=evolution` y `EVOLUTION_URL` / `EVOLUTION_APIKEY` en el `.env`,
conecta el QR desde el menú **WhatsApp** del panel y da de alta el webhook con el botón del
mismo panel.

Solo se envía WhatsApp a quien marcó la casilla de autorización al reservar.

### Alternativa: WhatsApp Business Cloud API de Meta

Si prefieres `WHATSAPP_DRIVER=cloud`:

1. Crea una app en Meta for Developers con el producto WhatsApp y registra el número del negocio.
2. Copia en `.env`: `WHATSAPP_CLOUD_TOKEN` (token permanente de usuario del sistema) y `WHATSAPP_PHONE_NUMBER_ID`.
3. Crea plantillas de categoría "Utilidad" con dos variables, por ejemplo: `Hola {{1}}. {{2}} — Una Barbería Muy Chimbita`. `{{1}}` es el nombre y `{{2}}` el aviso.
4. Cuando Meta las apruebe, escribe sus nombres en las variables `WHATSAPP_TPL_*` del `.env`.

Con Meta solo se entrega un mensaje iniciado por el negocio si el cliente escribió en las últimas
24 horas o si se usa una plantilla aprobada.

## 8. Seguridad

- El cliente entra con un enlace secreto de 40 caracteres por turno o cita. Saber el teléfono o el nombre no permite ver ni cancelar nada.
- La página de seguimiento no se indexa y no envía el enlace a otros sitios.
- Solo se aceptan suscripciones de los servicios push oficiales (Google, Mozilla, Apple, Microsoft).
- Cada aviso tiene una clave única en la base de datos: no se puede enviar dos veces.
- Las suscripciones que el navegador reporta como inválidas se borran solas.
- Todas las rutas públicas tienen límite de peticiones.
