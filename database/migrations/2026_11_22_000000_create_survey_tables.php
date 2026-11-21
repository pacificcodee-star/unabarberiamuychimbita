<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encuestas de satisfacción tras el servicio. Solo agrega tablas y columnas; no borra nada.
 * Se reutiliza lo que ya existe en vez de duplicarlo:
 *  - client_notifications: historial de envío de la invitación (intentos, errores, canal).
 *  - consent_records: autorización para publicar la opinión (finalidad publicacion_resena).
 *  - reviews: opiniones públicas (se le agregan columnas de origen y moderación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_questions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('text', 255);
            $table->string('help', 255)->nullable();
            $table->string('type', 20); // stars | choice | text | consent
            $table->json('options')->nullable(); // [{value, label}]
            $table->boolean('is_optional')->default(false);
            $table->json('show_if')->nullable(); // {question, operator: in|filled, values: []}
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('survey_invitations', function (Blueprint $table) {
            $table->id();
            // Una sola invitación por servicio (cita o turno).
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('barber_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->date('service_date');
            $table->string('status', 20)->default('programada'); // programada | enviada | completada | cancelada | vencida
            $table->dateTime('send_after');
            $table->timestamp('sent_at')->nullable();
            $table->dateTime('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id'], 'survey_invitation_subject_unique');
            $table->index(['status', 'send_after']);
            $table->index(['barber_id', 'service_date']);
        });

        Schema::create('survey_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_invitation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // web | whatsapp (donde empezó)
            $table->string('status', 20)->default('en_curso'); // en_curso | completada
            // Copia de las preguntas al empezar: si el admin las cambia después, esta encuesta no se altera.
            $table->json('questions');
            $table->dateTime('started_at');
            $table->dateTime('last_activity_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_activity_at']);
        });

        Schema::create('survey_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_session_id')->constrained()->cascadeOnDelete();
            $table->string('question_key', 40);
            $table->string('question_text', 255);
            $table->string('type', 20);
            $table->text('value')->nullable(); // null = omitida
            $table->string('value_label', 255)->nullable();
            $table->string('channel', 20);
            $table->dateTime('answered_at');
            $table->timestamps();

            // Una respuesta por pregunta: reintentos o eventos duplicados la actualizan, no la repiten.
            $table->unique(['survey_session_id', 'question_key']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->string('source', 30)->default('formulario_web')->after('approved');
            $table->foreignId('survey_session_id')->nullable()->unique()->after('source')->constrained()->nullOnDelete();
            $table->foreignId('barber_id')->nullable()->after('survey_session_id')->constrained('users')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->after('barber_id')->constrained('services')->nullOnDelete();
            $table->boolean('anonymous')->default(false)->after('service_id');
            $table->string('moderation_status', 20)->nullable()->after('anonymous'); // pendiente | aprobada | revision | oculta | retirada
            $table->string('moderation_reason', 255)->nullable()->after('moderation_status');
            $table->timestamp('published_at')->nullable()->after('moderation_reason');
            $table->timestamp('withdrawn_at')->nullable()->after('published_at');
        });

        // Las opiniones anteriores conservan su estado: aprobadas → publicadas; las demás, pendientes.
        DB::table('reviews')->where('approved', true)->update(['moderation_status' => 'aprobada']);
        DB::table('reviews')->where('approved', false)->update(['moderation_status' => 'pendiente']);

        $this->seedDefaultQuestions();
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('survey_session_id');
            $table->dropConstrainedForeignId('barber_id');
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['source', 'anonymous', 'moderation_status', 'moderation_reason', 'published_at', 'withdrawn_at']);
        });
        Schema::dropIfExists('survey_answers');
        Schema::dropIfExists('survey_sessions');
        Schema::dropIfExists('survey_invitations');
        Schema::dropIfExists('survey_questions');
    }

    /** Preguntas iniciales (editables luego desde el panel). */
    private function seedDefaultQuestions(): void
    {
        $now = now();
        $opt = fn (array $pairs) => json_encode(array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys($pairs), $pairs), JSON_UNESCAPED_UNICODE);
        $rows = [
            ['experiencia', '¿Cómo calificarías tu experiencia?', null, 'stars', null, false, null],
            ['lo_mejor', '¿Qué fue lo que más te gustó?', 'Elige una opción.', 'choice', $opt(['corte' => 'El corte', 'atencion' => 'La atención', 'ambiente' => 'El ambiente', 'puntualidad' => 'La puntualidad', 'precio' => 'El precio', 'otro' => 'Otra cosa']), true, null],
            ['atencion_barbero', '¿Cómo calificarías la atención de tu barbero?', null, 'stars', null, false, null],
            ['expectativas', '¿El resultado fue lo que esperabas?', null, 'choice', $opt(['si' => 'Sí', 'parcial' => 'En parte', 'no' => 'No']), false, null],
            ['que_mejorar', '¿Qué podríamos haber hecho mejor?', 'Tu respuesta solo la ve el equipo.', 'text', null, true, json_encode(['question' => 'expectativas', 'operator' => 'in', 'values' => ['parcial', 'no']])],
            ['recomendaria', '¿Recomendarías nuestra barbería?', null, 'choice', $opt(['si' => 'Sí', 'tal_vez' => 'Tal vez', 'no' => 'No']), false, null],
            ['comentario', '¿Quieres dejarnos un comentario?', 'Opcional. Cuéntanos con tus palabras.', 'text', null, true, null],
            ['publicar', '¿Autorizas publicar tu comentario en nuestra página web?', 'Puedes pedir que lo retiremos cuando quieras.', 'consent', $opt(['si_nombre' => 'Sí, con mi nombre', 'si_anonimo' => 'Sí, sin mi nombre', 'no' => 'No, solo para el equipo']), false, json_encode(['question' => 'comentario', 'operator' => 'filled'])],
        ];
        foreach ($rows as $i => [$key, $text, $help, $type, $options, $optional, $showIf]) {
            DB::table('survey_questions')->insert([
                'key' => $key, 'text' => $text, 'help' => $help, 'type' => $type, 'options' => $options,
                'is_optional' => $optional, 'show_if' => $showIf, 'sort_order' => ($i + 1) * 10, 'active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
