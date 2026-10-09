<?php

namespace App\Filament\Resources\DeliveryOrders\Schemas;

use App\Models\Customer;
use App\Models\DeliveryCity;
use App\Models\DeliveryOrder;
use App\Models\DeliveryRegion;
use App\Rules\RegionBelongsToSelectedCity;
use App\Services\Catalog\DeliveryDestinationService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DeliveryOrderForm
{
    /** Shown wherever a city has no decided price (PLAN §5.2.5). */
    public const UNDETERMINED_PRICE = 'السعر غير محدد';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->label('العميل')
                    ->relationship(
                        'customer',
                        'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Customer $record): string => $record->name.' — '.$record->phone)
                    ->searchable(['name', 'phone'])
                    ->preload()
                    ->required()
                    ->visibleOn('create'),
                TextInput::make('customer.name')
                    ->label('العميل')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                TextInput::make('value')
                    ->label('القيمة')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01),

                // ---- The destination (D2) ----

                // Required on create, and in the form rather than in the model.
                // This is human entry, and the split follows `LibyanMobileNumber`:
                // a form decides what a person must type, while the model decides
                // what may be *stored*. A model-level requirement would have
                // demanded a city of every fixture, service and importer in the
                // system — and of the historic orders that legitimately have none.
                Select::make('city_id')
                    ->label('المدينة')
                    ->options(fn (?DeliveryOrder $record): array => DeliveryCity::query()
                        ->selectableForDeliveryOrCurrent($record?->city_id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required(fn (?DeliveryOrder $record): bool => $record === null)
                    // Reloads the region list and the fee preview. The region is
                    // cleared on every city change without exception (PLAN
                    // §5.2.2): keeping it would leave a region of the previous
                    // city sitting under the new one, which the save path would
                    // then refuse — correctly, but with the operator left to work
                    // out why.
                    ->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('region_id', null))
                    ->disabled(fn (?DeliveryOrder $record): bool => static::isSynchronised($record))
                    ->helperText(fn (?DeliveryOrder $record): ?string => static::isSynchronised($record)
                        ? 'تم إبلاغ مَسار بهذا الطلب، ولا يمكن تغيير وجهته في هذه المرحلة.'
                        : null),

                Select::make('region_id')
                    ->label('المنطقة')
                    ->options(fn (Get $get, ?DeliveryOrder $record): array => static::regionOptions(
                        $get('city_id'),
                        $record?->region_id,
                    ))
                    ->searchable()
                    ->preload()
                    // Required exactly where the catalog says so, read live from
                    // the selected city rather than from a list of city names:
                    // the nine cities that demand a region are data, not code
                    // (PLAN §5.2.4).
                    ->required(fn (Get $get): bool => static::requiresRegion($get('city_id')))
                    // A city with no regions at all hides the field rather than
                    // showing an empty one. A city with *optional* regions still
                    // shows it, so one can be chosen (PLAN §5.2.4).
                    ->visible(fn (Get $get, ?DeliveryOrder $record): bool => static::regionOptions(
                        $get('city_id'),
                        $record?->region_id,
                    ) !== [])
                    ->rules(fn (Get $get): array => [new RegionBelongsToSelectedCity($get('city_id'))])
                    ->disabled(fn (?DeliveryOrder $record): bool => static::isSynchronised($record)),

                // Display only, and never dehydrated — so no fee is ever
                // submitted by the client, and there is nothing for a re-enabled
                // field to forge. The stored value is read from the catalog at
                // save time by `DeliveryDestinationService`; this is a preview of
                // what that will produce (PLAN §5.1).
                Placeholder::make('delivery_fee_preview')
                    ->label('سعر التوصيل')
                    ->content(fn (Get $get): string => static::feeLabel($get('city_id')))
                    ->visible(fn (Get $get): bool => filled($get('city_id'))),

                // Appears only when the chosen city differs from the stored one,
                // which is the moment a save would reprice the order. The
                // repricing still happens only on save — nothing is written while
                // the form is merely open or reopened (PLAN §5.2.7).
                Placeholder::make('delivery_fee_reprice_notice')
                    ->label('تنبيه')
                    ->content(fn (Get $get, ?DeliveryOrder $record): string => sprintf(
                        'تم تغيير المدينة. سيُحدَّث سعر التوصيل من %s إلى %s عند تأكيد الحفظ.',
                        static::amountLabel($record?->delivery_fee_lyd),
                        static::feeLabel($get('city_id')),
                    ))
                    ->visible(fn (Get $get, ?DeliveryOrder $record): bool => $record !== null
                        && ! static::isSynchronised($record)
                        && filled($get('city_id'))
                        && (int) $get('city_id') !== (int) $record->city_id),

                // The operator's only location input. Coordinates are deliberately
                // absent: this company owns the link, Masar owns turning it into a
                // position, and Masar announces the result back onto this order
                // through the location channel (§3.7, D13). So `latitude` and
                // `longitude` are values this side *receives*, never values an
                // operator is asked to look up by hand.
                //
                // They stay on the table and in `$fillable` for that same reason.
                // Removing these two inputs removes a data-entry task, not a field:
                // `MasarLocationWriter` still writes both, and the view page still
                // shows them.
                //
                // Untouched by D2, and that is a rule rather than an omission: the
                // city is administrative and the link is geographic, and choosing
                // one must never move the other (PLAN §1, §5.2.6).
                TextInput::make('location_link')
                    ->label('رابط الموقع')
                    ->maxLength(255),
            ]);
    }

    /**
     * The regions offered for a city, keyed by internal id.
     *
     * Empty for no city, and for a city with no regions — which is what the
     * field's own visibility is decided by.
     *
     * @return array<int, string>
     */
    private static function regionOptions(mixed $cityId, ?int $currentRegionId): array
    {
        if (blank($cityId)) {
            return [];
        }

        return DeliveryRegion::query()
            ->selectableForCity((int) $cityId, $currentRegionId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function requiresRegion(mixed $cityId): bool
    {
        if (blank($cityId)) {
            return false;
        }

        return (bool) DeliveryCity::query()->whereKey((int) $cityId)->value('is_region_required');
    }

    /**
     * The fee a city would charge, as a label.
     *
     * «السعر غير محدد» for the four unpriced cities, and emphatically not
     * `0.00 د.ل`: an operator shown a zero would reasonably read it as free
     * delivery, which is the one thing those four are not (PLAN §4.3).
     */
    private static function feeLabel(mixed $cityId): string
    {
        return static::amountLabel(
            app(DeliveryDestinationService::class)->previewFee(blank($cityId) ? null : (int) $cityId),
        );
    }

    private static function amountLabel(?string $amount): string
    {
        return $amount === null ? self::UNDETERMINED_PRICE : $amount.' د.ل';
    }

    /**
     * Whether Masar already holds this order's destination.
     *
     * Disables the two selects, and the save path refuses the change as well —
     * a disabled field is a courtesy to the operator, not a boundary (PLAN §6).
     * D3 lifts both halves together.
     */
    private static function isSynchronised(?DeliveryOrder $record): bool
    {
        return $record !== null && $record->hasBeenAnnouncedToMasar();
    }
}
