<?php

namespace App\Enums;

enum AddressType: string
{
    case Present = 'present';
    case Previous = 'previous';
    case Parents = 'parents';
    case Residence = 'residence';
    case Business = 'business';
    case Other = 'other';
}
