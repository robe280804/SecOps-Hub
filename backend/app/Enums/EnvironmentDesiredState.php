<?php

namespace App\Enums;

enum EnvironmentDesiredState: string
{
    case Stopped = 'stopped';
    case Running = 'running';
    case Deleted = 'deleted';
}
