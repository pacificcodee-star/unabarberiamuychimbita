@extends('layouts.app')

@section('title', 'WhatsApp')

@section('actions')
    <a href="{{ route('whatsapp.chat.index') }}" class="btn btn-outline-dark">
        <i class="bi bi-chat-dots me-1"></i> Bandeja de chat
        @php($unread = \App\Models\WhatsAppConversation::unreadTotal())
        @if($unread) <span class="badge rounded-pill text-bg-danger">{{ $unread }}</span> @endif
    </a>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}</div>
    @endif

    <div class="alert alert-light border small mb-4">
        <i class="bi bi-info-circle text-warning me-1"></i>
        Aquí se conecta el <strong>WhatsApp de la barbería</strong> mediante <strong>Evolution API</strong> (servicio alojado en Northflank).
        Desde hoy los avisos de citas y turnos salen por este canal, los clientes pueden escribirnos y el
        <strong>bot responde solo</strong>: confirma, cancela y responde precios y horarios.
    </div>

    <div class="row g-3">
        {{-- Estado y QR --}}
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-whatsapp me-1 text-success"></i> Conexión de WhatsApp</span>
                    <span id="wa-state-badge" class="badge-soft {{ ($state ?? '') === 'open' ? 'green' : 'red' }}">
                        @if(($state ?? '') === 'open') Conectado
                        @elseif(($state ?? '') === 'connecting') Escaneando QR
                        @elseif(($state ?? '') === 'close') Desconectado
                        @else Sin consultar
                        @endif
                    </span>
                </div>
                <div class="card-body">
                    @if(! $configured)
                        <div class="empty-state">
                            <i class="bi bi-sliders2"></i>
                            Falta configurar Evolution API en el archivo <code>.env</code>:
                            <code>EVOLUTION_URL</code> y <code>EVOLUTION_APIKEY</code>.
                            Abajo tienes los pasos.
                        </div>
                    @else
                        <div id="wa-qr-box" class="text-center" @if(($state ?? '') !== 'open') style="display:none" @endif>
                            <img id="wa-qr" alt="Código QR de WhatsApp" class="img-fluid rounded border" style="max-width: 260px; background:#fff">
                            <div id="wa-pairing" class="mt-2 fw-semibold"></div>
                        </div>

                        <div id="wa-connected" class="text-center" @if(($state ?? '') !== 'open') style="display:none" @endif>
                            <div class="display-5 my-2">💈</div>
                            <p class="mb-1">El WhatsApp de la barbería está <strong>conectado</strong>.</p>
                            <p class="small text-muted mb-0">Los avisos y el bot ya funcionan con este número.</p>
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
                            <button type="button" id="wa-connect" class="btn btn-gold">
                                <i class="bi bi-qr-code-scan me-1"></i> {{ ($state ?? '') === 'open' ? 'Actualizar estado' : 'Conectar / ver QR' }}
                            </button>
                            <form method="POST" action="{{ route('whatsapp.logout') }}" onsubmit="return confirm('¿Desvincular el número? Habrá que escanear el QR de nuevo.');">
                                @csrf
                                <button type="submit" class="btn btn-outline-dark"><i class="bi bi-box-arrow-right me-1"></i> Desvincular número</button>
                            </form>
                        </div>
                        <div id="wa-alert" class="alert alert-danger mt-3 d-none"></div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-hdd-stack me-1 text-warning"></i> Datos del servicio</div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-5">URL de Evolution</dt>
                        <dd class="col-7 text-break">{{ $baseUrl !== '' ? $baseUrl : '— sin configurar —' }}</dd>
                        <dt class="col-5">Instancia</dt>
                        <dd class="col-7"><code>{{ $instance }}</code></dt>
                        <dt class="col-5">Webhook (entrada)</dt>
                        <dd class="col-7 text-break">
                            <span id="wa-hook-url">{{ $webhookUrl }}</span>
                            <button type="button" class="btn btn-sm btn-outline-dark ms-1" id="wa-copy" title="Copiar URL">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </dd>
                        <dt class="col-5">Estado del webhook</dt>
                        <dd class="col-7">
                            @if(! empty($webhook['enabled']))
                                <span class="badge-soft green">Activo</span>
                            @else
                                <span class="badge-soft red">Inactivo</span>
                            @endif
                        </dd>
                    </dl>
                </div>
                <div class="card-footer">
                    <form method="POST" action="{{ route('whatsapp.webhook') }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-dark w-100">
                            <i class="bi bi-plug me-1"></i> {{ empty($webhook['enabled']) ? 'Conectar webhook automático' : 'Reconectar webhook' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Configuración y bot --}}
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-list-check me-1 text-warning"></i> Cómo ponerlo en marcha</div>
                <div class="card-body small">
                    <ol class="mb-3">
                        <li>En <strong>Northflank</strong> (proyecto Pacificcodes Team) copia la <strong>URL pública</strong> del servicio de Evolution API
                            y la variable <code>AUTHENTICATION_API_KEY</code>.</li>
                        <li>Pega ambas en el <code>.env</code> de esta página:
                            <pre class="bg-dark text-light rounded p-2 mt-2 mb-2">EVOLUTION_URL=https://tu-servicio.northflank.app
EVOLUTION_APIKEY=la-api-key
EVOLUTION_INSTANCE={{ $instance }}</pre>
                        </li>
                        <li>Guarda y ejecuta <code>php artisan config:clear</code>.</li>
                        <li>Presiona <strong>Conectar / ver QR</strong> y escanea con el WhatsApp de la barbería
                            (WhatsApp → Dispositivos vinculados → Vincular dispositivo).</li>
                        <li>Presiona <strong>Conectar webhook automático</strong> para que los mensajes entrantes lleguen al bot y a la bandeja.</li>
                    </ol>
                    <p class="mb-0 text-muted">
                        <i class="bi bi-clock me-1"></i> Cron necesario (avisos y fila): <code>* * * * * php artisan schedule:run</code>
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-robot me-1 text-warning"></i> Bot automático</div>
                <div class="card-body small">
                    <p class="mb-2">
                        @if(config('services.evolution.bot_enabled'))
                            <span class="badge-soft green">Activo</span> Responde solo cuando un cliente escribe:
                        @else
                            <span class="badge-soft red">Pausado</span> Configura <code>WHATSAPP_BOT_ENABLED=true</code> en el <code>.env</code> para activarlo:
                        @endif
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge-soft">confirmar cita</span>
                        <span class="badge-soft">cancelar cita o turno</span>
                        <span class="badge-soft">estado en la fila</span>
                        <span class="badge-soft">precios</span>
                        <span class="badge-soft">horarios</span>
                        <span class="badge-soft">ubicación</span>
                        <span class="badge-soft">reservar</span>
                    </div>
                    <p class="mt-3 mb-0">
                        <i class="bi bi-hand-index-thumb me-1"></i>
                        El equipo puede responder a mano desde la
                        <a href="{{ route('whatsapp.chat.index') }}">bandeja de chat</a> y pausar el bot chat por chat.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @if($configured)
        <script>
            (function () {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const badge = document.getElementById('wa-state-badge');
                const qrBox = document.getElementById('wa-qr-box');
                const qrImg = document.getElementById('wa-qr');
                const connected = document.getElementById('wa-connected');
                const pairing = document.getElementById('wa-pairing');
                const alertBox = document.getElementById('wa-alert');
                const btn = document.getElementById('wa-connect');
                let poll = null;
                let lastState = @json($state);

                const labels = {
                    open: ['Conectado', 'green'],
                    connecting: ['Escaneando QR', ''],
                    close: ['Desconectado', 'red'],
                };

                function paint(state) {
                    if (!badge) return;
                    const [text, tone] = labels[state] || ['Sin consultar', 'red'];
                    badge.textContent = text;
                    badge.className = 'badge-soft ' + (tone || '');
                    if (qrBox) qrBox.style.display = (state !== 'open' && qrImg?.src) ? '' : 'none';
                    if (connected) connected.style.display = state === 'open' ? '' : 'none';
                }

                async function status() {
                    try {
                        const res = await fetch(@json(route('whatsapp.status')), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                        const data = await res.json();
                        if (data.connected && lastState !== 'open') { location.reload(); return; }
                        lastState = data.state;
                        paint(data.state);
                    } catch (e) { /* se reintenta en el próximo ciclo */ }
                }

                async function connect() {
                    if (alertBox) { alertBox.classList.add('d-none'); }
                    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Conectando…'; }
                    try {
                        const res = await fetch(@json(route('whatsapp.connect')), {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        const data = await res.json();

                        if (data.error) { throw new Error(data.error); }

                        if (data.state === 'open') { location.reload(); return; }

                        if (data.qr) {
                            qrImg.src = data.qr.startsWith('data:') ? data.qr : 'data:image/png;base64,' + data.qr;
                            qrBox.style.display = '';
                            if (connected) connected.style.display = 'none';
                            pairing.textContent = data.pairing_code ? 'Código de emparejamiento: ' + data.pairing_code : '';
                            paint('connecting');
                            if (!poll) poll = setInterval(status, 3000);
                        }
                    } catch (e) {
                        if (alertBox) { alertBox.textContent = e.message || 'No se pudo conectar con Evolution API.'; alertBox.classList.remove('d-none'); }
                    } finally {
                        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-qr-code-scan me-1"></i> Conectar / ver QR'; }
                    }
                }

                btn?.addEventListener('click', connect);

                document.getElementById('wa-copy')?.addEventListener('click', function () {
                    navigator.clipboard?.writeText(document.getElementById('wa-hook-url').textContent.trim());
                    this.innerHTML = '<i class="bi bi-check2"></i>';
                    setTimeout(() => { this.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
                });

                // Refresco del estado mientras se espera el escaneo.
                if (lastState !== 'open') { poll = setInterval(status, 5000); }
            })();
        </script>
    @endif
@endpush
