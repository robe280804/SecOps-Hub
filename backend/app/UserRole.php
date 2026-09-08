<?php

namespace App;

enum UserRole: string
{
    case Admin = 'admin';
    case Member = 'member';
}
