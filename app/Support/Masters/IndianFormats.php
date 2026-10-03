<?php

namespace App\Support\Masters;

final class IndianFormats
{
    public const GSTIN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public const PAN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public const IFSC = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    public const MOBILE = '/^(\+91[\-\s]?)?[6-9][0-9]{9}$/';

    public const PINCODE = '/^[1-9][0-9]{5}$/';

    public const HSN_SAC = '/^[0-9]{4,8}$/';
}
