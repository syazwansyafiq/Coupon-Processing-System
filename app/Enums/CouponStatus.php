<?php

namespace App\Enums;

enum CouponStatus: string
{
    case Processing = 'processing';
    case Reserved   = 'reserved';
    case Consumed   = 'consumed';
    case Released   = 'released';
    case Failed     = 'failed';
    case Error      = 'error';
    case NotFound   = 'not_found';
}
