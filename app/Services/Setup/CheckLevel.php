<?php

namespace App\Services\Setup;

enum CheckLevel: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';
}
