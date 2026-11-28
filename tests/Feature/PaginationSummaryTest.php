<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class PaginationSummaryTest extends TestCase
{
    public function test_el_paginador_del_panel_oculta_el_resumen_y_conserva_los_controles(): void
    {
        $paginator = new LengthAwarePaginator(range(1, 20), 62, 20, 1, [
            'path' => '/comprobantes',
        ]);

        $vistas = [
            'predeterminada' => (string) $paginator->links(),
            'bootstrap' => (string) $paginator->links('pagination::bootstrap-5'),
        ];

        foreach ($vistas as $vista => $html) {
            $this->assertStringNotContainsString('Showing', $html, $vista);
            $this->assertStringNotContainsString('Mostrando', $html, $vista);
            $this->assertStringNotContainsString('results', $html, $vista);
            $this->assertStringContainsString('class="pagination ms-auto"', $html, $vista);
            $this->assertStringContainsString('Anterior', $html, $vista);
            $this->assertStringContainsString('Siguiente', $html, $vista);
            $this->assertStringNotContainsString('viewBox="0 0 20 20"', $html, $vista);
            $this->assertStringNotContainsString('pagination.previous', $html, $vista);
            $this->assertStringNotContainsString('pagination.next', $html, $vista);
        }
    }
}
