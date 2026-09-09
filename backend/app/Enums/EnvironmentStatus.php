<?php

namespace App\Enums;

enum EnvironmentStatus: string
{
    case Inactive = 'inactive';
    case Provisioning = 'provisioning';
    case Stopped = 'stopped';
    case Starting = 'starting';
    case Ready = 'ready';
    case Stopping = 'stopping';
    case Error = 'error';
    case Deleting = 'deleting';
}
