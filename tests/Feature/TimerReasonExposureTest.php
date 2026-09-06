<?php

namespace Tests\Feature;

use App\Enums\DeliveryOrderResult;
use App\Enums\OrderResultReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accepting a reason is not offering it (CONTRACT §3.21.5, §13.6 — v5.2).
 *
 * v5.2 added `follow_up_timer_opened` and `follow_up_timer_expired` to this
 * system's `OrderResultReason` so the inbound receiver can store what Masar
 * legitimately announces. That is the whole of the change, and the hazard it
 * creates is a quiet one: enums have a way of becoming dropdowns. A Filament
 * select built from `OrderResultReason::cases()`, or a local request validated
 * with `Rule::enum(...)`, would silently put both codes in front of an operator
 * of *this* company — offering them the choice of declaring that a courier in
 * Masar opened a follow-up timer, which is not a thing anyone here can do or
 * know.
 *
 * Nothing does that today, and this file is what keeps it that way. It is an
 * architectural assertion rather than a behavioural one, in the manner of
 * Masar's own caller inventories: it pins *where* the enum is allowed to be
 * used, so that adding a twelfth usage is a decision someone makes on purpose
 * rather than a side effect of reaching for the nearest list.
 *
 * The distinction is easy to state and easy to lose: this company's own result
 * vocabulary is `DeliveryOrderResult`, which is binary and says nothing about
 * reasons. `OrderResultReason` is Masar's vocabulary, mirrored.
 */
class TimerReasonExposureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The reason enum is referenced by the inbound receiver, and by nothing
     * else.
     *
     * A new entry appearing here is not automatically wrong — but it is a place
     * where Masar's vocabulary has reached a new part of this system, and
     * whoever adds it has to say why. If that place offers a choice to a person,
     * it must offer a filtered subset and not `cases()`.
     */
    public function test_the_masar_reason_vocabulary_is_used_only_by_the_inbound_receiver(): void
    {
        $users = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (str_contains($contents, 'OrderResultReason')) {
                $users[] = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
            }
        }

        sort($users);

        $this->assertSame([
            // The enum itself.
            'Enums/OrderResultReason.php',
            // And the one place it is read: the receiver's validation of an
            // announcement from Masar (§3.21.5).
            'Http/Requests/Integration/ReceiveMasarEventRequest.php',
        ], $users);
    }

    /**
     * No user-facing surface names either timer reason.
     *
     * Read across the whole presentation layer rather than asserted class by
     * class, because the failure being guarded against is someone adding a
     * *new* screen, not editing an existing one.
     */
    public function test_no_presentation_surface_mentions_a_timer_reason(): void
    {
        $found = [];

        foreach ([app_path('Filament'), resource_path('views')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (str_contains($contents, 'follow_up_timer_') || str_contains($contents, 'OrderResultReason')) {
                    $found[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $found, 'A timer reason reached a user-facing surface.');
    }

    /**
     * The two vocabularies are separate, and this company's own is untouched.
     *
     * `DeliveryOrderResult` is what an operator here actually chooses when they
     * close an order. It is binary and has no notion of a reason at all, which
     * is exactly why Masar's twelve codes cannot leak into it — but the
     * assertion is cheap and the consequence of the two ever merging is not.
     */
    public function test_the_local_result_vocabulary_is_unchanged_and_separate(): void
    {
        $this->assertSame(
            ['delivered', 'not_delivered'],
            array_column(DeliveryOrderResult::cases(), 'value'),
        );

        foreach (DeliveryOrderResult::cases() as $result) {
            $this->assertStringNotContainsString('follow_up_timer', $result->value);
        }

        // And the mirrored vocabulary does hold both, because the receiver needs
        // them — acceptance and selection are different questions.
        $this->assertContains('follow_up_timer_opened', OrderResultReason::codes());
        $this->assertContains('follow_up_timer_expired', OrderResultReason::codes());
    }
}
