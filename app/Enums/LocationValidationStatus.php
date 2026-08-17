<?php

namespace App\Enums;

enum LocationValidationStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
}
