<?php

namespace App\Filament\Resources\Patients\RelationManagers;

use App\Enums\Patient\SecurityEventActor;
use App\Enums\Patient\SecurityEventType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient's security history: sign-ins and failed attempts, sign-outs,
 * sessions ended, links sent, and changes operators made.
 *
 * READ-ONLY, and the model refuses edits regardless. Signing a patient out is
 * the "Sign out everywhere" action on the record's page, which appends an event
 * rather than touching this list.
 *
 * PatientSecurityEvent has no Shield policy, so viewing is gated on the owning
 * patient — same approach as ConsentsRelationManager.
 *
 * What is NOT here: failed sign-ins for an address that had no account at the
 * time. They carry an address hash but no patient, so they cannot be joined to
 * a record without trusting what an anonymous visitor typed.
 */
class SecurityEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'securityEvents';

    protected static ?string $title = 'Security history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('view', $ownerRecord) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('actorUser'))
            // Newest first, id as the tie-break: events a second apart share a
            // timestamp and would otherwise reorder between loads.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('occurred_at')->orderByDesc('id'))
            ->emptyStateHeading('No security events')
            ->emptyStateDescription('No sign-ins or account changes have been recorded for this patient yet.')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Event')
                    ->badge()
                    ->formatStateUsing(fn (SecurityEventType $state): string => $state->label())
                    ->color(fn (SecurityEventType $state): string => $state->color()),

                TextColumn::make('actor_type')
                    ->label('By')
                    ->formatStateUsing(fn (SecurityEventActor $state, $record): string => match ($state) {
                        SecurityEventActor::Operator => $record->actorUser?->name ?? "Operator #{$record->actor_user_id}",
                        SecurityEventActor::Patient => 'Patient',
                        SecurityEventActor::Anonymous => 'Unverified',
                        SecurityEventActor::System => 'System',
                    }),

                TextColumn::make('context')
                    ->label('Detail')
                    ->state(fn ($record): ?string => self::detail($record->context))
                    ->placeholder('—'),

                TextColumn::make('ip_address')
                    ->label('IP')
                    ->copyable()
                    ->placeholder('—'),

                TextColumn::make('user_agent')
                    ->label('Browser')
                    ->limit(40)
                    ->tooltip(fn ($record): ?string => $record->user_agent)
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('token_id')
                    ->label('Session')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(SecurityEventType::cases())
                        ->mapWithKeys(fn (SecurityEventType $type): array => [$type->value => $type->label()])
                        ->all()),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }

    /**
     * @param  array<string, scalar|null>|null  $context
     */
    private static function detail(?array $context): ?string
    {
        if (empty($context)) {
            return null;
        }

        // `account_deleted` as a reason only repeats the badge beside it.
        $reason = ($context['reason'] ?? null) === 'account_deleted' ? null : ($context['reason'] ?? null);

        return collect([
            'reason' => $reason !== null ? str_replace('_', ' ', (string) $reason) : null,
            'revoked' => isset($context['revoked']) ? "{$context['revoked']} session(s) ended" : null,
            'method' => isset($context['method']) ? 'via '.str_replace('_', ' ', (string) $context['method']) : null,
        ])->filter()->implode(' · ');
    }
}
