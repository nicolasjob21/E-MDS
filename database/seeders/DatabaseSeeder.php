<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Cheque;
use App\Models\Payee;
use App\Models\Unit;
use App\Models\User;
use App\Services\AcicService;
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

        // The reference lists an LDDAP is registered against. Sample entries only — replace
        // them with the office's real units and payees.
        foreach (['ACCOUNTING', 'BUDGET', 'CASH', 'ADMINISTRATION', 'LOGISTICS', 'OPERATIONS'] as $unit) {
            Unit::firstOrCreate(['name' => $unit]);
        }

        // A payee may hold several accounts; the first sample does, so the account select and
        // the auto-pick of a lone account both have something to show.
        foreach ([
            'ACME SUPPLIES INC.' => [['1701-0426-18', 'LBP'], ['0451-2210-07', 'DBP']],
            'CITY POWER CO.' => [['2028-9010-12', 'LBP']],
            'METRO RENTALS' => [['2028-9010-13', 'LBP']],
            'J. RIVERA' => [['2028-9010-14', 'LBP']],
            'SUNRISE CATERING' => [['2028-9010-15', 'LBP']],
            'BLUEOCEAN LOGISTICS' => [['2028-9010-16', 'LBP']],
        ] as $name => $accounts) {
            $payee = Payee::firstOrCreate(['name' => $name]);
            foreach ($accounts as [$accountNo, $bank]) {
                $payee->accounts()->firstOrCreate(['account_no' => $accountNo], ['bank' => $bank]);
            }
        }

        // ACIC numbers are a registered series now, so seed a block to draw on.
        $acics = app(AcicService::class);
        if ($acics->series()['registered'] === 0) {
            $acics->addRange($admin, 1, 200);
        }

        // Seed an initial run of cheque numbers (1–500) if none exist yet.
        $cheques = app(ChequeService::class);
        if ($cheques->counts()['total'] === 0) {
            $cheques->addRange($admin, 1, 500);

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
