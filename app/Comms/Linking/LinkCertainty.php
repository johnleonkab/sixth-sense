<?php

namespace App\Comms\Linking;

enum LinkCertainty: string
{
    /** All three runs chose the same target. */
    case Certain = 'certain';

    /** Placed on the most likely target and marked wherever it is used (D3). */
    case Uncertain = 'uncertain';
}
