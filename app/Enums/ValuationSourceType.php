<?php

namespace App\Enums;

/**
 * Kind of price evidence: new retail, an active (asking) listing, or a completed sale.
 */
enum ValuationSourceType: string
{
    case Retail = 'retail';
    case Active = 'active';
    case Sold = 'sold';
}
