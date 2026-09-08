<?php

namespace App\Enums;

enum ProjectAccessLevel: string
{
    case Viewer = 'viewer';
    case Contributor = 'contributor';
}
