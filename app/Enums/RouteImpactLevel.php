<?php

namespace App\Enums;

enum RouteImpactLevel: string
{
    case None = 'none';
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Major = 'major';
}
