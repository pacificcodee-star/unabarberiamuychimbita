<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BarberController;
use App\Http\Controllers\CashSessionController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvolutionWebhookController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\PublicReviewController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SiteSettingController;
use App\Http\Controllers\StaffNotificationController;
use App\Http\Controllers\StaffPushController;
use App\Http\Controllers\SupplyController;
use App\Http\Controllers\TrackController;
use App\Http\Controllers\TurnController;
use App\Http\Controllers\WhatsAppChatController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::get('/sitemap.xml', function () {
    return response()->view('sitemap')->header('Content-Type', 'application/xml; charset=UTF-8');
})->name('sitemap');
Route::get('/robots.txt', function () {
    return response()->view('robots')->header('Content-Type', 'text/plain; charset=UTF-8');
})->name('robots');

// Salud para monitores externos (UptimeRobot, Better Stack…): base de datos y tarea programada.
// Responde 503 si algo falla, sin detalles técnicos. /up (Laravel) solo dice que PHP responde.
Route::get('/salud', function () {
    $checks = ['base_de_datos' => false, 'tarea_programada' => false];
    try {
        \Illuminate\Support\Facades\DB::select('select 1');
        $checks['base_de_datos'] = true;
        $last = \Illuminate\Support\Facades\Cache::get('salud:tick');
        $checks['tarea_programada'] = $last && \Illuminate\Support\Carbon::parse($last)->gt(now()->subMinutes(5));
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::critical('salud.fallo', ['error' => class_basename($e)]);
    }
    $ok = ! in_array(false, $checks, true);

    return response()->json(['ok' => $ok] + $checks, $ok ? 200 : 503)->header('Cache-Control', 'no-store');
})->middleware('throttle:60,1')->name('health');

// Errores de JavaScript de los visitantes (sendBeacon, sin CSRF; ver public/js/error-guard.js).
Route::post('/errores-cliente', ClientErrorController::class)->middleware('throttle:20,1')->name('client-errors');

// Páginas legales
Route::view('/aviso-legal', 'public.legal.aviso-legal')->name('legal.notice');
Route::view('/politica-de-privacidad', 'public.legal.privacidad')->name('legal.privacy');
Route::view('/cookies', 'public.legal.cookies')->name('legal.cookies');
Route::get('/nosotros', [LandingController::class, 'about'])->name('public.about');
Route::get('/servicios', [LandingController::class, 'services'])->name('public.services');
Route::get('/barberos', [LandingController::class, 'barbers'])->name('public.barbers');
Route::get('/resenas', [LandingController::class, 'reviews'])->name('public.reviews');
Route::post('/resenas', [PublicReviewController::class, 'store'])->middleware('throttle:5,10')->name('public.reviews.store');
Route::get('/contacto', [LandingController::class, 'contact'])->name('public.contact');
Route::get('/reservar', [PublicBookingController::class, 'index'])->name('public.booking');
Route::get('/reservar/horarios', [PublicBookingController::class, 'availableTimes'])->name('public.booking.slots');
Route::post('/reservar/cita', [PublicBookingController::class, 'storeAppointment'])->middleware('throttle:10,1')->name('public.booking.appointment');
Route::post('/reservar/turno', [PublicBookingController::class, 'storeTurn'])->middleware('throttle:10,1')->name('public.booking.turn');
// ¿Ya quedó hecha la reserva con esta clave? (cuando se perdió la respuesta por falta de conexión)
Route::get('/reservar/estado/{key}', [PublicBookingController::class, 'bookingStatus'])->middleware('throttle:30,1')->name('public.booking.status');
// Token CSRF nuevo para seguir un formulario cuya sesión se venció (error 419) sin perder lo escrito.
Route::get('/sesion/token', fn () => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'))
    ->middleware('throttle:30,1')->name('session.token');

// Seguimiento del cliente sin cuenta: se entra con el enlace secreto del turno o cita.
Route::prefix('seguimiento/{type}/{token}')
    ->where(['type' => 'turno|cita', 'token' => '[A-Za-z0-9]{40}'])
    ->group(function () {
        Route::get('/', [TrackController::class, 'show'])->middleware('throttle:60,1')->name('track.show');
        Route::get('/estado', [TrackController::class, 'status'])->middleware('throttle:60,1')->name('track.status');
        Route::get('/app.webmanifest', [TrackController::class, 'manifest'])->middleware('throttle:30,1')->name('track.manifest');
        Route::post('/cancelar', [TrackController::class, 'cancel'])->middleware('throttle:10,1')->name('track.cancel');
        Route::post('/push', [TrackController::class, 'subscribe'])->middleware('throttle:20,1')->name('track.push.store');
        Route::delete('/push', [TrackController::class, 'unsubscribe'])->middleware('throttle:20,1')->name('track.push.destroy');
    });
// Renovación automática de la suscripción desde el Service Worker (sin página abierta, por eso va sin CSRF).
Route::post('/push/renovar', [TrackController::class, 'renew'])->middleware('throttle:20,1')->name('push.renew');

// Webhook de Evolution API: entra todo lo que escriben los clientes (sin sesión y sin CSRF,
// protegido con la apikey que Evolution envía en cada evento).
Route::post('/webhook/evolution', EvolutionWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhook.evolution');

Route::middleware('panel-desktop')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['panel-desktop', 'auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/staff/push', [StaffPushController::class, 'store'])->middleware('throttle:20,1')->name('staff.push.store');
    Route::delete('/staff/push', [StaffPushController::class, 'destroy'])->middleware('throttle:20,1')->name('staff.push.destroy');

    Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');
    Route::post('/sales/{sale}/items/{item}/return', [SaleController::class, 'returnItem'])->name('sales.items.return');
    Route::resource('sales', SaleController::class)->only(['index', 'create', 'store', 'show', 'destroy']);

    // Campana del panel: avisos para el equipo e historial de avisos a clientes.
    Route::get('/notificaciones', [StaffNotificationController::class, 'index'])->name('staff-notifications.index');
    Route::get('/notificaciones/feed', [StaffNotificationController::class, 'feed'])->name('staff-notifications.feed');
    Route::post('/notificaciones/vistas', [StaffNotificationController::class, 'seen'])->name('staff-notifications.seen');

    Route::get('/turns', [TurnController::class, 'index'])->name('turns.index');
    Route::post('/turns', [TurnController::class, 'store'])->name('turns.store');
    Route::post('/turns/call-next', [TurnController::class, 'callNext'])->name('turns.call-next');
    Route::patch('/turns/{turn}/status', [TurnController::class, 'updateStatus'])->name('turns.status');
    Route::delete('/turns/{turn}', [TurnController::class, 'destroy'])->name('turns.destroy');

    Route::patch('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.status');
    Route::resource('appointments', AppointmentController::class)->except(['show']);

    Route::get('/cash', [CashSessionController::class, 'index'])->name('cash.index');
    Route::post('/cash/open', [CashSessionController::class, 'open'])->name('cash.open');
    Route::get('/cash/{cashSession}', [CashSessionController::class, 'show'])->name('cash.show');
    Route::post('/cash/{cashSession}/close', [CashSessionController::class, 'close'])->name('cash.close');

    Route::middleware('admin')->group(function () {
        Route::get('/site-settings', [SiteSettingController::class, 'edit'])->name('site-settings.edit');
        Route::put('/site-settings', [SiteSettingController::class, 'update'])->name('site-settings.update');
        Route::resource('services', ServiceController::class)->except(['show']);
        Route::resource('expenses', ExpenseController::class)->except(['show']);
        Route::post('/insumos/entregas', [SupplyController::class, 'storeDelivery'])->name('supplies.deliveries.store');
        Route::delete('/insumos/entregas/{delivery}', [SupplyController::class, 'destroyDelivery'])->name('supplies.deliveries.destroy');
        Route::resource('insumos', SupplyController::class)->except(['show'])->names('supplies')->parameters(['insumos' => 'supply']);
        Route::patch('/barbers/{barber}/toggle', [BarberController::class, 'toggle'])->name('barbers.toggle');
        Route::resource('barbers', BarberController::class)->except(['show']);
        Route::get('/disponibilidad', [AvailabilityController::class, 'index'])->name('availability.index');
        Route::post('/disponibilidad', [AvailabilityController::class, 'store'])->name('availability.store');
        Route::delete('/disponibilidad/{availability}', [AvailabilityController::class, 'destroy'])->name('availability.destroy');

        // Nómina: lo ganado por servicios − insumos entregados = pago del barbero.
        Route::get('/nomina', [CommissionController::class, 'index'])->name('commissions.index');
        Route::post('/nomina', [CommissionController::class, 'store'])->name('commissions.store');
        Route::get('/nomina/barbero/{barber}', [CommissionController::class, 'show'])->name('commissions.show');
        Route::delete('/nomina/{commission}', [CommissionController::class, 'destroy'])->name('commissions.destroy');
        Route::redirect('/commissions', '/nomina');

        Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
        Route::post('/pos', [PosController::class, 'store'])->name('pos.store');
        Route::get('/pos/inventario', [PosController::class, 'inventory'])->name('pos.inventory');
        Route::get('/pos/historial', [PosController::class, 'history'])->name('pos.history');
        Route::post('/pos/productos', [PosController::class, 'storeProduct'])->name('pos.products.store');
        Route::put('/pos/productos/{product}', [PosController::class, 'updateProduct'])->name('pos.products.update');
        Route::delete('/pos/productos/{product}', [PosController::class, 'destroyProduct'])->name('pos.products.destroy');
        Route::post('/pos/productos/{product}/stock', [PosController::class, 'adjustStock'])->name('pos.products.stock');

        Route::get('/facturas', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/facturas/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

        // WhatsApp (Evolution API): instancia + QR, y bandeja de chat con los clientes.
        Route::get('/whatsapp', [WhatsAppController::class, 'index'])->name('whatsapp.index');
        Route::get('/whatsapp/status', [WhatsAppController::class, 'status'])->name('whatsapp.status');
        Route::post('/whatsapp/connect', [WhatsAppController::class, 'connect'])->name('whatsapp.connect');
        Route::post('/whatsapp/logout', [WhatsAppController::class, 'logout'])->name('whatsapp.logout');
        Route::post('/whatsapp/webhook', [WhatsAppController::class, 'configureWebhook'])->name('whatsapp.webhook');
        Route::post('/whatsapp/promocion', [WhatsAppController::class, 'sendPromotion'])->name('whatsapp.promotion');

        Route::get('/whatsapp/chat', [WhatsAppChatController::class, 'index'])->name('whatsapp.chat.index');
        Route::get('/whatsapp/chat/{conversation}', [WhatsAppChatController::class, 'show'])->name('whatsapp.chat.show');
        Route::get('/whatsapp/chat/{conversation}/messages', [WhatsAppChatController::class, 'messages'])->name('whatsapp.chat.messages');
        Route::post('/whatsapp/chat/{conversation}/send', [WhatsAppChatController::class, 'send'])->name('whatsapp.chat.send');
        Route::post('/whatsapp/chat/{conversation}/bot', [WhatsAppChatController::class, 'toggleBot'])->name('whatsapp.chat.bot');
        Route::delete('/whatsapp/chat/{conversation}', [WhatsAppChatController::class, 'destroy'])->name('whatsapp.chat.destroy');
    });
});
