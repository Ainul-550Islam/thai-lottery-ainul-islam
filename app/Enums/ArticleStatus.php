<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Publication state of a content-marketing article (D5).
 *
 * WHY THERE IS A REVIEW STATE AT ALL
 * This is not a personal blog. Articles on a real-money gambling site are
 * marketing for a regulated product, and the things that make an article good
 * at attracting search traffic — promising certainty, implying a system,
 * showing big wins — are the same things that make it a compliance problem.
 * A draft therefore cannot go straight to the public site: someone other than
 * the author has to look at it first.
 *
 * The review step is the default because the cost of one un-reviewed article is
 * not one bad article. It is a regulator's screenshot of it.
 */
enum ArticleStatus: string
{
    case Draft = 'draft';

    /** Written, awaiting a second pair of eyes. Not publicly reachable. */
    case InReview = 'in_review';

    /** Live. The only state the public site will render. */
    case Published = 'published';

    /**
     * Once published, now withdrawn — but the row and its content are kept.
     * See `Article::isPubliclyReachable()` for why this returns 404 rather
     * than lingering as a soft-deleted zombie.
     */
    case Archived = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InReview => 'In review',
            self::Published => 'Published',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether the public site may render an article in this state.
     *
     * Only Published. Everything else — including Archived — is invisible to
     * the public, because an archived article that still renders is an article
     * nobody is maintaining, and a stale "how to claim your prize" page is
     * worse than no page.
     */
    public function isPublic(): bool
    {
        return $this === self::Published;
    }

    /**
     * Whether an article in this state may still be edited.
     *
     * Archived is deliberately NOT editable while archived: un-archiving is a
     * deliberate act, so a withdrawn article cannot drift back into a live
     * state by someone quietly editing it.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft, self::InReview, self::Published => true,
            self::Archived => false,
        };
    }

    /**
     * The states an article may move to from here.
     *
     * Review cannot be skipped (Draft -> Published is not offered). Publishing
     * from InReview is the only route to live.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Archived],
            self::InReview => [self::Draft, self::Published, self::Archived],
            self::Published => [self::Archived],
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
