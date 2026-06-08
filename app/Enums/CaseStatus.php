<?php

namespace App\Enums;

enum CaseStatus: string
{
    case PendingClient = 'pending_client';
    case PendingProvider = 'pending_provider';
    case Concluded = 'concluded';
}
