# Encuestas de satisfacción y opiniones verificadas

## Cómo funciona

1. **Servicio terminado.** Cuando una cita pasa a *atendida* o un turno a *atendido* (por la
   automatización, por "llamar siguiente" o a mano en el panel) se crea **una** invitación para ese
   servicio. Nunca dos: hay una llave única por cita o turno.
2. **Margen de corrección.** La invitación sale `SURVEY_SEND_DELAY_MINUTES` (30) minutos después.
   Si en ese tiempo el barbero cambia el estado (p. ej. el cliente no llegó), la invitación se cancela.
   Así la hora estimada del servicio no es la única prueba de que se atendió.
3. **Aviso.** Se usa el mismo sistema de avisos de citas (`client_notifications`): notificación push
   si el cliente la activó y WhatsApp **solo si dio su autorización** (`whatsapp_consent`). Cada envío
   queda registrado con sus intentos y errores.
4. **Respuesta.** El cliente responde en la web (`/encuesta/{cita|turno}/{token}`, el mismo enlace
   secreto del seguimiento) o, si está activado, dentro del chat de WhatsApp. Es la misma encuesta y la
   misma base: puede empezar en un canal y terminar en el otro.
5. **Publicación.** Al terminar, si el cliente escribió un comentario **y** autorizó publicarlo, la
   opinión se publica sola cuando pasa la moderación objetiva; si no, queda en revisión. La
   autorización queda registrada en `consent_records` (finalidad `publicacion_resena`).

La invitación vence a los `SURVEY_EXPIRE_DAYS` (14) días. La página de seguimiento del cliente
muestra un botón "Responder la encuesta" mientras esté abierta.

## Datos

| Tabla | Para qué |
|-------|----------|
| `survey_questions` | Catálogo editable de preguntas (texto, tipo, opciones, condición, orden, activa) |
| `survey_invitations` | Una por servicio: barbero, servicio, fecha, estado, envío y vencimiento |
| `survey_sessions` | La respuesta en curso o terminada, con **copia de las preguntas** al empezar |
| `survey_answers` | Una fila por pregunta (llave única: los reintentos actualizan, no duplican) |
| `reviews` | Opiniones públicas; se agregaron origen, barbero, servicio, anónimo y moderación |
| `consent_records` | Ya existía: guarda la autorización para publicar |
| `client_notifications` | Ya existía: historial de entrega de la invitación |

Se distingue "servicio completado" (estado de la cita o turno) de "encuesta completada"
(`survey_invitations.status = completada`).

**Versiones:** cada sesión guarda las preguntas tal como estaban al empezar. Si el administrador
edita, reordena o desactiva preguntas, las encuestas ya empezadas no cambian.

**Preguntas especiales** (no se puede cambiar su tipo): `experiencia` (calificación de la opinión),
`comentario` (texto publicable) y `publicar` (autorización). Las demás son libres.

## Moderación

Automática y **objetiva** (`App\Support\ReviewModerator`): enlaces, correos, teléfonos o documentos,
insultos graves o amenazas, y texto repetido. **No mira la calificación.** Una opinión negativa se
publica igual que una positiva.

En el panel se puede ocultar una opinión solo eligiendo un motivo objetivo de una lista cerrada
(spam, datos personales, insultos o amenazas, no corresponde a la barbería). Queda en la auditoría.
"Retirar a pedido del cliente" revoca la autorización y ya no se puede volver a publicar.

Las respuestas internas (por ejemplo "¿qué podríamos haber hecho mejor?") nunca se publican.

## Panel (`/encuestas`, solo administradores)

- **Resultados:** calificación promedio, encuestas respondidas, tasa de respuesta, % que recomendaría,
  opiniones públicas, encuestas que requieren atención, comparación por barbero y por servicio
  (con "muestra insuficiente" por debajo de `SURVEY_MIN_SAMPLE` = 5), evolución mensual y filtros por
  barbero, servicio, fechas y estado. Sin respuestas no se muestra ningún promedio.
- **Respuestas:** detalle de cada encuesta y la evidencia de la autorización de publicación.
- **Opiniones:** publicar, ocultar con motivo, retirar a pedido del titular.
- **Preguntas:** crear, editar, reordenar, activar o desactivar y condiciones ("mostrar si…").

Cuando una encuesta llega con calificación baja o expectativa no cumplida, el barbero y el
administrador reciben un aviso en la campana del panel.

## WhatsApp: estado real

- El bot actual funciona con **Evolution API** (`WHATSAPP_DRIVER=evolution`), que se conecta a
  **WhatsApp Web**. **No es la plataforma oficial de Meta.** Mandar mensajes automáticos por ahí puede
  hacer que WhatsApp restrinja o bloquee el número.
- Por eso la encuesta **dentro del chat** viene **desactivada** (`SURVEY_WHATSAPP_ENABLED=false`). Con
  eso apagado, la invitación por WhatsApp solo lleva el enlace a la encuesta web, igual que los avisos
  de citas que el bot ya enviaba.
- El código de la conversación (una pregunta por mensaje, *atrás*, *omitir*, *salir*, retomar
  después) está hecho y probado con el webhook de Evolution en pruebas automáticas. **No está probado
  con un número real.**

### Para usar la plataforma oficial (WhatsApp Business Platform / Cloud API)

Falta, y no se puede completar sin tu cuenta de Meta:

1. Cuenta de Meta Business verificada y un número dado de alta en WhatsApp Business Platform.
2. `WHATSAPP_DRIVER=cloud`, `WHATSAPP_CLOUD_TOKEN` (token permanente de usuario de sistema) y
   `WHATSAPP_PHONE_NUMBER_ID`.
3. **Plantilla aprobada** para la invitación. Fuera de la ventana de 24 horas desde el último mensaje
   del cliente, Meta solo permite plantillas. Sugerencia de plantilla de categoría *utility*:
   "Hola {{1}}, gracias por visitarnos hoy. ¿Nos cuentas cómo te fue? {{2}}".
   Configurarla en `services.whatsapp.templates.survey_invite`.
4. **Webhook de Cloud API** con verificación de firma (`X-Hub-Signature-256`): hoy el proyecto solo
   tiene el webhook de Evolution. Hay que agregar el endpoint y validar la firma con el *app secret*.
5. Opcional: un **WhatsApp Flow** para mostrar la encuesta como formulario dentro de WhatsApp.
6. **Costos:** Meta cobra por conversación iniciada con plantilla (las de *utility* tienen tarifa propia
   por país). Las respuestas del cliente dentro de la ventana de 24 h no generan cargo extra. Revisar
   los precios vigentes para Colombia en la documentación de Meta antes de activarlo.

Hasta completar esos pasos, **WhatsApp no está integrado de forma oficial**.

## Configuración (`config/encuestas.php`)

| Variable | Por defecto | Qué hace |
|----------|-------------|----------|
| `SURVEYS_ENABLED` | true | Activa el sistema |
| `SURVEY_SEND_DELAY_MINUTES` | 30 | Margen antes de invitar |
| `SURVEY_EXPIRE_DAYS` | 14 | Días para responder |
| `SURVEY_WHATSAPP_ENABLED` | false | Encuesta dentro del chat de WhatsApp |
| `SURVEY_AUTO_PUBLISH` | true | Publicar sola si pasa la moderación (false = todo pasa por el panel) |
| `SURVEY_MIN_SAMPLE` | 5 | Mínimo para comparar barberos o servicios |
| `SURVEY_RETENTION_INCOMPLETE_DAYS` | 180 | Encuestas sin terminar se borran pasado este plazo |

## Conservación

- Encuestas sin terminar: se borran a los 180 días (tarea programada).
- Encuestas terminadas y opiniones: mientras sirvan al negocio. **[PENDIENTE]** fijar plazo.
- Retirar una opinión revoca la autorización y la saca del sitio; la constancia queda en `consent_records`.
