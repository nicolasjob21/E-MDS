<?php

/*
 * The constants printed on the ACIC form.
 *
 * These are properties of the office and its depository bank, not of any one ACIC, so they live
 * here rather than in the database — the printed form has to match the pre-agreed layout the
 * bank accepts, and these values change roughly never. Defaults are the values on the reference
 * form; override any of them in `.env`.
 */
return [
    'bank' => [
        'name' => env('ACIC_BANK_NAME', 'LANDBANK OF THE PHILIPPINES'),
        'branch' => env('ACIC_BANK_BRANCH', 'SOUTH HARBOR BRANCH'),
        'address' => env('ACIC_BANK_ADDRESS', 'MARSMAN BUILDING SOUTH HARBOR PORT AREA MANILA'),
    ],

    'agency' => [
        'name' => env('ACIC_AGENCY_NAME', 'PHILIPPINE COAST GUARD'),
        'address' => env('ACIC_AGENCY_ADDRESS', '139 25TH ST PORT AREA MANILA'),
    ],

    // The codes in the top-right block, printed verbatim.
    'org_code' => env('ACIC_ORG_CODE', '380060300016'),
    'funding_source' => env('ACIC_FUNDING_SOURCE', '01101101'),
    'area_code' => env('ACIC_AREA_CODE', '0010'),
    'allocation_no' => env('ACIC_ALLOCATION_NO', '001310-5'),
    'account_no' => env('ACIC_ACCOUNT_NO', '2028-9010-73'),

    // Whoever signs the form. Printed under the signature rules.
    'certified_by' => env('ACIC_CERTIFIED_BY', ''),
    'approved_by' => env('ACIC_APPROVED_BY', ''),

    // The transmittal file named at the foot of the form: "<prefix> <mm>-<seq>.txt".
    'filename_prefix' => env('ACIC_FILENAME_PREFIX', 'F:\\PCGLDDAP'),
];
