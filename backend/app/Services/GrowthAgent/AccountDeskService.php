<?php

namespace App\Services\GrowthAgent;

use App\Models\AiDecision;
use App\Models\ConnectedAccount;
use App\Models\Post;
use App\Models\User;
use App\Services\XApi\XApiService;
use Illuminate\Support\Collection;

class AccountDeskService
{
    public function __construct(private readonly XApiService $x) {}

    public function select(User $user, ?int $accountId): ?ConnectedAccount
    {
        $accounts = $this->accounts($user);
        if ($accountId) {
            $chosen = $accounts->firstWhere('id', $accountId);
            if ($chosen) {
                return $chosen;
            }
        }

        return $accounts->first();
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    public function accounts(User $user): Collection
    {
        return $user->connectedAccounts()
            ->where('platform', 'x')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array{id: int, username: string|null, niche: string, own_voice: bool, uses_main_voice: bool, selected: bool}>
     */
    public function voices(User $user, ?ConnectedAccount $selected): array
    {
        $accounts = $this->accounts($user);
        $primaryId = $accounts->first()?->id;
        $profile = $user->accountProfile;

        return $accounts->map(function (ConnectedAccount $account) use ($selected, $primaryId, $profile) {
            return [
                'id' => (int) $account->id,
                'username' => $account->username,
                'niche' => $account->nicheFor($profile),
                'own_voice' => $account->hasOwnVoice() || $account->id === $primaryId,
                'uses_main_voice' => ! $account->hasOwnVoice() && $account->id !== $primaryId,
                'selected' => $selected !== null && (int) $account->id === (int) $selected->id,
            ];
        })->all();
    }

    public function purpose(User $user, ?ConnectedAccount $account): string
    {
        $profile = $user->accountProfile;
        if (! $account) {
            return trim((string) ($profile?->description ?? '').' '.(string) ($profile?->audience ?? '').' '.(string) ($profile?->resolvedNiche() ?? ''));
        }

        return trim($account->descriptionFor($profile).' '.$account->audienceFor($profile).' '.$account->nicheFor($profile));
    }

    /**
     * @return array{decision: string|null, line: string, reason: string}
     */
    public function judgment(User $user, ?ConnectedAccount $account): array
    {
        if (! $account) {
            return [
                'decision' => null,
                'line' => 'Connect X to see why it posts.',
                'reason' => 'The line appears after the first look.',
            ];
        }

        $primaryId = $this->accounts($user)->first()?->id;
        $row = AiDecision::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereIn('decision', ['POST', 'WAIT', 'IGNORE'])
            ->where(function ($query) {
                $query->whereNull('action_taken')
                    ->orWhereNotIn('action_taken', ['liked', 'replied', 'reposted', 'skipped_reply', 'quoted']);
            })
            ->where(function ($query) use ($account, $primaryId) {
                $query->where('connected_account_id', $account->id);
                if ((int) $account->id === (int) $primaryId) {
                    $query->orWhereNull('connected_account_id');
                }
            })
            ->latest()
            ->first();

        if (! $row) {
            return [
                'decision' => null,
                'line' => 'It has not looked yet today.',
                'reason' => 'When it looks, this line says why it posted or why it stayed quiet.',
            ];
        }

        $line = match ($row->decision) {
            'POST' => 'It posted.',
            'IGNORE' => 'It left that moment.',
            default => 'It stayed quiet.',
        };

        return [
            'decision' => $row->decision,
            'line' => $line,
            'reason' => $this->plainReason((string) $row->reason),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $live
     * @return array{profile: array{content: string, visits: int}|null, replies: array{content: string, replies: int}|null}
     */
    public function earned(?array $live): array
    {
        $tweets = is_array($live) && is_array($live['tweets'] ?? null) ? $live['tweets'] : [];
        $posts = array_values(array_filter($tweets, fn ($row) => is_array($row) && ($row['kind'] ?? 'post') === 'post'));
        if ($posts === []) {
            $posts = array_values(array_filter($tweets, 'is_array'));
        }

        $profile = null;
        $replies = null;
        foreach ($posts as $row) {
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $visits = (int) ($row['profile_clicks'] ?? 0);
            $replyCount = (int) ($row['replies'] ?? 0);
            if ($visits > 0 && ($profile === null || $visits > $profile['visits'])) {
                $profile = ['content' => $content, 'visits' => $visits];
            }
            if ($replyCount > 0 && ($replies === null || $replyCount > $replies['replies'])) {
                $replies = ['content' => $content, 'replies' => $replyCount];
            }
        }

        return ['profile' => $profile, 'replies' => $replies];
    }

    /**
     * @return array{answered: list<array{author: string, theirs: string, ours: string}>, skipped: list<array{author: string, theirs: string, reason: string}>}
     */
    public function replyRecord(User $user, ?ConnectedAccount $account): array
    {
        if (! $account) {
            return ['answered' => [], 'skipped' => []];
        }

        $primaryId = $this->accounts($user)->first()?->id;
        $decisions = AiDecision::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereIn('action_taken', ['replied', 'skipped_reply'])
            ->where(function ($query) use ($account, $primaryId) {
                $query->where('connected_account_id', $account->id);
                if ((int) $account->id === (int) $primaryId) {
                    $query->orWhereNull('connected_account_id');
                }
            })
            ->latest()
            ->limit(12)
            ->get();

        $answered = [];
        $skipped = [];
        foreach ($decisions as $decision) {
            $context = is_array($decision->context) ? $decision->context : [];
            $author = trim((string) ($context['author'] ?? 'Someone'));
            $theirs = trim((string) ($context['theirs'] ?? ''));
            if ($decision->action_taken === 'replied') {
                $ours = trim((string) ($context['ours'] ?? ''));
                if ($ours === '') {
                    $ours = (string) Post::query()
                        ->where('user_id', $user->id)
                        ->where('content_type', 'reply')
                        ->where('status', 'posted')
                        ->where('created_at', '>=', now()->startOfDay())
                        ->latest()
                        ->value('content');
                }
                if ($ours !== '') {
                    $answered[] = ['author' => $author !== '' ? $author : 'Someone', 'theirs' => $theirs, 'ours' => $ours];
                }
            } else {
                $skipped[] = [
                    'author' => $author !== '' ? $author : 'Someone',
                    'theirs' => $theirs,
                    'reason' => 'It does not match what this account is for.',
                ];
            }
        }

        $profile = $user->accountProfile;
        foreach ($this->x->timelineSkipped($account, $account->nicheFor($profile), trim($account->descriptionFor($profile).' '.$account->audienceFor($profile))) as $row) {
            $theirs = trim((string) ($row['theirs'] ?? ''));
            if ($theirs === '' || $this->alreadyListed($skipped, $theirs) || $this->alreadyListed($answered, $theirs)) {
                continue;
            }
            $skipped[] = [
                'author' => (string) ($row['author'] ?? 'Someone'),
                'theirs' => $theirs,
                'reason' => (string) ($row['reason'] ?? 'It does not match what this account is for.'),
            ];
            if (count($skipped) >= 5) {
                break;
            }
        }

        return [
            'answered' => array_slice($answered, 0, 5),
            'skipped' => array_slice($skipped, 0, 5),
        ];
    }

    /**
     * @param  list<array{theirs?: string}>  $rows
     */
    private function alreadyListed(array $rows, string $theirs): bool
    {
        foreach ($rows as $row) {
            if (trim((string) ($row['theirs'] ?? '')) === $theirs) {
                return true;
            }
        }

        return false;
    }

    private function plainReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || str_contains($reason, 'Nothing loud enough')) {
            return 'Nothing in this niche was loud enough. It did not post to fill the day.';
        }

        return $reason;
    }
}
