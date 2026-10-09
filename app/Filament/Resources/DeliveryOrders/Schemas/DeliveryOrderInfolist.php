<?php

namespace App\Filament\Resources\DeliveryOrders\Schemas;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Enums\TourParticipation;
use App\Filament\Support\OrderStatusPresenter;
use App\Models\DeliveryOrder;
use App\Services\OperationalStatusProjection;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One order, with both of its state dimensions and the evidence behind them.
 *
 * Three sections, and the division is the point. The first answers "what is the
 * state of this order", the second "what did Masar actually tell us", and the
 * third holds the company's own data. Until now everything Masar had announced
 * about an order — its result, its reason, its version, its participation in a
 * tour — was stored and shown nowhere at all.
 *
 * **Naming discipline in the execution section.** The two instants the
 * participation channel carries are the moments Masar *announced* a transition,
 * not the moment a courier drove off: D31 carries no instant for the tour's own
 * start. They are therefore labelled as what they are, and `tour_departure_at`
 * is labelled a scheduled time rather than evidence of anything — an order
 * whose departure hour has passed is not thereby in progress, and the
 * projection does not read that column at all.
 */
class DeliveryOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('حالة الطلب')
                    ->description('البعد التشغيلي والبعد الإداري، وكلٌّ منهما مستقل عن الآخر.')
                    ->schema([
                        TextEntry::make('operational_status')
                            ->label('الحالة التشغيلية')
                            ->badge()
                            ->state(fn (DeliveryOrder $record): string => OrderStatusPresenter::operationalLabel($record))
                            ->color(fn (DeliveryOrder $record): string => OrderStatusPresenter::operationalColor($record)),

                        TextEntry::make('administrative_state')
                            ->label('الحالة الإدارية')
                            ->badge()
                            ->state(fn (DeliveryOrder $record): string => OperationalStatusProjection::administrativeFor($record)->label())
                            ->color(fn (DeliveryOrder $record): string => OperationalStatusProjection::administrativeFor($record)->color()),

                        // The diagnostic notes, each a fact with a named cause.
                        // Deliberately not states: a seventh operational value
                        // would stop the six being a partition, and the filters
                        // would stop matching the badges.
                        TextEntry::make('status_notes')
                            ->label('ملاحظات تشخيصية')
                            ->state(function (DeliveryOrder $record): ?string {
                                $notes = OrderStatusPresenter::notes($record);

                                return $notes === [] ? null : implode("\n", array_column($notes, 'text'));
                            })
                            ->placeholder('لا ملاحظات')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('معلومات التنفيذ في مَسار')
                    ->description('ما أعلنه مَسار عن هذا الطلب. هذه الحقول يملكها مَسار ولا يكتبها هذا النظام.')
                    ->schema([
                        TextEntry::make('masar_result')
                            ->label('نتيجة التوصيل من مَسار')
                            ->state(fn (DeliveryOrder $record): ?string => OrderStatusPresenter::masarResultLabel($record))
                            ->placeholder('لم يُعلن مَسار نتيجة بعد'),

                        TextEntry::make('status_reason')
                            ->label('سبب التأجيل أو الرجوع')
                            ->placeholder('—'),

                        TextEntry::make('masar_status_version')
                            ->label('نسخة نتيجة الطلب')
                            ->state(fn (DeliveryOrder $record): string => (string) $record->masar_status_version
                                .((int) $record->masar_status_version === 0 ? ' (لم يتحدث مَسار)' : '')),

                        TextEntry::make('masar_participation')
                            ->label('حالة مشاركة الطلب في الجولة')
                            ->badge()
                            ->state(fn (DeliveryOrder $record): string => match ($record->masar_participation) {
                                TourParticipation::Scheduled => 'جولة مُعدّة بموعد مستقبلي',
                                TourParticipation::Active => 'ضمن جولة بدأت',
                                TourParticipation::Ended => 'انتهت مشاركته في الجولة',
                                default => 'لا مشاركة معلنة',
                            })
                            ->color(fn (DeliveryOrder $record): string => match ($record->masar_participation) {
                                TourParticipation::Active => 'primary',
                                TourParticipation::Scheduled => 'info',
                                default => 'gray',
                            }),

                        TextEntry::make('masar_participation_version')
                            ->label('نسخة المشاركة')
                            ->state(fn (DeliveryOrder $record): string => (string) $record->masar_participation_version),

                        TextEntry::make('participation_validity')
                            ->label('صلاحية المشاركة للإسناد الحالي')
                            ->badge()
                            ->state(function (DeliveryOrder $record): string {
                                if ($record->masar_participation === TourParticipation::None) {
                                    return 'لا ينطبق';
                                }

                                return OperationalStatusProjection::participationValidFor($record)
                                    ? 'صالحة'
                                    : 'أُبطلت بإعادة الإسناد';
                            })
                            ->color(fn (DeliveryOrder $record): string => match (true) {
                                $record->masar_participation === TourParticipation::None => 'gray',
                                OperationalStatusProjection::participationValidFor($record) => 'success',
                                default => 'warning',
                            }),

                        TextEntry::make('participation_courier')
                            ->label('المندوب المرتبط بواقعة المشاركة')
                            ->state(function (DeliveryOrder $record): string {
                                if ($record->masar_participation === TourParticipation::None) {
                                    return '—';
                                }

                                $resolved = $record->masarTourStartedRepresentative;

                                if ($resolved === null) {
                                    $uid = (string) $record->masar_tour_started_courier_uid;

                                    return $uid === ''
                                        ? 'لم يُرسل مَسار هوية مندوب'
                                        : 'غير مربوط محلياً — معرّف مَسار: '.$uid;
                                }

                                return $resolved->name
                                    .(OperationalStatusProjection::participationCourierMatchesFor($record)
                                        ? ' (مطابق للمندوب المسند)'
                                        : ' (يخالف المندوب المسند حالياً)');
                            })
                            ->columnSpanFull(),

                        TextEntry::make('masar_tour_reference')
                            ->label('مرجع الجولة في مَسار')
                            ->placeholder('—'),

                        // Named a *scheduled* time, because that is all it is.
                        // Its hour passing is not evidence that anyone set off,
                        // and nothing in the projection reads it.
                        TextEntry::make('masar_tour_departure_at')
                            ->label('موعد الانطلاق المجدول (ليس دليل بدء)')
                            ->dateTime()
                            ->placeholder('—'),

                        // Named for the announcement, not the departure. D31
                        // carries no instant for `delivery_tours.started_at`.
                        TextEntry::make('masar_participation_started_at')
                            ->label('وقت تسجيل بدء مشاركة الطلب')
                            ->dateTime()
                            ->placeholder('—'),

                        TextEntry::make('masar_participation_ended_at')
                            ->label('وقت تسجيل انتهاء مشاركة الطلب')
                            ->dateTime()
                            ->placeholder('—'),

                        // Age as information, never as a threshold. Tour
                        // closure is synchronised on no channel, so an age is
                        // evidence of silence and nothing more: it cannot show
                        // that a tour ended, and no amount of it turns `active`
                        // into `ended`.
                        TextEntry::make('participation_age')
                            ->label('عمر آخر تحديث مشاركة من مَسار')
                            ->state(fn (DeliveryOrder $record): ?string => OrderStatusPresenter::participationAge($record))
                            ->helperText('معلومة فقط: تأخّر التحديث لا يعني أن الجولة انتهت.')
                            ->placeholder('—'),
                    ])
                    ->columns(2),

                Section::make('بيانات الطلب وشركة التوصيل')
                    ->schema([
                        TextEntry::make('id')->label('رقم الطلب'),
                        TextEntry::make('value')->label('القيمة')->money('LYD'),

                        TextEntry::make('status')
                            ->label('حالة النظام المحلي')
                            ->badge()
                            ->formatStateUsing(fn (DeliveryOrderStatus $state): string => match ($state) {
                                DeliveryOrderStatus::NewOrder => 'جديد',
                                DeliveryOrderStatus::Assigned => 'مُسند',
                                DeliveryOrderStatus::Completed => 'مكتمل',
                                DeliveryOrderStatus::Cancelled => 'ملغي',
                            }),
                        TextEntry::make('result')
                            ->label('النتيجة المحلية')
                            ->badge()
                            ->formatStateUsing(fn (?DeliveryOrderResult $state): string => match ($state) {
                                DeliveryOrderResult::Delivered => 'تم التسليم',
                                DeliveryOrderResult::NotDelivered => 'لم يتم التسليم',
                                null => '—',
                            })
                            ->placeholder('—'),

                        TextEntry::make('customer.name')->label('العميل'),
                        TextEntry::make('customer.phone')->label('هاتف العميل'),

                        // The destination as *this order* recorded it, read from
                        // the order's own snapshot columns and never from the
                        // catalog (D2). An order sent to «طرابلس» keeps saying
                        // «طرابلس» after the catalog row is renamed, and its fee
                        // keeps saying what was charged after the city is
                        // repriced — so this page shows what happened, not what
                        // the price list says today.
                        TextEntry::make('city_name')
                            ->label('المدينة')
                            ->placeholder('—'),
                        TextEntry::make('region_name')
                            ->label('المنطقة')
                            ->placeholder('—'),
                        TextEntry::make('delivery_fee_lyd')
                            ->label('سعر التوصيل')
                            ->money('LYD')
                            // Null is not zero and must not read as free
                            // delivery. An order with a city but no fee is one of
                            // the four unpriced cities and says so in words; an
                            // order with no destination at all is simply blank
                            // (PLAN §4.3).
                            ->placeholder(fn (DeliveryOrder $record): string => $record->city_id === null
                                ? '—'
                                : DeliveryOrderForm::UNDETERMINED_PRICE),

                        TextEntry::make('representative.name')
                            ->label('المندوب المسند')
                            ->placeholder('غير مسند'),
                        TextEntry::make('representative.phone')
                            ->label('هاتف المندوب')
                            ->placeholder('—'),
                        TextEntry::make('assignment_order_version')
                            ->label('نسخة آخر إسناد')
                            ->state(fn (DeliveryOrder $record): string => (string) $record->assignment_order_version),
                        TextEntry::make('location_link')
                            ->label('رابط الموقع')
                            ->url(fn (?string $state): ?string => $state)
                            ->openUrlInNewTab()
                            ->placeholder('—'),
                        TextEntry::make('latitude')->label('خط العرض')->placeholder('—'),
                        TextEntry::make('longitude')->label('خط الطول')->placeholder('—'),
                        TextEntry::make('completed_at')->label('تاريخ الإكمال')->dateTime()->placeholder('—'),
                        TextEntry::make('cancelled_at')->label('تاريخ الإلغاء')->dateTime()->placeholder('—'),
                        TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime()->placeholder('—'),
                        TextEntry::make('updated_at')->label('آخر تحديث')->dateTime()->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }
}
