<?php

namespace App\Petkit\Devices\FreshElement3;

use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\Select;
use App\Filament\StateCasts\IdentityStateCast;
use App\Helpers\Time;
use App\Jobs\ServiceEnd;
use App\Jobs\ServiceStart;
use App\Models\Device;
use App\MQTT\GenericReply;
use App\MQTT\OtaMessage;
use App\MQTT\UserGet;
use App\Petkit\DeviceActions;
use App\Petkit\DeviceDefinition;
use App\Petkit\DeviceStates;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Filament\Forms;
use Filament\Forms\Form;
use App\Filament\Forms\Components\MinutesTimePicker;

class UI
{

    public function formFields(): array
    {
        return [
            Section::make('Consumables')->columns(2)->schema([
                TextInput::make('configuration.consumables.desiccantDurability')->numeric(),
                TextInput::make('configuration.consumables.desiccantNextChange')->label('Next Reset in Days (Desiccant)')->formatStateUsing(function ($state) {
                    if ($state <= 0) {
                        return 'Not set';
                    }
                    $date = Carbon::parse($state);
                    $now = Carbon::now();

                    return round($now->diffInDays($date));

                })->readOnly()->disabled(true),
            ]),
            Section::make('Feeding')->schema([
                TextInput::make('configuration.settings.amount')
                    ->label('Feeding Amount')
                    ->helperText('Default amount for manual feeding')
                    ->numeric()
            ]),
            Section::make('Settings')->columns(2)->schema([
                Toggle::make('configuration.settings.manualLock')
                    ->helperText('Activate Child Lock to disable the control panel')
                    ->columnSpan('half')
                    ->label('Child Lock'),

                Toggle::make('configuration.settings.lightMode')
                    ->helperText('Indicator light work within the following period')
                    ->label('Indicator Light'),

                Section::make('Light Period')->schema([
                    MinutesTimePicker::make('configuration.settings.lightRange.from'),

                    MinutesTimePicker::make('configuration.settings.lightRange.till')
                ])
                    ->columns(2)
                    ->columnSpanFull(),

                Toggle::make('configuration.settings.soundEnable')
                    ->helperText('Play the voice when the food is dispensing')
                    ->label('Voice for Food Dispensing'),

                Toggle::make('configuration.settings.systemSoundEnable')
                    ->helperText('Turn on the voice prompt of the device')
                    ->label('Voice Prompt'),

                FileUpload::make('configuration.feed_sound_file')
                    ->label('Custom Food Dispensing Audio')
                    ->helperText('Upload an audio recording (.mp3, .wav, .aac, .m4a, .ogg). Automatically converted to AAC 16kHz mono for the feeder.')
                    ->disk('public')
                    ->directory('sounds')
                    ->visibility('public')
                    ->acceptedFileTypes(['audio/*', 'video/mp4', 'audio/aac', 'audio/mpeg', 'audio/wav', 'audio/mp4', 'audio/ogg', 'audio/x-m4a'])
                    ->maxSize(10240)
                    ->columnSpanFull(),

                Placeholder::make('feed_sound_preview')
                    ->label('Enregistrement vocal pour la distribution')
                    ->content(function (?Device $record) {
                        $meta = $record?->configuration['feed_sound'] ?? null;
                        if (!$meta || empty($meta['id'])) {
                            return new HtmlString("<div style='padding: 10px; background-color: rgba(156, 163, 175, 0.1); border-radius: 8px; color: #6b7280;'><em>Aucun enregistrement vocal actif. T&eacute;l&eacute;versez un fichier audio ci-dessous pour le diffuser lors des repas.</em></div>");
                        }
                        $fn = $meta['filename'] ?? ('feed_' . $meta['id'] . '.aac');
                        $url = route('sound.feed', ['device' => $record->id, 'filename' => $fn]);
                        $sizeKb = round(($meta['size'] ?? 0) / 1024, 1);
                        return new HtmlString("
                            <div style='display: flex; flex-direction: column; gap: 8px; padding: 12px; background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); border-radius: 8px;'>
                                <div style='display: flex; justify-content: space-between; align-items: center;'>
                                    <span style='font-weight: 600; color: #15803d;'>&#128266; Enregistrement personnalis&eacute; actif ({$sizeKb} Ko)</span>
                                    <span style='font-size: 0.85em; color: #166534;'>Format mat&eacute;riel : AAC 16kHz Mono</span>
                                </div>
                                <audio controls src='{$url}' style='width: 100%; height: 36px;'></audio>
                            </div>
                        ");
                    })
                    ->columnSpanFull(),

                Actions::make([
                    Action::make('test_feeder_sound')
                        ->label('Tester sur le distributeur (Haut-parleur)')
                        ->icon('heroicon-o-speaker-wave')
                        ->color('success')
                        ->action(function (?Device $record) {
                            if (!$record) return;
                            $soundId = (int) ($record->configuration['feed_sound']['id'] ?? ($record->configuration['settings']['selectedSound'] ?? 1));
                            if ($soundId <= 0) $soundId = 1;
                            \App\Jobs\PlaySound::dispatchSync($record, $soundId);
                            Notification::make()->title('Ordre de lecture audio envoyé au distributeur')->success()->send();
                        }),
                ])
                ->visible(fn (?Device $record) => !empty($record?->configuration['feed_sound']['id']))
                ->columnSpanFull(),

                Toggle::make('configuration.remove_feed_sound')
                    ->label("Supprimer l'enregistrement vocal existant")
                    ->helperText("Cochez cette case puis cliquez sur Enregistrer pour effacer l'enregistrement personnalisé.")
                    ->visible(fn (?Device $record) => !empty($record?->configuration['feed_sound']['id']))
                    ->default(false)
                    ->columnSpanFull(),

                TextInput::make('configuration.settings.volume')
                    ->label('Volume')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(9),

                Toggle::make('configuration.settings.disturbMode')
                    ->helperText('Mute sounds and lights within the following period')
                    ->label('Do Not Disturb'),

                Section::make('Do Not Disturb Period')->schema([
                    MinutesTimePicker::make('configuration.settings.disturbRange.from'),

                    MinutesTimePicker::make('configuration.settings.disturbRange.till')
                ])
                    ->columns(2)
                    ->columnSpanFull(),

                Toggle::make('configuration.settings.surplusControl')
                    ->helperText('Pause dispensing while enough food is left in the bowl')
                    ->label('Surplus Control'),

                TextInput::make('configuration.settings.surplus')
                    ->label('Surplus Amount')
                    ->numeric()
                    ->suffix('g'),


            ]),
            Section::make('Feeding Plan')
                ->schema([
                    Repeater::make('configuration.schedule')

                        ->schema([
                            CheckboxList::make('re')
                                ->label('Days of Week')
                                ->options([
                                    '1' => 'Sunday',
                                    '2' => 'Monday',
                                    '3' => 'Tuesday',
                                    '4' => 'Wednesday',
                                    '5' => 'Thursday',
                                    '6' => 'Friday',
                                    '7' => 'Saturday',
                                ])
                                ->columns(4)
                                ->required()
                                ->stateCast(new IdentityStateCast())
                                ->formatStateUsing(fn(string|array|null $state) => is_array($state) ? $state : ($state === null || $state === '' ? [] : explode(',', $state)))
                                // Kept as an array here, not joined into a comma string - Filament
                                // validates the *dehydrated* value against the options list, and a
                                // joined string ("1,2,3,4,5,6,7") never matches a single option key,
                                // so any 2+ day selection failed "is invalid". The comma string this
                                // needs for storage is built afterwards, in
                                // EditDevice::mutateFormDataBeforeSave(), after validation has passed.
                                ->dehydrateStateUsing(fn($state) => array_values(Arr::sort(array_filter((array) $state)))),

                            Repeater::make('it')
                                ->label('Schedule Items')
                                ->schema([
                                    TimePicker::make('time_display')
                                        ->label('Time')
                                        ->seconds(false)
                                        ->required()
                                        ->dehydrated(false)
                                        ->afterStateUpdated(function (Set $set, $state) {
                                            if ($state) {
                                                // Convert time to seconds from midnight;
                                                $seconds = Time::toSeconds($state);
                                                $set('t', $seconds);
                                                // Reverted 2026-08-24: the 'n%d' (no underscore)
                                                // shortening below was meant to leave a
                                                // null-terminator byte after a 2026-08-23 capture
                                                // showed the device's own debug log printing
                                                // garbage past an unterminated 7-byte id. A live
                                                // side-by-side test against the real app confirmed
                                                // that hypothesis wrong: the real app sends the
                                                // full 7-byte unterminated 'n_%d' form and the
                                                // device fires fine on it, while localkit's
                                                // shortened 'n%d' form silently failed to fire at
                                                // the scheduled time. Back to 'n_%d'.
                                                $set('id', sprintf('n_%d', $seconds));
                                            }
                                        })
                                        ->afterStateHydrated(function (Set $set, Get $get) {
                                            $seconds = $get('t');
                                            if ($seconds) {
                                                // Convert seconds back to time format
                                                $time = Time::toTimeFromSeconds($seconds);
                                                $set('time_display', $time);
                                            }
                                        }),
                                    TextInput::make('id')
                                        ->hidden(true)
                                        ->dehydratedWhenHidden(true)
                                        ->label('id')
                                        ->required(),
                                    TextInput::make('a')
                                        ->label('Amount')
                                        ->numeric()
                                        ->required()
                                        ->integer()
                                        ->dehydrateStateUsing(fn($state) => (int)$state)
                                        ->suffix('amount'),
                                    TextInput::make('t')
                                        ->hidden(true)
                                        ->label('Time (seconds)')
                                        ->numeric()
                                        ->dehydratedWhenHidden(true)
                                        ->required(),
                                ])
                                ->columns(2)
                                ->addActionLabel('Add Schedule Item')
                                ->minItems(0)
                                ->collapsible()
                                ->live(true)
                                ->dehydrateStateUsing(function (array $state) {
                                    if (!is_array($state)) return $state;

                                    // Sort by time_display treating it as time, descending - the device requires it.
                                    uasort($state, function ($a, $b) {
                                        $timeA = $a['time_display'] ?? '00:00';
                                        $timeB = $b['time_display'] ?? '00:00';

                                        // Convert to comparable format (HHMM as integer)
                                        $intA = (int)str_replace(':', '', $timeA);
                                        $intB = (int)str_replace(':', '', $timeB);

                                        return $intB <=> $intA;
                                    });

                                    return collect($state)->map(fn($s) => [
                                        'a' => $s['a'],
                                        'id' => $s['id'],
                                        't' => $s['t'],
                                    ])->toArray();
                                })
                                ->itemLabel(function (array $state): ?string {
                                    $time = '';
                                    if (!empty($state['t'])) {
                                        $seconds = $state['t'];
                                        $hours = floor($seconds / 3600);
                                        $minutes = floor(($seconds % 3600) / 60);
                                        $time = sprintf('%02d:%02d', $hours, $minutes);
                                    }
                                    $amount = $state['a'] ?? '';
                                    return $time ? "{$time} ({$amount}g)" : 'New Item';
                                }),
                        ])
                        ->columns(1)
                        ->addActionLabel('Add Day Schedule')
                        ->minItems(0)
                        ->collapsible()
                        ->itemLabel(function (array $state): ?string {
                            $days = [];
                            if (!empty($state['re'])) {
                                $dayNumbers = is_string($state['re']) ? explode(',', $state['re']) : $state['re'];
                                $dayNames = [
                                    '1' => 'Sun', '2' => 'Mon', '3' => 'Tue', '4' => 'Wed',
                                    '5' => 'Thu', '6' => 'Fri', '7' => 'Sat'
                                ];
                                foreach ($dayNumbers as $day) {
                                    if (isset($dayNames[$day])) {
                                        $days[] = $dayNames[$day];
                                    }
                                }
                            }
                            return !empty($days) ? implode(', ', $days) : 'New Schedule';
                        }),
                ]),
            Section::make('Unknown')->columns(2)->schema([

                ViewField::make('UnknownWarning')
                    ->columnSpanFull()
                    ->view('filament.forms.warning')
                    ->viewData(['message' => 'Its Unknown, because the changes are not verified']),


                Select::make('configuration.settings.language')->options([
                    'en_US' => 'English',
                    'zh_CN' => 'Chinese'
                ])->label('Language'),

                TextInput::make('configuration.settings.selectedSound')
                    ->columnSpan('half')
                    ->readOnly()
                    ->numeric()
                    ->label('Selected Sound'),
                TextInput::make('configuration.settings.numLimit')
                    ->columnSpan('half')
                    ->readOnly()
                    ->numeric()
                    ->label('Num Limit'),
            ]),


        ];
    }

}
