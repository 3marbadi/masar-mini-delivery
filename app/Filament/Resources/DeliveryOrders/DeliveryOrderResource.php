<?php

namespace App\Filament\Resources\DeliveryOrders;

use App\Enums\DeliveryOrderResult;
use App\Enums\DeliveryOrderStatus;
use App\Exceptions\InvalidDeliveryOrderTransitionException;
use App\Filament\Resources\DeliveryOrders\Pages\CreateDeliveryOrder;
use App\Filament\Resources\DeliveryOrders\Pages\EditDeliveryOrder;
use App\Filament\Resources\DeliveryOrders\Pages\ListDeliveryOrders;
use App\Filament\Resources\DeliveryOrders\Pages\ViewDeliveryOrder;
use App\Filament\Resources\DeliveryOrders\Schemas\DeliveryOrderForm;
use App\Filament\Resources\DeliveryOrders\Schemas\DeliveryOrderInfolist;
use App\Filament\Resources\DeliveryOrders\Tables\DeliveryOrdersTable;
use App\Models\DeliveryOrder;
use App\Models\Representative;
use App\Services\DeliveryOrderLifecycleService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DeliveryOrderResource extends Resource
{
    protected static ?string $model = DeliveryOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'الطلبات';

    protected static ?string $modelLabel = 'طلب';

    protected static ?string $pluralModelLabel = 'الطلبات';

    public static function canEdit(Model $record): bool
    {
        return in_array($record->status, [DeliveryOrderStatus::NewOrder, DeliveryOrderStatus::Assigned], true);
    }

    public static function form(Schema $schema): Schema
    {
        return DeliveryOrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeliveryOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeliveryOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function assignRepresentativeAction(): Action
    {
        return static::representativeAction('assignRepresentative', 'إسناد مندوب')
            ->visible(fn (DeliveryOrder $record): bool => $record->status === DeliveryOrderStatus::NewOrder);
    }

    public static function reassignRepresentativeAction(): Action
    {
        return static::representativeAction('reassignRepresentative', 'تغيير المندوب')
            ->visible(fn (DeliveryOrder $record): bool => $record->status === DeliveryOrderStatus::Assigned);
    }

    public static function completeAction(): Action
    {
        return Action::make('completeOrder')
            ->label('تسجيل نتيجة الطلب')
            ->schema([
                Select::make('result')
                    ->label('النتيجة')
                    ->options([
                        DeliveryOrderResult::Delivered->value => 'تم التسليم',
                        DeliveryOrderResult::NotDelivered->value => 'لم يتم التسليم',
                    ])
                    ->required(),
            ])
            ->visible(fn (DeliveryOrder $record): bool => $record->status === DeliveryOrderStatus::Assigned)
            ->action(function (DeliveryOrder $record, array $data): void {
                try {
                    app(DeliveryOrderLifecycleService::class)->complete(
                        $record,
                        DeliveryOrderResult::from($data['result']),
                    );

                    Notification::make()->success()->title('تم تسجيل نتيجة الطلب بنجاح')->send();
                } catch (InvalidDeliveryOrderTransitionException $exception) {
                    static::sendDomainFailure($exception);
                }
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancelOrder')
            ->label('إلغاء الطلب')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('هل أنت متأكد من إلغاء هذا الطلب؟')
            ->visible(fn (DeliveryOrder $record): bool => in_array(
                $record->status,
                [DeliveryOrderStatus::NewOrder, DeliveryOrderStatus::Assigned],
                true,
            ))
            ->action(function (DeliveryOrder $record): void {
                try {
                    app(DeliveryOrderLifecycleService::class)->cancel($record);

                    Notification::make()->success()->title('تم إلغاء الطلب بنجاح')->send();
                } catch (InvalidDeliveryOrderTransitionException $exception) {
                    static::sendDomainFailure($exception);
                }
            });
    }

    private static function representativeAction(string $name, string $label): Action
    {
        return Action::make($name)
            ->label($label)
            ->schema([
                Select::make('representative_id')
                    ->label('المندوب')
                    ->options(fn (): array => Representative::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Representative $representative): array => [
                            $representative->id => trim($representative->name.' — '.($representative->phone ?? 'بدون هاتف')),
                        ])
                        ->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (DeliveryOrder $record, array $data): void {
                try {
                    $representative = Representative::query()->findOrFail($data['representative_id']);
                    app(DeliveryOrderLifecycleService::class)->assignRepresentative($record, $representative);

                    Notification::make()->success()->title('تم إسناد الطلب بنجاح')->send();
                } catch (InvalidDeliveryOrderTransitionException $exception) {
                    static::sendDomainFailure($exception);
                }
            });
    }

    private static function sendDomainFailure(InvalidDeliveryOrderTransitionException $exception): void
    {
        Notification::make()
            ->danger()
            ->title('تعذر تنفيذ العملية')
            ->body($exception->getMessage())
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryOrders::route('/'),
            'create' => CreateDeliveryOrder::route('/create'),
            'view' => ViewDeliveryOrder::route('/{record}'),
            'edit' => EditDeliveryOrder::route('/{record}/edit'),
        ];
    }
}
