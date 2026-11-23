{{--
    Cambio de página casi instantáneo: Chrome, Edge y Android descargan la página de un enlace del sitio
    cuando el mouse se posa sobre él (o al empezar a tocarlo en el celular). Al hacer clic ya está lista.
    Solo se descarga el HTML: no se ejecuta nada de la página hasta que la persona entra.
    Quedan fuera las páginas que cambian algo con solo abrirlas (marcar avisos o chats como leídos,
    enlaces de acceso de un solo uso, recibos para imprimir) y los enlaces con data-no-prefetch.
    Las pestañas de la barra inferior del celular (.mnav) se descargan apenas carga la página, para que
    cambiar de pestaña sea casi instantáneo (solo el HTML comprimido, unos 20 KB por página).
    La regla usa data-mnav-current, que marca el servidor y ningún script cambia: si dependiera de un
    atributo que cambia al tocar (como antes aria-current), Chrome descartaría la página ya descargada
    justo al ir a abrirla y la pediría otra vez al servidor.
    Los navegadores que no conocen esta función la ignoran.
--}}
<script type="speculationrules">
{
    "prefetch": [{
        "where": {
            "and": [
                { "href_matches": "/*" },
                { "not": { "href_matches": "/logout" } },
                { "not": { "href_matches": "/notificaciones" } },
                { "not": { "href_matches": "/whatsapp/chat/*" } },
                { "not": { "href_matches": "/mis-servicios/acceso/*" } },
                { "not": { "href_matches": "/seguimiento/*" } },
                { "not": { "href_matches": "/encuesta/*" } },
                { "not": { "href_matches": "/restablecer-contrasena/*" } },
                { "not": { "href_matches": "/sesion/*" } },
                { "not": { "href_matches": "/sales/*/receipt" } },
                { "not": { "selector_matches": "[download], [target=_blank], [data-no-prefetch]" } }
            ]
        },
        "eagerness": "moderate"
    }, {
        "where": { "selector_matches": ".mnav__item:not([data-mnav-current])" },
        "eagerness": "immediate"
    }]
}
</script>
