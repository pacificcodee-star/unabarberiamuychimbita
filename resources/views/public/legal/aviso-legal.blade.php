@extends('layouts.public')

@section('title', 'Aviso legal · '.($siteSettings['brand_name'] ?? 'Una Barberia Muy Chimbita'))
@section('meta_description', 'Aviso legal y condiciones de uso del sitio web y del sistema de reservas de '.($siteSettings['brand_name'] ?? 'la barbería').'.')

@section('content')

    <article class="legal-doc" aria-labelledby="legal-title">
        <div class="container">
            <header class="legal-head">
                <div class="section-kicker">Información legal</div>
                <h1 id="legal-title">Aviso legal y condiciones de uso</h1>
                <p class="legal-updated">Última actualización: {{ $legalUpdated }}</p>
            </header>

            <div class="legal-body">
                <h2>1. Quiénes somos</h2>
                <p>Este sitio web pertenece a:</p>
                <ul>
                    <li><strong>Titular:</strong> {{ $legalOwner }}</li>
                    @if($legalNit)<li><strong>NIT / C.C.:</strong> {{ $legalNit }}</li>@endif
                    @if($legalAddress)<li><strong>Dirección:</strong> {{ $legalAddress }}</li>@endif
                    @if($legalPhone)<li><strong>Teléfono:</strong> {{ $legalPhone }}</li>@endif
                    @if($legalEmail)<li><strong>Correo:</strong> <a href="mailto:{{ $legalEmail }}">{{ $legalEmail }}</a></li>@endif
                </ul>

                <h2>2. Para qué sirve este sitio</h2>
                <p>Desde aquí puedes conocer nuestros servicios, precios y barberos, reservar una cita o pedir un turno, seguir el estado de tu reserva y dejar tu opinión. Usar el sitio es gratis y no necesitas crear una cuenta.</p>

                <h2>3. Reservas, turnos y cancelaciones</h2>
                <ul>
                    <li>Una reserva queda hecha cuando ves la pantalla de confirmación. Te damos un enlace privado para seguirla o cancelarla.</li>
                    <li>Si no puedes venir, cancela desde ese enlace o escríbenos por WhatsApp lo antes posible. Así liberamos el espacio para otra persona.</li>
                    <li>Los precios y tiempos que se muestran son de referencia. El valor final puede cambiar si pides algo adicional el día de la visita.</li>
                    <li>Los pagos se hacen en la barbería, con los medios de pago que aparecen en el sitio. Este sitio no cobra en línea ni guarda datos de tarjetas.</li>
                    <li>Podemos reprogramar o cancelar una reserva por causas de fuerza mayor. Si pasa, te avisamos por el medio de contacto que nos diste.</li>
                </ul>

                <h2>4. Uso correcto</h2>
                <p>Al usar el sitio te comprometes a dar datos reales y a no hacer reservas falsas, enviar mensajes automáticos o intentar dañar el servicio. Podemos borrar reservas u opiniones que no cumplan estas reglas, que sean ofensivas o que no tengan relación con la barbería.</p>

                <h2>5. Opiniones</h2>
                <p>Las opiniones que publicas se revisan antes de aparecer y se muestran con el nombre que escribiste. Al enviarlas nos autorizas a mostrarlas en este sitio.</p>

                <h2>6. Propiedad intelectual</h2>
                <p>El nombre, el logo, las fotos, los videos, los textos y el diseño de este sitio son de {{ $legalOwner }} o se usan con permiso. No los copies ni los uses con fines comerciales sin nuestra autorización escrita. Las marcas de terceros (Nequi, Daviplata, Bre-b, WhatsApp, Google, Instagram, Facebook o TikTok) pertenecen a sus dueños.</p>

                <h2>7. Responsabilidad</h2>
                <p>Trabajamos para que el sitio funcione sin interrupciones y con información correcta, pero puede haber fallas técnicas o datos desactualizados. No respondemos por daños causados por el mal uso del sitio ni por el contenido de páginas externas a las que enlazamos.</p>

                <h2>8. Tus datos personales y cookies</h2>
                <p>Cómo tratamos tus datos está explicado en la <a href="{{ route('legal.privacy') }}">política de privacidad y tratamiento de datos</a>. El uso de cookies está explicado en el <a href="{{ route('legal.cookies') }}">aviso de cookies</a>.</p>

                <h2>9. Ley aplicable</h2>
                <p>Estas condiciones se rigen por las leyes de la República de Colombia, incluido el Estatuto del Consumidor (Ley 1480 de 2011). Si tienes un reclamo, escríbenos primero para buscar una solución directa. También puedes acudir a la Superintendencia de Industria y Comercio.</p>
            </div>
        </div>
    </article>
@endsection
