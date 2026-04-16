<?php

namespace SmartTill\Core\Filament\Resources\Units\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Unit Details')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('symbol')
                            ->maxLength(50),
                        TextInput::make('code')
                            ->maxLength(50),
                        Select::make('dimension_id')
                            ->label('Dimension')
                            ->relationship('dimension', 'name', fn ($query) => $query->orderBy('name'))
                            ->required()
                            ->searchable()
                            ->preload(),
                    ]),
                Section::make('Conversion')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('to_base_factor')
                            ->label('To Base Factor')
                            ->numeric()
                            ->inputMode('decimal')
                            ->required()
                            ->default(1),
                        TextInput::make('to_base_offset')
                            ->label('To Base Offset')
                            ->numeric()
                            ->inputMode('decimal')
                            ->default(0),
                    ]),
            ]);
    }
}
