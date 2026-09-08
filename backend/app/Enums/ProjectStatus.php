<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case Archived = 'archived';
}
