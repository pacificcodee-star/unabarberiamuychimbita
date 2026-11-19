<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Textos nuevos del inicio: frase, misión y visión (también se usan en Nosotros). */
    private array $content = [
        'home_quote' => 'Más que una barbería, somos un espacio donde el estilo y la tradición se unen para ofrecerte un servicio único que va más allá del espejo: queremos que te veas bien y te sientas mejor.',
        'mission' => 'Brindar un servicio de barbería de calidad, ofreciendo cortes modernos, atención personalizada y experiencias que hagan sentir cómodos y satisfechos a nuestros clientes. Nos enfocamos en el cuidado personal, los buenos resultados y un ambiente agradable, donde cada persona reciba un servicio que supere sus expectativas.',
        'vision' => 'Ser una barbería reconocida en Tumaco por la calidad de nuestros servicios, la atención al cliente y la innovación. Buscamos crecer como negocio, mejorar constantemente y convertirnos en un referente de la barbería, destacándonos por ofrecer experiencias diferentes y construir relaciones de confianza con nuestros clientes.',
    ];

    public function up(): void
    {
        $row = DB::table('site_settings')->orderBy('id')->first();

        if (! $row) {
            return; // Sin registro guardado: el sitio usa los valores por defecto, que ya traen estos textos.
        }

        $data = json_decode($row->data ?? '[]', true) ?: [];
        $data = array_merge($data, $this->content);

        DB::table('site_settings')->where('id', $row->id)->update([
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // No se restauran los textos anteriores: pueden editarse desde Contenido del sitio.
    }
};
