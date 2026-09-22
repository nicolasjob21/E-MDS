<?php

namespace App\Enums;

/**
 * What an LDDAP-ADA pays for. Printed in caps, as the disbursement forms carry it.
 */
enum NatureOfPayment: string
{
    case PayrollPersonalClaims = 'payroll_personal_claims';
    case ForeignTravel = 'foreign_travel';
    case LocalTravel = 'local_travel';
    case PrePayment = 'pre_payment';
    case CashAdvance = 'cash_advance';
    case Reimbursement = 'reimbursement';
    case CommercialClaims = 'commercial_claims';
    case Rental = 'rental';
    case Remittances = 'remittances';
    case ElectricBill = 'electric_bill';
    case Pol = 'pol';
    case Retirement = 'retirement';
    case Honoraria = 'honoraria';
    case TrustFund = 'trust_fund';

    public function label(): string
    {
        return match ($this) {
            self::PayrollPersonalClaims => 'PAYROLL / PERSONAL CLAIMS',
            self::ForeignTravel => 'FOREIGN TRAVEL',
            self::LocalTravel => 'LOCAL TRAVEL',
            self::PrePayment => 'PRE-PAYMENT (ALL TYPES)',
            self::CashAdvance => 'CASH ADVANCE',
            self::Reimbursement => 'REIMBURSEMENT',
            self::CommercialClaims => 'COMMERCIAL CLAIMS',
            self::Rental => 'RENTAL',
            self::Remittances => 'REMITTANCES',
            self::ElectricBill => 'ELECTRIC BILL',
            self::Pol => 'POL',
            self::Retirement => 'RETIREMENT',
            self::Honoraria => 'HONORARIA',
            self::TrustFund => 'TRUST FUND',
        };
    }

    /**
     * The options a select offers, in form order.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
