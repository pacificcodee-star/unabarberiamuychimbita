@extends('layouts.public')

@section('title', 'Política de privacidad · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', 'Cómo '.($siteSettings['brand_name'] ?? 'la barbería').' recoge, usa y protege tus datos personales, según la Ley 1581 de 2012.')

@section('content')

    <article class="legal-doc" aria-labelledby="legal-title">
        <div class="container">
            <nav aria-label="Ruta de navegación">
                <ol class="breadcrumb-public">
                    <li><a href="{{ route('landing') }}">Inicio</a></li>
                    <li><span aria-current="page">Privacidad</span></li>
                </ol>
            </nav>

            <header class="legal-head">
                <div class="section-kicker">Información legal</div>
                <h1 id="legal-title">Política de privacidad y tratamiento de datos</h1>
                <p class="legal-updated">Última actualización: {{ $legalUpdated }}</p>
            </header>

            <div class="legal-body">
                <p class="legal-lead">Cumplimos la Ley 1581 de 2012 y el Decreto 1377 de 2013 (compilado en el Decreto 1074 de 2015). Aquí te contamos qué datos recogemos, para qué los usamos y cómo puedes controlarlos.</p>

                <h2>1. Responsable del tratamiento</h2>
                <ul>
                    <li><strong>Responsable:</strong> {{ $legalOwner }}</li>
                    @if($legalNit)<li><strong>NIT / C.C.:</strong> {{ $legalNit }}</li>@endif
                    @if($legalAddress)<li><strong>Dirección:</strong> {{ $legalAddress }}</li>@endif
                    @if($legalPhone)<li><strong>Teléfono:</strong> {{ $legalPhone }}</li>@endif
                    @if($legalEmail)<li><strong>Correo para temas de datos personales:</strong> <a href="mailto:{{ $legalEmail }}">{{ $legalEmail }}</a></li>@endif
                </ul>

                <h2>2. Qué datos recogemos</h2>
                <ul>
                    <li><strong>Al reservar una cita o pedir un turno:</strong> nombre, número de celular, correo (opcional), barbero y servicio elegidos, fecha y hora, y las notas que escribas.</li>
                    <li><strong>Si aceptas los avisos por WhatsApp:</strong> tu número y los mensajes que intercambiamos sobre tu reserva.</li>
                    <li><strong>Al consultar «Mis servicios» sin cuenta:</strong> una credencial anónima que guardamos en una cookie de tu navegador. En nuestros servidores solo conservamos un hash de esa credencial, nunca el valor completo.</li>
                    <li><strong>Si vinculas tu historial de WhatsApp:</strong> usamos un identificador temporal para comprobar el número desde el que envías el mensaje. Asociamos ese número a la credencial de este navegador para que puedas consultar tus servicios vinculados.</li>
                    <li><strong>Si activas las notificaciones del navegador:</strong> un identificador técnico de suscripción que entrega tu navegador. No incluye tu nombre.</li>
                    <li><strong>Al dejar una opinión:</strong> el nombre que escribas, la calificación y el comentario.</li>
                    <li><strong>Al navegar:</strong> datos técnicos (dirección IP, tipo de navegador y páginas visitadas). Las estadísticas de visitas solo se recogen si aceptas las cookies de analítica.</li>
                </ul>
                <p>No pedimos datos sensibles ni datos de menores de edad. Si eres menor de edad, pídele a tu acudiente que haga la reserva.</p>

                <h2>3. Para qué los usamos</h2>
                <ul>
                    <li>Agendar, confirmar, recordar, reprogramar o cancelar tu cita o turno.</li>
                    <li>Avisarte cuando se acerca tu turno, por WhatsApp o con notificaciones, si lo aceptaste.</li>
                    <li>Responder tus mensajes y solicitudes.</li>
                    <li>Mostrar tu opinión en el sitio después de revisarla.</li>
                    <li>Llevar el historial de servicios y la contabilidad interna de la barbería.</li>
                    <li>Medir de forma agregada cómo se usa el sitio para mejorarlo, solo si aceptaste la analítica.</li>
                </ul>
                <p>No vendemos ni alquilamos tus datos y no los usamos para publicidad de terceros.</p>

                <h2>4. Autorización</h2>
                <p>Cuando envías un formulario de reserva u opinión, después de leer el aviso que está junto al botón, nos das tu autorización para tratar tus datos con esa finalidad (es una conducta inequívoca, según el Decreto 1074 de 2015). Los avisos por WhatsApp se autorizan aparte, con su propia casilla, que nunca viene marcada.</p>
                <p>Guardamos constancia de cada autorización: la finalidad, la versión de esta política, la fecha y hora y el medio (formulario web o WhatsApp). La constancia se asocia a tu reserva u opinión; no guardamos tu dirección IP para esto.</p>
                <p>Puedes revocarla cuando quieras (punto 7). Para dejar de recibir avisos por WhatsApp basta con escribir <strong>BAJA</strong> en el chat.</p>

                <h2>5. Con quién los compartimos</h2>
                <p>Solo con proveedores que necesitamos para prestar el servicio. Ellos tratan los datos por encargo nuestro:</p>
                <ul>
                    <li>Alojamiento del sitio y de la base de datos (Render).</li>
                    <li>Mensajería por WhatsApp (Meta / WhatsApp), solo si aceptaste esos avisos.</li>
                    <li>Servicios de notificaciones del navegador (Google, Apple o Mozilla, según tu navegador).</li>
                    <li>Google Maps, para mostrar el mapa en la página de contacto.</li>
                    <li>Google Analytics, solo si aceptaste las cookies de analítica.</li>
                </ul>
                <p>Algunos de estos proveedores guardan información fuera de Colombia. Exigimos que lo hagan con niveles adecuados de protección. También entregaremos datos si una autoridad competente lo ordena.</p>

                <h2>6. Cuánto tiempo los guardamos</h2>
                <p>Guardamos los datos de las reservas mientras sean útiles para el servicio y para cumplir obligaciones contables y legales. Las suscripciones a notificaciones se borran cuando las desactivas o cuando dejan de funcionar. Puedes pedir que borremos tus datos antes, salvo los que la ley nos obligue a conservar.</p>

                <h2>7. Tus derechos</h2>
                <p>Como titular de los datos tienes derecho a:</p>
                <ul>
                    <li>Conocer, actualizar y corregir tus datos.</li>
                    <li>Pedir prueba de la autorización que nos diste.</li>
                    <li>Saber cómo hemos usado tus datos.</li>
                    <li>Revocar la autorización o pedir que borremos tus datos.</li>
                    <li>Consultar tus datos gratis.</li>
                    <li>Presentar quejas ante la Superintendencia de Industria y Comercio (SIC).</li>
                </ul>

                <h2>8. Cómo ejercerlos</h2>
                <p>Escríbenos @if($legalEmail)a <a href="mailto:{{ $legalEmail }}">{{ $legalEmail }}</a>@endif @if($legalPhone){{ $legalEmail ? 'o' : '' }} por WhatsApp al {{ $legalPhone }}@endif. Incluye tu nombre, tu celular y lo que necesitas.</p>
                <ul>
                    <li><strong>Consultas:</strong> respondemos en un máximo de 10 días hábiles.</li>
                    <li><strong>Reclamos</strong> (corregir, actualizar, borrar o revocar): respondemos en un máximo de 15 días hábiles.</li>
                </ul>
                <p>Si no te respondemos o no estás de acuerdo con la respuesta, puedes acudir a la SIC.</p>

                <h2>9. Seguridad</h2>
                <p>El sitio funciona con conexión cifrada (HTTPS). El acceso al panel interno está protegido con contraseña, y los enlaces de seguimiento de cada reserva son privados y difíciles de adivinar. No compartas tu enlace de seguimiento.</p>
                <p>La credencial de «Mis servicios» viaja en una cookie privada que no es accesible desde JavaScript, se renueva mientras la usas y se puede perder si borras las cookies. Los códigos de recuperación vencen a los 10 minutos, admiten pocos intentos y se invalidan al usarlos. Si detectas un acceso que no reconoces, escríbenos para revocarlo.</p>

                <h2>10. Cookies</h2>
                <p>Lee el <a href="{{ route('legal.cookies') }}">aviso de cookies</a> para saber qué cookies usamos y cómo cambiar tu elección.</p>

                <h2>11. Cambios</h2>
                <p>Si cambiamos esta política, publicamos aquí la nueva versión con su fecha. Si el cambio es importante, te avisaremos.</p>
            </div>

            <div class="legal-foot">
                <a href="{{ route('landing') }}" class="btn btn-gold btn-lg"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver al inicio</a>
            </div>
        </div>
    </article>
@endsection
