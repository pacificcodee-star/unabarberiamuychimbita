# Mis servicios (autoservicio del cliente sin cuenta)

Página donde el cliente ve sus citas y turnos, los gestiona y recupera el acceso desde otro
dispositivo, sin crear usuario ni iniciar sesión. La identidad es una **credencial anónima** más
el **enlace firmado** de cada reserva y, si se pierde la credencial, la **verificación del celular**.

## Identidad del invitado

| Tabla | Para qué |
|-------|----------|
| `guest_visitors` | Visitante anónimo. `phone` (últimos 10 dígitos), `verified_phone` único, `phone_verified_at`, `recovery_consent_at`, `last_seen_at` |
| `guest_credentials` | Credencial del navegador. `token_hash` (SHA-256, único), `expires_at`, `revoked_at`, `last_used_at` |
| `guest_verification_codes` | Códigos de recuperación. `phone`, `code_hash` (HMAC-SHA256 con `APP_KEY`), `attempts`, `expires_at`, `consumed_at`, `sent_at`, `request_ip` |
| `client_service_accesses` | Legado y permisos puntuales del enlace firmado: `device_hash` + `appointment_id` o `turn_id` |

Las citas y turnos guardan `guest_visitor_id`, `client_phone_normalized` y `service_price`
(precio vigente al reservar).

### Cómo se gana el acceso

1. **Al reservar** (`POST /reservar/cita` o `/reservar/turno`) se crea el visitante si no existe,
   se le asigna la reserva y se entrega la credencial en la cookie `mis_servicios`.
2. **Al abrir el enlace de seguimiento** o el enlace firmado de "Mis servicios", el navegador
   recibe la misma credencial. Si la reserva ya pertenece a otro visitante, se registra un permiso
   puntual en `client_service_accesses` sin cambiarle el dueño a la reserva.
3. **Dispositivos con la cookie anterior** se adoptan una sola vez: se crea el visitante, se
   vinculan las reservas sin dueño y se emite una credencial nueva.

### Ciclo de vida de la credencial

- Token de 32 bytes aleatorios en base64url; en MySQL solo se guarda su SHA-256.
- Cookie `mis_servicios`: `HttpOnly`, `SameSite=Lax`, `Secure` cuando la petición es HTTPS, un año.
- Renovación deslizante: si al año le quedan menos de 180 días, se extiende sin rotar el token
  (rotarlo en cada petición rompería pestañas abiertas en paralelo).
- Revocación con `revoked_at`; una credencial revocada o vencida nunca se reutiliza ni se
  "recupera" por vías débiles: hay que verificar el celular.
- Limpieza diaria: códigos de más de 7 días y credenciales inactivas hace más de 30 días.

## Recuperación desde otro dispositivo

Rutas: `GET/POST /mis-servicios/recuperar` (celular) y `GET/POST /mis-servicios/recuperar/codigo`.

1. El cliente escribe el celular con el que reservó y **autoriza** la verificación y la política
   de privacidad (casilla obligatoria).
2. Si hay reservas con ese número, se genera un código de 6 dígitos, se guarda su HMAC y se envía
   por WhatsApp con `WhatsAppService`. **Si no hay proveedor configurado no se envía nada**: se
   responde con un error operativo, nunca con un éxito simulado.
3. El código vence a los 10 minutos, admite 5 intentos, se consume al usarlo y cada solicitud
   nueva invalida los anteriores. Reenvío mínimo cada 60 s y máximo 5 códigos por hora y número.
   Además, las rutas tienen límite por IP.
4. Un número sin reservas recibe **la misma respuesta** que uno con reservas; no se crea código ni
   se revela si está registrado.
5. Al verificar, el teléfono queda como `verified_phone` del visitante (primera verificación),
   se adjuntan las reservas de ese número que no tengan dueño o pertenezcan a visitantes aún no
   verificados, y se emite una credencial nueva para ese navegador.

**Riesgo asumido:** quien controle el celular puede recuperar la cuenta; es el modelo estándar de
recuperación por SMS/WhatsApp. No hay revocación automática si el número cambia de dueño: el
equipo debe revocar la credencial a pedido. No hay respaldo por SMS si WhatsApp no está disponible.

## Pantalla "Mis servicios"

- Pestañas **Activos** e **Historial**; la pestaña elegida se conserva al refrescar.
- **Activos:** citas `pendiente`/`confirmada` que aún no terminan y turnos de hoy en
  `esperando`/`llamado`, con barbero, servicio, hora, número de turno y estimado de llamada.
- **Historial:** citas atendidas/canceladas y turnos llamados, atendidos o cancelados, con fecha,
  barbero, servicio, estado final y **precio registrado**.
- Acciones permitidas por el servidor (`ClientBookingActions`): confirmar, reprogramar y cancelar
  citas; cancelar turnos vigentes. Los estados finales no admiten cambios.
- **Volver a reservar** abre el formulario actual con `tipo` y `servicio` preseleccionados; el
  precio y los horarios se revalidan al reservar. Solo aparece si el servicio sigue activo.
- Sin servicios activos: "No tienes servicios activos.", botón "Solicitar nuevo servicio" y
  enlace "Recuperar mis servicios".
- Actualización en tiempo real con el sondeo existente cada 5 s (`X-Service-List: 1` devuelve solo
  el parcial). No se añadió broadcasting.

## Estados e historial

Se conservan los estados existentes:

- Citas: `pendiente` → `confirmada` → `atendida`; `cancelada` desde pendiente/confirmada.
- Turnos: `esperando` → `llamado` → `atendiendo` → `atendido`; `cancelado` mientras la cola lo
  permita. La numeración diaria empieza en 1 y el corte de solicitudes es 30 minutos antes del
  cierre configurado (implementación y pruebas en `TurnDailyResetTest`).

Cada alta y cambio de estado crea una fila en `reservation_status_events` (sujeto, estado
anterior, nuevo estado, actor staff/guest/system, motivo y fecha) desde los eventos de
`Appointment` y `Turn`. Los cambios finales no se pueden revertir desde "Mis servicios".

## Rutas y límites

| Ruta | Límite |
|------|--------|
| `GET /mis-servicios` | 90/min |
| `GET /mis-servicios/acceso/{type}/{token}` (firmada) | 20/min |
| `POST /mis-servicios/{type}/{id}/{action}` | 15/min |
| `GET /mis-servicios/recuperar` | 20/min |
| `POST /mis-servicios/recuperar` | 5/min |
| `GET /mis-servicios/recuperar/codigo` | 30/min |
| `POST /mis-servicios/recuperar/codigo` | 10/min |

Cada ruta usa su propio prefijo de `throttle` (no comparte contador con el sondeo de la agenda).
Las respuestas privadas llevan `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`
y `X-Robots-Tag: noindex, nofollow`.

## Pruebas

- `tests/Feature/GuestPersistenceTest.php` (10 pruebas): credencial persistente y guardada como
  hash, flags de cookie, revocación, varias reservas activas e históricas con "Volver a reservar",
  estado vacío, recuperación completa con código hasheado de un solo uso, vencimiento, 5 intentos,
  número desconocido con respuesta idéntica, proveedor sin configurar y consentimiento obligatorio.
- `tests/Feature/MyServicesTest.php`: gestión de la propia reserva, enlace firmado individual,
  turno cancelado con cola/notificaciones una sola vez.
- `tests/Feature/TurnDailyResetTest.php`: numeración diaria, unicidad y corte de 30 minutos.
- `tests/Feature/ProductionHardeningTest.php`: límites aislados por ruta.
- Suite completa: **181 pruebas aprobadas** (PHPUnit muestra avisos de deprecación de
  `PDO::MYSQL_ATTR_SSL_CA`, no fallos).

Comprobación manual en navegador (Brave headless vía CDP, 1440/390/320): pestañas, tarjetas,
historial con precio y "Volver a reservar", estado vacío, formulario de recuperación en dos pasos,
sin desbordamiento horizontal, y actualización automática al cambiar el estado desde el panel
manteniendo la pestaña activa.

## Pendiente / configuración externa

- Ejecutar `php artisan migrate --force` en producción (en local y en las pruebas ya está aplicado).
- Tener `WHATSAPP_DRIVER` y sus credenciales configuradas para enviar los códigos reales; sin eso
  la recuperación responde honestamente que no está disponible.
- Mantener encendido el programador para `barberia:tick` y la limpieza diaria de códigos.
- No existe todavía una pantalla administrativa para revocar una credencial concreta; se hace en
  base de datos (`guest_credentials.revoked_at`) a pedido del cliente.
