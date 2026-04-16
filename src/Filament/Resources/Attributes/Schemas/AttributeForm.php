<?php

namespace SmartTill\Core\Filament\Resources\Attributes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AttributeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        Section::make('Attribute Detail')
                            ->schema([
                                TextInput::make('name')
                                    ->required(),
                            ])
                            ->columnSpan('full'),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
