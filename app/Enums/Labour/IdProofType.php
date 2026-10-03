<?php

namespace App\Enums\Labour;

use App\Enums\Concerns\HasOptions;

enum IdProofType: string
{
    use HasOptions;

    case Aadhaar = 'aadhaar';
    case Pan = 'pan';
    case VoterId = 'voter_id';
    case DrivingLicence = 'driving_licence';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Aadhaar => 'Aadhaar',
            self::Pan => 'PAN',
            self::VoterId => 'Voter ID',
            self::DrivingLicence => 'Driving licence',
            self::Other => 'Other',
        };
    }
}
