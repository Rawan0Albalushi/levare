<?php

namespace App;

enum CouplingEnd: string
{
    case Pump = 'pump';
    case Vapro = 'vapro';
    case Gs = 'gs';
    case Intake = 'intake';
    case Seal = 'seal';
    case Motor = 'motor';
}
