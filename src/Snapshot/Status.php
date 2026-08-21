<?php

namespace Ultraviolettes\Constellation\Snapshot;

enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unavailable = 'unavailable';
}
