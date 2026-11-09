<?php

namespace App\Comms\Models;

enum ModelAnswerKind: string
{
    case Accepted = 'accepted';
    case Question = 'question';
    case NoDraft = 'no_draft';
}
