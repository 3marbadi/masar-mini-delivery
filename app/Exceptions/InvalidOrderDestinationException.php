<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A destination that may not be written to an order.
 *
 * Raised at the model boundary, which is to say from *every* path that saves an
 * order — the admin form, a service, a console command, a future importer — and
 * not from the one page that happens to exist today. The Filament form repeats
 * these rules as field validation so an operator gets a message next to the
 * input rather than an exception; this is what guarantees the rule when that
 * form is bypassed, and the two must agree.
 *
 * It covers four refusals, all of them about a destination being *chosen*:
 * a region that belongs to another city, a missing region for a city that
 * demands one, a withdrawn city or region selected afresh, and a destination
 * changed on an order whose assignment Masar has already been told about.
 */
class InvalidOrderDestinationException extends RuntimeException {}
