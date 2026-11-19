<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\CashSession;
use App\Models\CommissionPayment;
use App\Models\Expense;
use App\Models\Review;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@barberia.com'],
            [
                'name' => 'Administrador',
                'password' => 'password',
                'role' => 'admin',
                'phone' => '300 000 0000',
                'commission_percentage' => 0,
                'attention_mode' => 'llegada',
                'active' => true,
            ]
        );

        $barbersData = [
            [
                'name' => 'Carlos Mendoza',
                'email' => 'carlos@barberia.com',
                'photo_path' => 'barbers/referencia-01-sin-fondo.png',
                'commission_percentage' => 40,
                'attention_mode' => 'llegada',
                'phone' => '310 111 1111',
                'specialty' => 'Cortes clásicos y fades',
                'bio' => 'Barbero tradicional con más de 12 años de experiencia. Especialista en cortes clásicos, fades y perfilado de barba. Su precisión y trato cercano conquistan a quien se sienta en su silla.',
            ],
            [
                'name' => 'Andrés Gómez',
                'email' => 'andres@barberia.com',
                'photo_path' => 'barbers/referencia-02-sin-fondo.png',
                'commission_percentage' => 45,
                'attention_mode' => 'citas',
                'phone' => '320 222 2222',
                'specialty' => 'Cortes modernos y texturizados',
                'bio' => 'Apasionado por el diseño de cortes modernos y texturizados. Trabaja con cita previa para dedicar el tiempo que cada cliente merece y convertir cada visita en una experiencia a medida.',
            ],
            [
                'name' => 'Mateo Ruiz',
                'email' => 'mateo@barberia.com',
                'photo_path' => 'barbers/referencia-03-sin-fondo.png',
                'commission_percentage' => 35,
                'attention_mode' => 'citas',
                'phone' => '315 333 3333',
                'specialty' => 'Barba y afeitado tradicional',
                'bio' => 'Experto en barba y afeitado tradicional con navaja. Combina técnica, paciencia y un trato cercano para que cada cliente salga renovado y con la confianza al máximo.',
            ],
            [
                'name' => 'Juan Pablo Ríos',
                'email' => 'juan@barberia.com',
                'photo_path' => 'barbers/referencia-04-sin-fondo.png',
                'commission_percentage' => 50,
                'attention_mode' => 'llegada',
                'phone' => '301 444 4444',
                'specialty' => 'Color y transformaciones',
                'bio' => 'Especialista en color, tintes y transformaciones. Mezcla tendencia y técnica para lograr looks únicos, siempre cuidando la salud del cabello y el estilo de cada persona.',
            ],
        ];

        $barbers = collect($barbersData)->map(fn (array $data) => User::updateOrCreate(
            ['email' => $data['email']],
            [...$data, 'password' => 'password', 'role' => 'barbero', 'active' => true]
        ));

        $this->call(CatalogoServiciosSeeder::class);

        $reviewsData = [
            ['client_name' => 'Felipe R.', 'comment' => 'La atención es increíble y el corte quedó exactamente como lo pedí. Se nota la experiencia y el cuidado en cada detalle.'],
            ['client_name' => 'Camila S.', 'comment' => 'Llevo más de un año viniendo y nunca me han fallado. El ambiente es excelente y siempre salgo como nuevo.'],
            ['client_name' => 'Daniel M.', 'comment' => 'El arreglo de barba con Mateo fue otro nivel. Puntualidad, buena música y un resultado impecable.'],
            ['client_name' => 'Laura G.', 'comment' => 'Pedí cita por la página y todo fue muy fácil. Me atendieron a la hora y el servicio fue de 10.'],
            ['client_name' => 'Sebastián P.', 'comment' => 'Excelente lugar para un cambio de look. Juan Pablo entendió perfecto lo que quería y el resultado superó mis expectativas.'],
            ['client_name' => 'Diego A.', 'comment' => 'Un clásico de confianza. Precio justo, buena atención y siempre salgo satisfecho.'],
        ];

        foreach ($reviewsData as $review) {
            Review::updateOrCreate(
                ['client_name' => $review['client_name'], 'comment' => $review['comment']],
                // Reseñas de ejemplo: se cargan OCULTAS. En la web solo se publican opiniones reales aprobadas en el panel.
                ['rating' => 5, 'approved' => false]
            );
        }

        $walkInServices = Service::active()->get();
        foreach ($barbers->where('attention_mode', 'llegada')->values() as $index => $barber) {
            foreach (range(1, $index === 0 ? 2 : 1) as $number) {
                Turn::firstOrCreate(
                    [
                        'barber_id' => $barber->id,
                        'turn_date' => now()->toDateString(),
                        'turn_number' => $number,
                    ],
                    [
                        'service_id' => $walkInServices->random()->id,
                        'client_name' => $number === 1 ? 'Cliente ocasional' : 'Visitante',
                        'client_phone' => null,
                        'status' => 'esperando',
                    ]
                );
            }
        }

        if (Sale::exists()) {
            return;
        }

        $services = Service::active()->get();
        $clientNames = ['Cliente ocasional', 'Felipe', 'Sofía', 'Camila', 'Daniel', 'Laura', 'Sebastián', 'Valentina', 'Diego', 'Mariana'];
        $paymentMethods = array_keys(Sale::PAYMENT_METHODS);
        $expenseDescriptions = [
            'arriendo' => 'Arriendo del local',
            'servicios_publicos' => 'Pago de servicios públicos',
            'productos' => 'Compra de productos para el cabello',
            'cuchillas' => 'Reposición de cuchillas',
            'desinfectantes' => 'Desinfectante y limpieza',
            'publicidad' => 'Publicidad en redes sociales',
            'refrigerios' => 'Refrigerios para el equipo',
            'otros' => 'Gasto varios',
        ];

        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo);
            $isToday = $daysAgo === 0;

            $session = CashSession::create([
                'opened_by' => $admin->id,
                'initial_amount' => 50000,
                'status' => 'abierta',
                'opened_at' => $date->copy()->setTime(8, 0),
            ]);

            $saleCount = $isToday ? 4 : rand(3, 7);

            for ($i = 0; $i < $saleCount; $i++) {
                $barber = $barbers->random();
                $chosenServices = $services->random(rand(1, 2));
                $total = 0;
                $items = [];

                foreach ($chosenServices as $service) {
                    $subtotal = (float) $service->price;
                    $total += $subtotal;
                    $items[] = [
                        'service_id' => $service->id,
                        'service_name' => $service->name,
                        'price' => $service->price,
                        'quantity' => 1,
                        'subtotal' => $subtotal,
                    ];
                }

                $sale = Sale::create([
                    'barber_id' => $barber->id,
                    'cash_session_id' => $session->id,
                    'client_name' => $clientNames[array_rand($clientNames)],
                    'payment_method' => $paymentMethods[array_rand($paymentMethods)],
                    'total' => $total,
                    'commission_percentage' => $barber->commission_percentage,
                    'commission_amount' => round($total * ((float) $barber->commission_percentage) / 100, 2),
                    'sold_at' => $date->copy()->setTime(rand(9, 19), rand(0, 59)),
                ]);

                $sale->items()->createMany($items);
            }

            for ($i = 0; $i < rand(0, 2); $i++) {
                $category = array_rand(Expense::CATEGORIES);
                Expense::create([
                    'user_id' => $admin->id,
                    'cash_session_id' => $session->id,
                    'category' => $category,
                    'description' => $expenseDescriptions[$category],
                    'amount' => rand(5, 50) * 1000,
                    'expense_date' => $date->toDateString(),
                ]);
            }

            if (! $isToday) {
                $expected = $session->calculateExpected();
                $counted = $expected + rand(-2000, 2000);

                $session->update([
                    'closed_by' => $admin->id,
                    'expected_amount' => $expected,
                    'counted_amount' => $counted,
                    'difference' => round($counted - $expected, 2),
                    'status' => 'cerrada',
                    'closed_at' => $date->copy()->setTime(20, 0),
                ]);
            }
        }

        CommissionPayment::create([
            'barber_id' => $barbers->first()->id,
            'registered_by' => $admin->id,
            'amount' => 20000,
            'paid_at' => now()->subDays(2)->toDateString(),
            'notes' => 'Abono de comisión',
        ]);

        $appointmentServices = $services->take(3);

        foreach ($barbers->where('attention_mode', 'citas') as $barber) {
            foreach ([now()->toDateString(), now()->addDay()->toDateString()] as $day) {
                Appointment::create([
                    'barber_id' => $barber->id,
                    'service_id' => $appointmentServices->random()->id,
                    'client_name' => $clientNames[array_rand($clientNames)],
                    'client_phone' => '300 555 5555',
                    'appointment_date' => $day,
                    'appointment_time' => sprintf('%02d:00', rand(10, 18)),
                    'status' => $day === now()->toDateString() ? 'confirmada' : 'pendiente',
                ]);
            }
        }
    }
}
