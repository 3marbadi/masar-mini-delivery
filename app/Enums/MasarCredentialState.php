<?php

namespace App\Enums;

/**
 * The state of a courier's Masar login, as Masar reports it (CONTRACT §13.28.5).
 *
 * Three values and there is no fourth: Masar's own lexicon is closed at
 * `none` · `active` · `retired`, so an unrecognised string on the wire is a
 * contract violation rather than a value to carry forward
 * (MasarCredentialClient refuses it).
 *
 * An enum rather than the raw string, because the three are read at a distance
 * from where they arrive — the admin page decides what to show from them — and a
 * typo in a comparison against `'retierd'` would be a silently empty branch
 * rather than a failure.
 *
 * `Retired` deliberately does not mean "has a credential". A courier with
 * history and nothing live in it cannot log in, so §13.28.5 reports
 * `has_credential = false` beside it; the name is still carried, because it is
 * the name a reset will hand back to them.
 */
enum MasarCredentialState: string
{
    case None = 'none';
    case Active = 'active';
    case Retired = 'retired';
}
