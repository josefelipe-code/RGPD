<?php

namespace App\Enums;

enum MailDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
}
