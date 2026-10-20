<?php

declare(strict_types=1);

namespace MklUebernahme;

/** Eine Zeile wird nicht übernommen; die Meldung kommt in den Bericht. */
final class SkipRow extends \RuntimeException
{
}
