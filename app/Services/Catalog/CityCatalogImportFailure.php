<?php

namespace App\Services\Catalog;

use RuntimeException;

/**
 * The file itself cannot be imported.
 *
 * Reserved for failures that make the whole run meaningless — the file is
 * missing, unreadable, empty, or its header is not this catalog's header. A bad
 * *row* is never this: it is rejected, counted and reported, and the other 304
 * rows still land. The distinction matters because the two need opposite
 * responses — one is fixed by finding the right file, the other by correcting a
 * line in the file you already have.
 */
final class CityCatalogImportFailure extends RuntimeException {}
