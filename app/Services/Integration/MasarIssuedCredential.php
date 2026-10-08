<?php

namespace App\Services\Integration;

use App\Enums\MasarCredentialState;
use Carbon\CarbonImmutable;
use JsonSerializable;
use LogicException;

/**
 * The one copy of a password Masar has just issued (CONTRACT §13.28.10).
 *
 * Returned only by a genuinely applied create or rotation. Its counterpart is
 * MasarReplayedCredential, which describes the same credential **without a
 * password property at all** — the pair is what makes §13.28.11's "a replay
 * returns no secret" structural rather than careful. A single result type with a
 * nullable password would leave that promise to whoever writes the response, one
 * `?? ''` away from being broken, and broken silently.
 *
 * **The plaintext lives for one request.** §13.28.10 says it exists in Masar's
 * response body and in the single Mini request that handles it, and «ينعدم
 * بانعدام ذلك الطلب». So this object has to be hostile to every mechanism that
 * would outlive the request:
 *
 *   - `__serialize()` and `__sleep()` throw. PHP's `serialize()` is how the cache,
 *     the session and the queue store an object, so an accidental `Cache::put()`,
 *     `session()->put()` or `dispatch()` fails loudly instead of writing a secret
 *     to the `cache` or `sessions` table — which it would, since this application
 *     is configured for database-backed cache and sessions;
 *   - it is not an Eloquent model, has no `toArray()` and no `jsonSerialize()`, so
 *     nothing hands it to a database column or a JSON column by habit;
 *   - `__debugInfo()` redacts, so a `dd()` or a `Log::debug()` of the object
 *     reaching a developer's screen or an exception context does not carry the
 *     password with it;
 *   - `jsonSerialize()` redacts as well, and that one closes a path the others do
 *     not. A readonly class with public properties is JSON-encodable by default,
 *     so `json_encode($credential)` — which is what a log handler does to its
 *     context, and what a JSON column write does to a value — would otherwise
 *     print the password without anything failing. Redacted rather than refused,
 *     because the realistic caller here is a log line, and a log line that throws
 *     turns a careless diagnostic into an outage.
 *
 * None of those guards is the plan — the plan is that the controller reads
 * `$credential->password` once and lets the request end. They are there because a
 * guarantee that depends on nobody ever writing one careless line is not a
 * guarantee.
 */
final readonly class MasarIssuedCredential implements JsonSerializable
{
    public function __construct(
        public string $loginName,
        /** The one and only plaintext copy. Shown once, stored nowhere. */
        public string $password,
        public MasarCredentialState $state,
        public ?CarbonImmutable $credentialsUpdatedAt,
    ) {}

    /**
     * Refuses to be serialized.
     *
     * The message names no value, so the refusal itself cannot leak what it was
     * protecting.
     */
    public function __serialize(): array
    {
        throw new LogicException(
            'A Masar issued credential must not be serialized: the plaintext password lives for one '
            .'request and is never written to a cache, a session, a queue payload or a column '
            .'(CONTRACT §13.28.10).'
        );
    }

    /** The older serialization hook, closed for the same reason. */
    public function __sleep(): array
    {
        throw new LogicException(
            'A Masar issued credential must not be serialized (CONTRACT §13.28.10).'
        );
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return $this->redacted();
    }

    /**
     * The shape any JSON encoding sees, with the one value it must not carry
     * replaced rather than omitted — an absent key would read as "there was no
     * password", which is a different and untrue statement.
     *
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->redacted();
    }

    /** @return array<string, string> */
    private function redacted(): array
    {
        return [
            'loginName' => $this->loginName,
            'password' => '[redacted]',
            'state' => $this->state->value,
            'credentialsUpdatedAt' => (string) $this->credentialsUpdatedAt,
        ];
    }
}
