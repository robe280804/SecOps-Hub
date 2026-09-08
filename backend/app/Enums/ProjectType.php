<?php

namespace App\Enums;

enum ProjectType: string
{
    case BugBounty = 'bug_bounty';
    case Personal = 'personal';
    case Company = 'company';
    case Ctf = 'ctf';
}
