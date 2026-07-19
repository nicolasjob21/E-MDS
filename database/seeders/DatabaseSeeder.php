<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ChequeService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Default administrator. Override via env for real deployments.
        $admin = User::updateOrCreate(
            ['username' => env('SEED_ADMIN_USERNAME', 'admin')],
            [
                'name' => 'System Administrator',
                'password' => env('SEED_ADMIN_PASSWORD', 'password'),
                'role' => UserRole::Admin,
                'is_active' => true,
            ],
        );

        // A sample staff account for testing the non-admin experience.
        User::updateOrCreate(
            ['username' => 'staff'],
            [
                'name' => 'Sample Staff',
                'password' => 'password',
                'role' => UserRole::Staff,
                'is_active' => true,
            ],
        );

        // Seed an initial run of cheque numbers (1–500) if none exist yet.
        $cheques = app(ChequeService::class);
        if ($cheques->counts()['total'] === 0) {
            $cheques->addRange($admin, 500, 1);

            // Use the first few so there is sample "used" data and audit history.
            $payees = ['Acme Supplies', 'City Power Co.', 'Metro Rentals', 'J. Rivera', 'Sunrise Catering', 'BlueOcean Logistics', 'Northwind Traders', 'Payroll Run'];
            foreach (range(1, 8) as $i => $number) {
                $used = $cheques->useNext($admin, $number, [
                    'payee_name' => $payees[$i],
                    'amount' => round(mt_rand(50000, 2500000) / 100, 2),
                    'cheque_date' => now()->subDays(8 - $i)->toDateString(),
                ]);

                // Mark the earliest few as already cashed at the bank, for sample encashment data.
                if ($i < 4) {
                    $cheques->cashCheque($admin, $used, [
                        'teller_name' => ['Teller A. Cruz', 'Teller B. Santos', 'Teller C. Reyes', 'Teller D. Lim'][$i],
                        'cashed_at' => now()->subDays(4 - $i)->toDateString(),
                    ]);
                }
            }
        }

        $this->command?->info('Seeded admin (username: '.$admin->username.') and staff (username: staff). Default password: password');
    }
}
