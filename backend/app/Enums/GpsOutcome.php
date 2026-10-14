<?php

namespace App\Enums;

enum GpsOutcome: string
{
    case Valid = 'valid';
    case Warning = 'warning';
    case Suspicious = 'suspicious';
    case Rejected = 'rejected';
}
