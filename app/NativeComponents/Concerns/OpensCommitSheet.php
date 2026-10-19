<?php

namespace App\NativeComponents\Concerns;

use App\Devnull\Client;
use App\Devnull\Data\Commit;
use App\Devnull\Data\ScheduledCommit;
use App\Devnull\ReportReason;
use App\Devnull\Stash;
use App\Devnull\Timezone;
use Carbon\CarbonImmutable;
use Closure;
use Native\Mobile\Facades\Dialog;

/**
 * The sheet under a commit (see partials/commit-sheet). Report commit, in the
 * More menu, opens it as the web's Report dialog. As on the web, only someone
 * else's published commit can be reported, so only their menus have it.
 *
 * Quote opens the same sheet as a composer, over the commit being quoted, as
 * the web's Quote dialog. The Log opens it with nothing to quote, for a new
 * commit of your own, so you can write one without leaving where you are in
 * it. Once devnull has the commit the sheet goes, and a screen that lists
 * your new commits puts it in place (see showCommitted()).
 *
 * A new commit can be scheduled instead, as the web's composer schedules one:
 * the clock button opens "Submit at" with the system's date and time picker,
 * in the phone's timezone (see Timezone), and Commit becomes Schedule commit.
 * Only you see it until it's due, on the Scheduled commits page it links to.
 *
 * What you write in it is stashed as you type, as on the web (see Stash): a
 * quote per commit quoted, and a new commit under the web's composer draft.
 * Opened again, it starts from the draft, marked as restored. It's forgotten
 * once it's sent.
 *
 * The sheet keeps its commit while it slides away, so it doesn't empty on the
 * way down, and lets go of it once iOS says it's gone, however it was closed.
 */
trait OpensCommitSheet
{
    /**
     * What the web says once a commit is scheduled.
     */
    public const string Scheduled = 'Commit scheduled. Only you can see it until it is submitted.';

    /**
     * What the web says of a time the timezone skips.
     */
    public const string SkippedTime = 'This time does not exist in your timezone. Choose another time.';

    /**
     * Whether the sheet is up, or on its way up.
     */
    public bool $sheetOpen = false;

    /**
     * The commit the sheet is for, as it was when it opened, until the sheet has gone.
     */
    public ?Commit $sheetCommit = null;

    /**
     * What the sheet shows: 'report' (the form), 'reported' (sent), 'quote', or 'compose' (a new commit, with no
     * commit of its own).
     */
    public string $sheetView = 'report';

    /**
     * The reason picked on the report form, or '' until one is.
     */
    public string $reportReason = '';

    public string $reportDetails = '';

    /**
     * The commit you're writing in the sheet, quoting its commit or not.
     */
    public string $sheetBody = '';

    /**
     * Whether the sheet opened on a stashed draft, which it marks until you empty it or drop it.
     */
    public bool $sheetRestored = false;

    /**
     * Why devnull didn't take the report or the commit, shown in the sheet.
     */
    public ?string $sheetError = null;

    /**
     * Whether the new commit is to be scheduled for later, as the web's clock button toggles it.
     */
    public bool $scheduling = false;

    /**
     * When to submit it, as the picker's wall clock in the phone's timezone (`2026-10-20T09:00`), or '' until one is
     * picked.
     */
    public string $scheduledAt = '';

    /**
     * Whether the time picked is one to schedule for: there is one, and the phone's timezone doesn't skip it.
     */
    public bool $hasScheduledTime = false;

    /**
     * Whether Scheduled commits → was tapped, so the list opens once the sheet has gone.
     */
    public bool $opensScheduledCommits = false;

    /**
     * Report commit, in the More menu of someone else's published commit.
     */
    public function reportCommit(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canReport($commit)) {
            return;
        }

        $this->forgetCommitSheet();
        $this->sheetCommit = $commit;
        $this->sheetOpen = true;
    }

    /**
     * Sends the report. The sheet says it was received, or keeps the form with why it wasn't.
     */
    public function sendReport(): void
    {
        $commit = $this->sheetCommit;
        $reason = ReportReason::tryFrom($this->reportReason);

        if ($commit === null || $this->sheetView !== 'report' || $reason === null) {
            return;
        }

        $details = trim($this->reportDetails);

        $sent = $this->attemptInSheet(function (Client $devnull) use ($commit, $reason, $details): bool {
            $devnull->reportCommit($commit->id, $reason, $details);

            return true;
        });

        if ($sent === true) {
            $this->sheetView = 'reported';
        }
    }

    /**
     * Quote, under someone else's published commit or one of your own, as on the web.
     */
    public function quoteCommit(string $id): void
    {
        $commit = $this->shownCommit($id);

        if ($commit === null || ! $this->canQuote($commit)) {
            return;
        }

        $this->forgetCommitSheet();
        $this->sheetCommit = $commit;
        $this->sheetView = 'quote';
        $this->sheetOpen = true;
        $this->restoreSheetDraft();
    }

    /**
     * A new commit in the sheet, with nothing quoted.
     */
    public function composeInSheet(): void
    {
        $this->forgetCommitSheet();
        $this->sheetView = 'compose';
        $this->sheetOpen = true;
        $this->restoreSheetDraft();
    }

    /**
     * The web's clock button, in a new commit's composer: it opens "Submit at" under the field, with no time picked,
     * or closes it, and the commit goes now again.
     */
    public function toggleSchedule(): void
    {
        if ($this->sheetView !== 'compose') {
            return;
        }

        $this->scheduling = ! $this->scheduling;
        $this->scheduledAt = '';
        $this->hasScheduledTime = false;
        $this->sheetError = null;
    }

    /**
     * A time picked: the web says when the phone's timezone skips it, as on the night the clocks go forward.
     */
    public function updatedScheduledAt(): void
    {
        $this->hasScheduledTime = $this->scheduledTime() !== null;
        $this->sheetError = $this->scheduledAt === '' || $this->hasScheduledTime ? null : self::SkippedTime;
    }

    /**
     * Scheduled commits →, under "Submit at": the sheet goes, keeping the draft, and the list opens once it has.
     * Pushed while the sheet slides away, the list comes in without its title.
     */
    public function openScheduledCommits(): void
    {
        $this->opensScheduledCommits = true;
        $this->sheetOpen = false;
    }

    /**
     * Stashes the draft as it changes. An empty field forgets it, and isn't restored any more.
     */
    public function updatedSheetBody(): void
    {
        $name = $this->sheetStashName();

        if (! $this->sheetOpen || $name === null) {
            return;
        }

        app(Stash::class)->put($this->me()?->id, $name, $this->sheetBody);

        if ($this->sheetBody === '') {
            $this->sheetRestored = false;
        }
    }

    /**
     * The web's drop, beside a restored draft: it's thrown away, and the field starts again empty.
     */
    public function dropSheetStash(): void
    {
        $this->sheetBody = '';
        $this->updatedSheetBody();
    }

    /**
     * Commits what's written in the sheet, quoting its commit if it's a quote, or schedules it. The sheet goes once
     * devnull has it, or keeps the draft with why it didn't take it.
     */
    public function commitFromSheet(): void
    {
        $quoted = $this->sheetView === 'quote' ? $this->sheetCommit : null;
        $stashName = $this->sheetStashName();
        $body = trim($this->sheetBody);

        if ($stashName === null || mb_strlen($body) < Commit::MinLength || mb_strlen($body) > Commit::MaxLength) {
            return;
        }

        if ($this->scheduling && $this->sheetView === 'compose') {
            $this->scheduleFromSheet($body, $stashName);

            return;
        }

        $commit = $this->attemptInSheet(fn (Client $devnull): Commit => $devnull->createCommit($body, quotedCommitId: $quoted?->id));

        if ($commit === null) {
            return;
        }

        app(Stash::class)->put($this->me()?->id, $stashName, '');
        $this->sheetOpen = false;
        $this->showCommitted($commit);
    }

    /**
     * The time picked to submit at, or null until one is, or when the phone's timezone skips it, like 01:30 on the
     * night the clocks go forward.
     */
    private function scheduledTime(): ?CarbonImmutable
    {
        if ($this->scheduledAt === '') {
            return null;
        }

        $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $this->scheduledAt, app(Timezone::class)->here());

        return $at instanceof CarbonImmutable && $at->format('Y-m-d\TH:i') === $this->scheduledAt ? $at : null;
    }

    /**
     * Done: the sheet goes.
     */
    public function hideCommitSheet(): void
    {
        $this->sheetOpen = false;
    }

    /**
     * iOS says the sheet has gone, swiped away or closed from here. What was drafted in it stays stashed.
     */
    public function commitSheetClosed(): void
    {
        $opensScheduledCommits = $this->opensScheduledCommits;
        $this->forgetCommitSheet();

        if ($opensScheduledCommits) {
            $this->openInTab('/scheduled');
        }
    }

    /**
     * A commit you just made, for a screen to put where it lists your new ones. Most don't.
     */
    protected function showCommitted(Commit $commit): void
    {
        //
    }

    private function canQuote(Commit $commit): bool
    {
        $viewer = $this->me();

        return $viewer !== null && ! $commit->isDeleted() && ($commit->isApproved() || $commit->author->id === $viewer->id);
    }

    private function canReport(Commit $commit): bool
    {
        $viewer = $this->me();

        return $viewer !== null && ! $commit->isDeleted() && $commit->isApproved() && $commit->author->id !== $viewer->id;
    }

    /**
     * Schedules what's written for the time picked. The sheet goes once devnull has it, and says so as the web does.
     */
    private function scheduleFromSheet(string $body, string $stashName): void
    {
        $at = $this->scheduledTime();

        if ($at === null) {
            return;
        }

        $scheduled = $this->attemptInSheet(fn (Client $devnull): ScheduledCommit => $devnull->scheduleCommit($body, $at));

        if ($scheduled === null) {
            return;
        }

        app(Stash::class)->put($this->me()?->id, $stashName, '');
        $this->sheetOpen = false;
        Dialog::toast(self::Scheduled);
    }

    private function forgetCommitSheet(): void
    {
        $this->sheetOpen = false;
        $this->sheetCommit = null;
        $this->sheetView = 'report';
        $this->reportReason = '';
        $this->reportDetails = '';
        $this->sheetBody = '';
        $this->sheetRestored = false;
        $this->sheetError = null;
        $this->scheduling = false;
        $this->scheduledAt = '';
        $this->hasScheduledTime = false;
        $this->opensScheduledCommits = false;
    }

    /**
     * The draft the sheet stashes, by the web's name for it, or null when it isn't a composer.
     */
    private function sheetStashName(): ?string
    {
        return match (true) {
            $this->sheetView === 'compose' => 'commit',
            $this->sheetView === 'quote' && $this->sheetCommit !== null => 'quote-'.$this->sheetCommit->id,
            default => null,
        };
    }

    private function restoreSheetDraft(): void
    {
        $name = $this->sheetStashName();

        $this->sheetBody = $name === null ? '' : app(Stash::class)->get($this->me()?->id, $name);
        $this->sheetRestored = $this->sheetBody !== '';
    }

    /**
     * Like attempt(), but a failure shows in the sheet rather than where the screen prints its errors.
     *
     * @template TResult
     *
     * @param  Closure(Client): TResult  $call
     * @return TResult|null
     */
    private function attemptInSheet(Closure $call): mixed
    {
        $screenError = $this->error;
        $this->sheetError = null;

        $result = $this->attempt($call);

        if ($result === null) {
            $this->sheetError = $this->error;
            $this->error = $screenError;
        }

        return $result;
    }
}
