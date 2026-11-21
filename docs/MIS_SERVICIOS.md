# Mis servicios (autoservicio del cliente)

Página donde el cliente ve, sin cuenta y sin iniciar sesión, sus citas y turnos, y puede
confirmar, reprogramar o cancelarlos desde su propio teléfono o computador.

## Cómo funciona

1. **Se gana el acceso al reservar.** Cuando la reserva se crea (`POST /reservar/cita` o
   `/reservar/turno`) se guarda un permiso en `client_service_accesses`: el dispositivo queda
   identificado con una cookie `mis_servicios` y ese dispositivo puede ver esa cita o turno.
2. **También al abrir el seguimiento.** Entrar al enlace de seguimiento (`/seguimiento/…`) otorga
   el mismo permiso, así el cliente que ya tenía el enlace no pierde nada.
3. **Enlace de recuperación.** El panel de éxito de la reserva muestra "Mis servicios" con una URL
   firmada y temporal (`/mis-servicios/acceso/{cita|turno}/{token}`) que vence el mismo día en que
   vence el enlace de seguimiento (30 días, `notificaciones.track_link_days`). Sirve para volver
   desde otro dispositivo o si se borraron las cookies.
4. **Navegación.** El enlace "Mis servicios" aparece en el menú superior y en la navegación
   inferior móvil **solo si el dispositivo ya tiene servicios** (`ClientServiceAccess::hasServices`).
5. **Acciones.** Cada fila ofrece los botones que su estado permite (`ClientBookingActions::actions`)
   y el click manda un `POST /mis-servicios/{cita|turno}/{id}/{confirmar|reprogramar|cancelar}`.
   La lista se refresca con `fetch` a la misma URL usando la cabecera `X-Service-List: 1`, que
   devuelve sólo el parcial (`my-services-list`) en vez de la página completa.

La página se sirve con `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer` y
`X-Robots-Tag: noindex, nofollow`: es contenido privado por dispositivo y no debe indexarse.

## Datos

| Tabla | Para qué |
|-------|----------|
| `client_service_accesses` | Permiso dispositivo ↔ cita o turno. `device_hash` (sha256 de la cookie) + `appointment_id` **o** `turn_id`, con llave única por pareja |

No hay usuarios nuevos, ni tokens de sesión propios, ni tablas de "cuenta del cliente": el acceso
es el dispositivo más el enlace firmado.

## Qué puede hacer cada uno

| Sujeto | Acciones disponibles |
|--------|----------------------|
| Cita `pendiente` o `confirmada` y aún no empieza | `confirmar`, `reprogramar`, `cancelar` (`confirmar` solo si falta confirmar la asistencia) |
| Cita en pasado, cancelada o atendida | ninguna (queda en el historial) |
| Turno de hoy en `esperando` o `llamado` | `cancelar` |
| Turno llamado/atendido/cancelado | ninguno |

**Reprogramar** valida el nuevo horario contra `Appointment::availableTimes()` y reserva el cupo con
`Appointment::reserveSlot()` (misma ruta que una reserva nueva, así que no se puede pisar a otro
cliente). **Cancelar** un turno avanza la cola del barbero (`TurnQueueService::advance`).

Toda acción queda registrada en la campana del equipo (`StaffNotification`) y notifica al barbero
por los mismos canales que cualquier cambio de agenda (`ClientNotifier`), incluido WhatsApp si el
cliente dio su consentimiento.

## Concurrencia

Las acciones corren dentro de una transacción con `lockForUpdate()` sobre el barbero y la cita,
**en el mismo orden que las altas de agenda**, con 3 reintentos: dos reprogramaciones simultáneas no
pueden quedarse con el mismo cupo. Si la cita cambió mientras el cliente tenía el diálogo abierto,
se responde "La cita cambió o ya no admite esta acción." en vez de aplicar un cambio obsoleto.

## Límites de peticiones

Cada ruta lleva su nombre como prefijo del `throttle` (`throttle:15,1,my-services.update`):

| Ruta | Límite |
|------|--------|
| `GET /mis-servicios` | 90/min |
| `GET /mis-servicios/acceso/…` (firmada) | 20/min |
| `POST /mis-servicios/{type}/{id}/{action}` | 15/min |

El prefijo no es decorativo: el `throttle` **inline** de Laravel identifica el límite sólo por IP,
sin la ruta, de modo que todas las rutas compartían un mismo contador. Con el sondeo de agenda de
la página de reserva (cada 5 s) el cupo se agotaba y el propio cliente recibía **429 al enviar su
reserva** (límite 10/min). Todas las rutas del archivo `routes/web.php` llevan ahora su prefijo, y
`ProductionHardeningTest::test_rate_limits_are_isolated_from_one_route_to_another` lo cubre.

## Detalle del flujo de reserva

La reserva se confirma en la misma respuesta que la creó: `booking_success` se guarda en la sesión
con `PublicBookingController::rememberSuccess()` y se lee con `session()->pull()` al pintar la
pantalla. Antes se usaba el flash de Laravel, que el sondeo de la agenda (5 s) consumía antes de que
el navegador llegara a la página de éxito, y el enlace a "Mis servicios" nunca aparecía.

## Pruebas

- `tests/Feature/MyServicesTest.php`: el cliente gestiona su propia cita y el equipo ve cada cambio;
  sólo un enlace individual y válido recupera una reserva; cancelar un turno llamado actualiza
  cola, historial, estimados y notificaciones una sola vez.
- `tests/Feature/PublicBookingTest.php`: la pantalla de éxito sobrevive al sondeo de la agenda.
- `tests/Feature/ProductionHardeningTest.php`: los límites de peticiones están aislados por ruta.
- E2E en navegador (Playwright) contra el flujo completo: reservar → enlace → lista → reprogramar →
  cancelar → navegación móvil. Los scripts viven en `.qa/e2e` (ignorado por git).

## Pendiente

`php artisan migrate` en el entorno de desarrollo: la tabla `client_service_accesses` aún no está
migrada ahí (en CI y en el E2E sí se creó).
