<?php

namespace App\Support\Pdf;

use App\Models\Core\Company;

/**
 * Company-level PDF presentation settings (CompanySetting keys). Presentation only.
 */
final class DocumentPdfSettings
{
    public const SIGNATORY_NAME = 'pdf.signatory_name';

    public const SIGNATORY_DESIGNATION = 'pdf.signatory_designation';

    public const FORMER_NAME = 'pdf.former_name';

    public const CIN = 'pdf.cin';

    public const COMPUTER_GENERATED = 'pdf.computer_generated_footer';

    /**
     * @return array{signatory_name: ?string, signatory_designation: ?string, former_name: ?string, cin: ?string, computer_generated: bool}
     */
    public static function for(Company $company): array
    {
        return [
            'signatory_name' => self::string($company, self::SIGNATORY_NAME),
            'signatory_designation' => self::string($company, self::SIGNATORY_DESIGNATION),
            'former_name' => self::string($company, self::FORMER_NAME),
            'cin' => self::string($company, self::CIN),
            'computer_generated' => filter_var(
                $company->setting(self::COMPUTER_GENERATED) ?? true,
                FILTER_VALIDATE_BOOLEAN
            ),
        ];
    }

    private static function string(Company $company, string $key): ?string
    {
        $value = $company->setting($key);
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? trim($value) : (string) $value;
    }
}
