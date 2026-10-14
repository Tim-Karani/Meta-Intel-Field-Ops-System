<?php

namespace App\Enums;

enum QuestionType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Currency = 'currency';
    case YesNo = 'yes_no';
    case SingleSelect = 'single_select';
    case MultiSelect = 'multi_select';
    case Dropdown = 'dropdown';
    case DateTime = 'date_time';
    case Rating = 'rating';
    case Percentage = 'percentage';
    case Product = 'product';
    case Gps = 'gps';
    case Photo = 'photo';
    case Signature = 'signature';
    case Barcode = 'barcode';

    public function hasOptions(): bool
    {
        return in_array($this, [self::SingleSelect, self::MultiSelect, self::Dropdown], true);
    }

    /** Photo-like answers are uploaded as files, not stored as response values. */
    public function isEvidence(): bool
    {
        return in_array($this, [self::Photo, self::Signature], true);
    }
}
