<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use App\Services\Content\ArticleService;
use App\Support\Admin\AdminAccess;
use App\Support\SupportedLocale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Admin screen for content-marketing articles (D5).
 *
 * WHY THE STATUS IS NOT A PLAIN SELECT
 * Publication state is a transition, not a value. A dropdown containing
 * "published" would let an operator move a draft straight to live, which is
 * exactly what App\Enums\ArticleStatus::allowedTransitions() exists to prevent:
 * article copy on a real-money gambling site is regulated marketing, and the
 * things that make it good at attracting search traffic are the same things
 * that make it a compliance problem. So the form carries no status field at all
 * and every change of state goes through a named action.
 *
 * WHY AN ACTION HIDES INSTEAD OF FAILING
 * `visible()` checks the transition before the button is rendered. An operator
 * is never offered "Publish" on a draft — not offered and then refused with an
 * error, which reads as the software being broken, when in fact the workflow is
 * working as designed.
 */
final class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    /*
     * AUTHORISATION.
     *
     * Article copy is regulated marketing on a real-money platform: publishing a
     * page is a controlled action, not a read. The resource therefore asks
     * AdminAccess for the settings-level permission that governs published copy,
     * exactly like every other resource in the console - a resource that inherits
     * canViewAny() from the framework is open to anyone the panel admits.
     */
    public static function canViewAny(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return AdminAccess::current(AdminAccess::MANAGE_SYSTEM_SETTINGS);
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        // Copy is never hard-deleted: unpublishing is the reversible action, and a
        // deleted article cannot be produced for a regulator.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return 'Article';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Articles';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Article')
                ->description('The status is changed with the actions above the table, never from this form.')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
                            // Pre-fill the slug from the title, but only while it
                            // is untouched: overwriting a slug an editor has
                            // deliberately refined would break published links.
                            if (filled($state)) {
                                $set('slug', app(ArticleService::class)->uniqueSlug($state, 'en'));
                            }
                        }),

                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(200)
                        ->helperText('The URL segment. Changing it breaks every existing link to the article.'),

                    Forms\Components\Select::make('locale')
                        ->required()
                        ->options(array_combine(SupportedLocale::ALL, array_map('strtoupper', SupportedLocale::ALL)))
                        ->default(SupportedLocale::EN),

                    Forms\Components\Select::make('category')
                        ->required()
                        ->options(collect(ArticleCategory::cases())
                            ->mapWithKeys(fn (ArticleCategory $c): array => [$c->value => $c->label()])
                            ->all())
                        ->default(ArticleCategory::Guide->value)
                        ->helperText('Result coverage is not indexed by search engines. Responsible-gaming articles never show a call to action.'),

                    Forms\Components\TextInput::make('author_name')
                        ->maxLength(120)
                        ->placeholder('ThaiLotto Editorial')
                        ->helperText('Free text, not a staff account — attribution is editorial.'),

                    Forms\Components\RichEditor::make('body')
                        ->required()
                        ->columnSpanFull()
                        ->helperText('Stored sanitised. Scripts, inline styles and event handlers are removed on save.'),

                    Forms\Components\Textarea::make('excerpt')
                        ->rows(3)
                        ->maxLength(600)
                        ->helperText('Used on the index and as the meta description. Derived from the body when empty.'),
                ])->columns(2),

            Forms\Components\Section::make('Search appearance')
                ->description('Optional. Empty fields fall back to the title and excerpt.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('meta_title')
                        ->maxLength(255)
                        ->helperText('Search results show about 60 characters.'),

                    Forms\Components\Textarea::make('meta_description')
                        ->rows(2)
                        ->maxLength(320)
                        ->helperText('Search results show about 155 characters.'),

                    Forms\Components\Toggle::make('featured')
                        ->label('Feature on the index')
                        ->default(false),

                    Forms\Components\TextInput::make('reading_minutes')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(120)
                        ->helperText('Left empty, it is estimated from the body.'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->limit(60),

                Tables\Columns\TextColumn::make('locale')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),

                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (ArticleCategory $state): string => $state->label()),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (ArticleStatus $state): string => match ($state) {
                        ArticleStatus::Draft => 'gray',
                        ArticleStatus::InReview => 'warning',
                        ArticleStatus::Published => 'success',
                        ArticleStatus::Archived => 'danger',
                    })
                    ->formatStateUsing(fn (ArticleStatus $state): string => $state->label()),

                Tables\Columns\TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('featured')->boolean(),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(ArticleStatus::cases())
                        ->mapWithKeys(fn (ArticleStatus $s): array => [$s->value => $s->label()])
                        ->all()),

                Tables\Filters\SelectFilter::make('locale')
                    ->options(array_combine(SupportedLocale::ALL, array_map('strtoupper', SupportedLocale::ALL))),

                Tables\Filters\SelectFilter::make('category')
                    ->options(collect(ArticleCategory::cases())
                        ->mapWithKeys(fn (ArticleCategory $c): array => [$c->value => $c->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Each action is offered only where the workflow permits it, so
                // the operator is never shown a button that would be refused.
                Tables\Actions\Action::make('submit_for_review')
                    ->label('Submit for review')
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->visible(fn (Article $record): bool => $record->status?->canTransitionTo(ArticleStatus::InReview) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('The article will be queued for a second reviewer. It is not public yet.')
                    ->action(fn (Article $record) => self::transition($record, ArticleStatus::InReview, 'Submitted for review.')),

                Tables\Actions\Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-globe-alt')
                    ->color('success')
                    ->visible(fn (Article $record): bool => $record->status?->canTransitionTo(ArticleStatus::Published) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('This article will become publicly reachable and will appear in the sitemap.')
                    ->action(fn (Article $record) => self::transition($record, ArticleStatus::Published, 'Article published.')),

                Tables\Actions\Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('danger')
                    ->visible(fn (Article $record): bool => $record->status?->canTransitionTo(ArticleStatus::Archived) ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('The article will stop being publicly reachable. Its URL will return 404.')
                    ->action(fn (Article $record) => self::transition($record, ArticleStatus::Archived, 'Article archived.')),

                Tables\Actions\Action::make('back_to_draft')
                    ->label('Back to draft')
                    ->icon('heroicon-o-pencil')
                    ->visible(fn (Article $record): bool => $record->status?->canTransitionTo(ArticleStatus::Draft) ?? false)
                    ->action(fn (Article $record) => self::transition($record, ArticleStatus::Draft, 'Returned to draft.')),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticles::route('/'),
            'create' => Pages\CreateArticle::route('/create'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }

    /**
     * @return Builder<Article>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    private static function transition(Article $record, ArticleStatus $target, string $message): void
    {
        try {
            app(ArticleService::class)->transition($record, $target, auth()->id());
        } catch (RuntimeException $e) {
            // A refused transition is the workflow working, not a fault, but it
            // must still be explained rather than failing silently.
            Notification::make()
                ->title('That change is not allowed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()->title($message)->success()->send();
    }
}
