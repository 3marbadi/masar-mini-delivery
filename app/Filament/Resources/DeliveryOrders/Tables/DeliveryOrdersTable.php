<?php

namespace App\Filament\Resources\DeliveryOrders\Tables;

use App\Enums\AdministrativeState;
use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\OperationalStatus;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Support\OrderStatusPresenter;
use App\Models\DeliveryOrder;
use App\Services\OperationalStatusProjection;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The orders list, showing both of an order's state dimensions.
 *
 * **Why the old columns stay.** `status` and `result` are this company's own
 * lifecycle, they are what every historical figure is computed from, and they
 * are not replaced here — they are joined by the operational status Masar's
 * channels produce. An operator can now see that an order is cancelled in this
 * system *and* delivered according to Masar, which is a real and alarming state
 * the previous table could not express at all.
 *
 * **Why every filter and sort goes through SQL.** The badge is rendered in PHP
 * and the filter runs in the database, and the two must agree — so both come
 * from `OperationalStatusProjection`, which renders its one rule table into
 * both. Filtering in PHP over the loaded page would have been simpler and
 * wrong: it would quietly search a page instead of a table, and it would
 * disagree with the dashboard's counts.
 */
class DeliveryOrdersTable
{
    public static function configure(Table $table): Table
    {
        [$operationalSql, $operationalBindings] = OperationalStatusProjection::operationalSql();
        [$administrativeSql, $administrativeBindings] = OperationalStatusProjection::administrativeSql();
        [$conflictSql, $conflictBindings] = OperationalStatusProjection::conflictSql();

        return $table
            // The two relations the rows display. Without this each row would
            // fetch its own customer and representative — the N+1 that turns a
            // page of twenty-five into fifty-one queries. The presenter itself
            // reads plain columns and loads nothing further.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'representative']))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable(),
                TextColumn::make('customer.phone')
                    ->label('هاتف العميل')
                    ->searchable(),
                TextColumn::make('representative.name')
                    ->label('المندوب')
                    ->placeholder('غير مسند')
                    ->searchable(),
                TextColumn::make('value')
                    ->label('القيمة')
                    ->money('LYD')
                    ->sortable(),

                // The operational dimension — the six states, derived.
                //
                // Sorted by the compiled expression rather than by a column,
                // because there is no column: the state is a projection of
                // several. `id` breaks ties, so a page boundary is stable and
                // pagination cannot show a row twice or skip one.
                TextColumn::make('operational_status')
                    ->label('الحالة التشغيلية')
                    ->badge()
                    ->state(fn (DeliveryOrder $record): string => OrderStatusPresenter::operationalLabel($record))
                    ->color(fn (DeliveryOrder $record): string => OrderStatusPresenter::operationalColor($record))
                    ->description(fn (DeliveryOrder $record): ?string => self::noteLines($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("({$operationalSql}) {$direction}", $operationalBindings)
                        ->orderBy('delivery_orders.id')),

                // The administrative dimension — this company's own verdict.
                // Shown only when it says something: `open` adds nothing the
                // operational badge has not already said.
                TextColumn::make('administrative_state')
                    ->label('الحالة الإدارية')
                    ->badge()
                    ->state(function (DeliveryOrder $record): ?string {
                        $state = OperationalStatusProjection::administrativeFor($record);

                        return $state->dominatesDisplay() ? $state->label() : null;
                    })
                    ->color(fn (DeliveryOrder $record): string => OperationalStatusProjection::administrativeFor($record)->color())
                    ->placeholder('—')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByRaw("({$administrativeSql}) {$direction}", $administrativeBindings)
                        ->orderBy('delivery_orders.id')),

                // Masar's standing result, as its own fact. §5 forbids hiding a
                // delivery Masar announced merely because this company closed
                // the order some other way.
                TextColumn::make('masar_result')
                    ->label('نتيجة مَسار')
                    ->state(fn (DeliveryOrder $record): ?string => OrderStatusPresenter::masarResultLabel($record))
                    ->placeholder('—')
                    ->toggleable(),

                // The company's own lifecycle columns, unchanged and still
                // present: every historical figure is computed from them.
                TextColumn::make('status')
                    ->label('حالة النظام المحلي')
                    ->badge()
                    ->formatStateUsing(fn (DeliveryOrderStatus $state): string => match ($state) {
                        DeliveryOrderStatus::NewOrder => 'جديد',
                        DeliveryOrderStatus::Assigned => 'مُسند',
                        DeliveryOrderStatus::Completed => 'مكتمل',
                        DeliveryOrderStatus::Cancelled => 'ملغي',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('result')
                    ->label('النتيجة المحلية')
                    ->badge()
                    ->formatStateUsing(fn (?DeliveryOrderResult $state): string => match ($state) {
                        DeliveryOrderResult::Delivered => 'تم التسليم',
                        DeliveryOrderResult::NotDelivered => 'لم يتم التسليم',
                        null => '—',
                    })
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('operational_status')
                    ->label('الحالة التشغيلية')
                    ->options(OperationalStatus::options())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereRaw(
                            "({$operationalSql}) = ?",
                            array_merge($operationalBindings, [$data['value']]),
                        )),

                SelectFilter::make('administrative_state')
                    ->label('الحالة الإدارية')
                    ->options(AdministrativeState::options())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : $query->whereRaw(
                            "({$administrativeSql}) = ?",
                            array_merge($administrativeBindings, [$data['value']]),
                        )),

                TernaryFilter::make('has_conflict')
                    ->label('تعارض بين المصدرين')
                    ->placeholder('الكل')
                    ->trueLabel('المتعارضة فقط')
                    ->falseLabel('غير المتعارضة فقط')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereRaw("({$conflictSql}) = '1'", $conflictBindings),
                        false: fn (Builder $query): Builder => $query->whereRaw("({$conflictSql}) = '0'", $conflictBindings),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                // The pre-existing filters, kept so anything an operator
                // already relied on still works.
                SelectFilter::make('status')
                    ->label('حالة النظام المحلي')
                    ->options([
                        DeliveryOrderStatus::NewOrder->value => 'جديد',
                        DeliveryOrderStatus::Assigned->value => 'مُسند',
                        DeliveryOrderStatus::Completed->value => 'مكتمل',
                        DeliveryOrderStatus::Cancelled->value => 'ملغي',
                    ]),
                SelectFilter::make('result')
                    ->label('النتيجة المحلية')
                    ->options([
                        DeliveryOrderResult::Delivered->value => 'تم التسليم',
                        DeliveryOrderResult::NotDelivered->value => 'لم يتم التسليم',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeliveryOrderResource::assignRepresentativeAction(),
                DeliveryOrderResource::reassignRepresentativeAction(),
                DeliveryOrderResource::completeAction(),
                DeliveryOrderResource::cancelAction(),
            ])
            ->toolbarActions([]);
    }

    /**
     * The diagnostic notes, under the operational badge.
     *
     * Joined into one line in the presenter's order, and null when there is
     * nothing to say — which is the ordinary case and should cost the row no
     * extra height.
     */
    private static function noteLines(DeliveryOrder $record): ?string
    {
        $notes = OrderStatusPresenter::notes($record);

        if ($notes === []) {
            return null;
        }

        return implode(' · ', array_column($notes, 'text'));
    }
}
