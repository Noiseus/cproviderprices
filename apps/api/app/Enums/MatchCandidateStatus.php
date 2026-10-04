<?php

namespace App\Enums;

enum MatchCandidateStatus: string
{
    case Pending = 'pending';
    case AutoPublished = 'auto_published';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}
