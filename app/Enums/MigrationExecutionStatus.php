<?php

namespace App\Enums;

enum MigrationExecutionStatus: string
{
    case Pending = 'pending';
    case Applying = 'applying';
    case Applied = 'applied';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case VerificationFailed = 'verification_failed';
    case Failed = 'failed';
}
