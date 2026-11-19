<?php

declare(strict_types=1);

namespace App\Support\CadruCapturat;

/** Un jeton `{{ax:…}}` necunoscut sau rămas după înlocuire (Jetoane::rezolva): cadrul răspunde 500 și scrie în jurnal. */
final class EroareJeton extends \RuntimeException
{
}
