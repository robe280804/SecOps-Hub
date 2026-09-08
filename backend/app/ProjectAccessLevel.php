<?php

namespace App;

enum ProjectAccessLevel: string
{
    case Viewer = 'viewer';
    case Contributor = 'contributor';
}
