<?php

namespace App\Filament\Resources\Representatives;

use App\Filament\Resources\Representatives\Pages\CreateRepresentative;
use App\Filament\Resources\Representatives\Pages\EditRepresentative;
use App\Filament\Resources\Representatives\Pages\ListRepresentatives;
use App\Filament\Resources\Representatives\Pages\ViewRepresentative;
use App\Filament\Resources\Representatives\Schemas\RepresentativeForm;
use App\Filament\Resources\Representatives\Schemas\RepresentativeInfolist;
use App\Filament\Resources\Representatives\Tables\RepresentativesTable;
use App\Models\Representative;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RepresentativeResource extends Resource
{
    protected static ?string $model = Representative::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'المندوبون';

    protected static ?string $modelLabel = 'مندوب';

    protected static ?string $pluralModelLabel = 'المندوبون';

    public static function form(Schema $schema): Schema
    {
        return RepresentativeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RepresentativeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RepresentativesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepresentatives::route('/'),
            'create' => CreateRepresentative::route('/create'),
            'view' => ViewRepresentative::route('/{record}'),
            'edit' => EditRepresentative::route('/{record}/edit'),
        ];
    }
}
