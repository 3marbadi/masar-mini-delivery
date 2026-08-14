<?php

namespace App\Filament\Resources\Representatives\Tables;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RepresentativesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('الهاتف')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('الحالة')
                    ->trueLabel('نشط')
                    ->falseLabel('غير نشط'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('deactivate')
                    ->label('تعطيل')
                    ->requiresConfirmation()
                    ->hidden(fn ($record): bool => ! $record->is_active)
                    ->action(fn ($record) => $record->update(['is_active' => false])),
                Action::make('activate')
                    ->label('تفعيل')
                    ->hidden(fn ($record): bool => $record->is_active)
                    ->action(fn ($record) => $record->update(['is_active' => true])),
            ])
            ->toolbarActions([]);
    }
}
