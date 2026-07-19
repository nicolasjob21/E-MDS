<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Cheque;
use App\Models\User;
use App\Services\ChequeService;
use App\Services\UpdateRequestService;
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
        $staff = User::updateOrCreate(
            ['username' => 'staff'],
            [
                'name' => 'Sample Staff',
                'password' => 'password',
                'role' => UserRole::Staff,
                'is_active' => true,
            ],
        );

        // A sample teller account — confirms that used cheques have been received.
        $teller = User::updateOrCreate(
            ['username' => 'teller'],
            [
                'name' => 'Sample Teller',
                'password' => 'password',
                'role' => UserRole::Teller,
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

                // The teller confirms the earliest few as received, for sample receipt data.
                if ($i < 4) {
                    $cheques->confirmReceipt($teller, $used);
                }
            }

            // A sample pending update request, so the admin approvals queue has data.
            $target = Cheque::where('cheque_number', 6)->first();
            app(UpdateRequestService::class)->create(
                $staff,
                $target,
                [
                    'payee_name' => $target->payee_name.' (corrected)',
                    'amount' => $target->amount,
                    'cheque_date' => $target->cheque_date->toDateString(),
                ],
                'The payee name was misspelled on this cheque — please correct it.',
            );
        }

        $this->command?->info('Seeded admin, staff and teller accounts. Default password: password');
    }
}
