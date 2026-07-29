<?php

namespace App\Enums;

enum MilestoneAction: string
{
    case Opened = 'opened';
    case RepliedClient = 'replied_client';
    case RepliedProvider = 'replied_provider';
    case PhoneValidated = 'phone_validated';
    case ProviderConfirmed = 'provider_confirmed';
    case ClientFingerprintSent = 'client_fingerprint_sent';
    case Closed = 'closed';
    case Reopened = 'reopened';
}
