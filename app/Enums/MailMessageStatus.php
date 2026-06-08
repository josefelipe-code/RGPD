<?php

namespace App\Enums;

enum MailMessageStatus: string
{
    case New = 'new';
    case Associated = 'associated';
    case RepliedClient = 'replied_client';
    case RepliedProvider = 'replied_provider';
    case PendingReview = 'pending_review';
    case Discarded = 'discarded';
}
