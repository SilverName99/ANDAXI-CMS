<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress;

use RuntimeException;

/** O oprire a importului, cu un mesaj pentru om (fără parole: ConfigWordPress::fara). */
final class EroareImport extends RuntimeException
{
}
