<?php

namespace SmartTill\Core\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SmartTill\Core\Enums\CategoryStatus;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        Section::make('Category Detail')
                            ->schema([
                                TextInput::make('name')
                                    ->required(),
                                Textarea::make('description')
                                    ->columnSpanFull(),
                            ])
                            ->columnSpan(2),

                        Section::make('Status')
                            ->hidden(fn (?Model $record): bool => $record === null)
                            ->schema([
                                Select::make('status')
                                    ->options(CategoryStatus::class)
                                    ->default(CategoryStatus::default())
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                            ])
                            ->columnSpan(1),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
