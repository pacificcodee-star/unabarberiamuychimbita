@php
    $legalModalOwner = config('web.legal.owner') ?: ($siteSettings['brand_name'] ?? $brandName);
    $legalModalNit = config('web.legal.nit');
    $legalModalEmail = config('web.legal.email') ?: ($siteSettings['email'] ?? '');
    $legalModalAddress = trim(($siteSettings['address'] ?? '').', '.($siteSettings['city'] ?? ''), ', ');
    $legalModalPhone = $siteSettings['phone'] ?? '';
    $legalModalContactLine = collect([
        '<strong>'.e($legalModalOwner).'</strong>',
        $legalModalNit ? 'NIT/C.C. '.e($legalModalNit) : null,
        $legalModalAddress ? e($legalModalAddress) : null,
        $legalModalPhone ? e($legalModalPhone) : null,
        $legalModalEmail ? '<a href="mailto:'.e($legalModalEmail).'">'.e($legalModalEmail).'</a>' : null,
    ])->filter()->implode(' · ');
    $legalModalHowToContact = $legalModalEmail && $legalModalPhone
        ? 'a <a href="mailto:'.e($legalModalEmail).'">'.e($legalModalEmail).'</a> o por WhatsApp al '.e($legalModalPhone)
        : ($legalModalEmail
            ? 'a <a href="mailto:'.e($legalModalEmail).'">'.e($legalModalEmail).'</a>'
            : ($legalModalPhone ? 'por WhatsApp al '.e($legalModalPhone) : 'por los medios de contacto del sitio'));
@endphp

<dialog class="legal-modal" id="legalNoticeModal" data-lenis-prevent aria-labelledby="legal-notice-title">
    <div class="legal-modal-card">
        <div class="legal-modal-head">
            <div class="section-kicker">Información legal</div>
            <h2 id="legal-notice-title">Aviso legal y condiciones de uso</h2>
            <button type="button" class="legal-modal-close" data-legal-close aria-label="Cerrar aviso legal">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="legal-modal-body">
            <h3>Quiénes somos</h3>
            <p>{!! $legalModalContactLine !!}</p>

            <h3>Reservas y cancelaciones</h3>
            <p>La reserva queda hecha al confirmar y te damos un enlace privado para verla o cancelarla. Si no puedes venir, avísanos por WhatsApp. Los precios son de referencia y se pagan en la barbería; el sitio no procesa pagos ni guarda datos de tarjetas.</p>

            <h3>Pagos y devoluciones</h3>
            <p>Cancelar sin pago anticipado no tiene costo. Si pagaste antes y hay que cancelar, devolvemos por el mismo medio según la Ley 1480 de 2011. Un servicio ya prestado no se devuelve, salvo reclamo por calidad.</p>

            <h3>Uso correcto y opiniones</h3>
            <p>Da datos reales y no hagas reservas falsas ni intentes dañar el sitio. Las opiniones se revisan y se publican con el nombre que escribiste.</p>

            <h3>Propiedad y responsabilidad</h3>
            <p>El nombre, logo, fotos, videos y diseño son de {{ $legalModalOwner }} o se usan con permiso. Puede haber fallas técnicas; no respondemos por daños causados por el mal uso del sitio.</p>

            <h3>Ley aplicable</h3>
            <p>Aplica la ley colombiana, incluido el Estatuto del Consumidor (Ley 1480 de 2011). Puedes acudir a la Superintendencia de Industria y Comercio.</p>
            <p>Más detalle en la <a href="{{ route('legal.privacy') }}">política de privacidad</a> y el <a href="{{ route('legal.cookies') }}">aviso de cookies</a>.</p>

            <p class="legal-modal-more"><a href="{{ route('legal.notice') }}">Ver el aviso legal completo</a></p>
        </div>
    </div>
</dialog>

<dialog class="legal-modal" id="legalPrivacyModal" data-lenis-prevent aria-labelledby="legal-privacy-title">
    <div class="legal-modal-card">
        <div class="legal-modal-head">
            <div class="section-kicker">Información legal</div>
            <h2 id="legal-privacy-title">Política de privacidad y tratamiento de datos</h2>
            <button type="button" class="legal-modal-close" data-legal-close aria-label="Cerrar política de privacidad">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="legal-modal-body">
            <h3>Responsable</h3>
            <p>{!! $legalModalContactLine !!}</p>

            <h3>Datos que recogemos</h3>
            <p>Al reservar: nombre, celular, correo opcional, servicio, barbero, fecha, hora y notas. Si aceptas avisos: tu número y los mensajes por WhatsApp. Al opinar: nombre, calificación y comentario. Al navegar: datos técnicos; la analítica solo se activa con tu permiso de cookies.</p>

            <h3>Para qué los usamos</h3>
            <p>Para agendar y gestionar citas, responderte, publicar opiniones revisadas, llevar el historial y medir el uso del sitio. No vendemos ni alquilamos tus datos.</p>

            <h3>Autorización</h3>
            <p>Nos autorizas al enviar cada formulario, después de leer el aviso. Los avisos por WhatsApp se aceptan aparte y puedes cancelarlos escribiendo <strong>BAJA</strong>.</p>

            <h3>Con quién los compartimos</h3>
            <p>Solo con proveedores necesarios (alojamiento, WhatsApp/Meta si aceptaste los avisos, notificaciones y Google si aceptaste cookies) y cuando lo ordene una autoridad. Algunos pueden guardar datos fuera de Colombia.</p>

            <h3>Conservación y derechos</h3>
            <p>Guardamos los datos mientras sean útiles para el servicio y para cumplir obligaciones legales. Puedes conocer, actualizar, corregir, revocar o pedir el borrado de tus datos, y reclamar ante la SIC.</p>

            <h3>Cómo ejercerlos</h3>
            <p>Escríbenos {!! $legalModalHowToContact !!} con tu nombre y celular. Respondemos consultas en máximo 10 días hábiles y reclamos en 15.</p>

            <h3>Seguridad y cambios</h3>
            <p>Usamos HTTPS, el panel interno está protegido y los enlaces de seguimiento son privados. Si cambia la política, publicamos la versión nueva con su fecha. El detalle de cookies está en el <a href="{{ route('legal.cookies') }}">aviso de cookies</a>.</p>

            <p class="legal-modal-more"><a href="{{ route('legal.privacy') }}">Ver la política completa</a></p>
        </div>
    </div>
</dialog>

<script>
(() => {
    const routes = {
        notice: new URL(@json(route('legal.notice')), location.href).pathname,
        privacy: new URL(@json(route('legal.privacy')), location.href).pathname,
    };
    const dialogs = {
        notice: document.getElementById('legalNoticeModal'),
        privacy: document.getElementById('legalPrivacyModal'),
    };

    document.addEventListener('click', (event) => {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.closest('.legal-modal') || link.target === '_blank') return;

        const path = new URL(link.href, location.href).pathname;
        const key = Object.keys(routes).find((name) => routes[name] === path);
        const dialog = key ? dialogs[key] : null;
        if (!dialog || typeof dialog.showModal !== 'function') return;

        event.preventDefault();
        if (!dialog.open) dialog.showModal();
        document.body.classList.add('legal-modal-open');
    });

    Object.values(dialogs).forEach((dialog) => {
        if (!dialog) return;
        dialog.querySelectorAll('[data-legal-close]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
        dialog.addEventListener('close', () => document.body.classList.remove('legal-modal-open'));
    });
})();
</script>
