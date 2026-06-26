<?php

namespace App\Enums;

enum MilestoneAction: string
{
    case Opened = 'opened';
    case RepliedClient = 'replied_client';
    case RepliedProvider = 'replied_provider';
    case Closed = 'closed';
    case Reopened = 'reopened';
}
