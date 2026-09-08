<?php

namespace App;

enum ProjectStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
    case Archived = 'archived';
}
