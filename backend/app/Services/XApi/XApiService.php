<?php

namespace App\Services\XApi;

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class XApiService
{
    public const SCOPES = 'tweet.read tweet.write users.read like.read offline.access';

    public function configured(): bool
    {
        return filled(config('services.x.client_id')) && filled(config('services.x.client_secret'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function authorizationUrl(array $payload): string
    {
        $verifier = $this->verifier();
        $state = Crypt::encryptString(json_encode([
            ...$payload,
            'verifier' => $verifier,
            'exp' => now()->addMinutes(10)->timestamp,
        ]));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.x.client_id'),
            'redirect_uri' => config('services.x.redirect'),
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $this->challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        return 'https://twitter.com/i/oauth2/authorize?'.$query;
    }

    /**
     * @return array<string, mixed>
     */
    public function exchange(string $code, string $verifier): array
    {
        $response = Http::asForm()
            ->withBasicAuth((string) config('services.x.client_id'), (string) config('services.x.client_secret'))
            ->post('https://api.twitter.com/2/oauth2/token', [
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.x.redirect'),
                'code_verifier' => $verifier,
            ]);

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException($this->apiError($response));
        }

        return $response->json();
    }

    /**
     * @return array{id: string, email: null, name: string, username: ?string}
     */
    public function profile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://api.twitter.com/2/users/me', [
                'user.fields' => 'name,username',
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->apiError($response));
        }

        $info = $response->json('data') ?? [];

        return [
            'id' => (string) ($info['id'] ?? ''),
            'email' => null,
            'name' => (string) ($info['name'] ?? ''),
            'username' => isset($info['username']) ? strtolower((string) $info['username']) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $token
     */
    public function rememberToken(string $xUserId, array $token): void
    {
        Cache::put($this->tokenCacheKey($xUserId), $token, now()->addMinutes(20));
    }

    public function attachRemembered(User $user): ?ConnectedAccount
    {
        if (! $user->x_id) {
            return null;
        }

        $token = Cache::pull($this->tokenCacheKey($user->x_id));
        if (! is_array($token) || ! is_string($token['access_token'] ?? null)) {
            return null;
        }

        return $this->store($user, $token, $this->profile($token['access_token']));
    }

    public function connectFromCode(User $user, string $code, string $verifier): ConnectedAccount
    {
        $token = $this->exchange($code, $verifier);
        $profile = $this->profile($token['access_token']);

        if (! $user->x_id) {
            $user->x_id = $profile['id'];
            $user->save();
        }

        return $this->store($user, $token, $profile);
    }

    /**
     * @param  array<string, mixed>  $token
     * @param  array{id: string, username: ?string}  $profile
     */
    public function store(User $user, array $token, array $profile): ConnectedAccount
    {
        $values = [
            'username' => $profile['username'],
            'access_token' => $token['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 7200)),
            'is_active' => true,
        ];

        if (is_string($token['refresh_token'] ?? null) && $token['refresh_token'] !== '') {
            $values['refresh_token'] = $token['refresh_token'];
        }

        return ConnectedAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => 'x',
                'platform_account_id' => $profile['id'],
            ],
            $values,
        );
    }

    public function publishText(ConnectedAccount $account, string $content, string $type): string
    {
        $parts = $type === 'thread'
            ? array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", $content) ?: [])))
            : [trim($content)];

        if ($parts === [] || $parts === ['']) {
            throw new RuntimeException('That draft is empty.');
        }

        $token = $this->accessToken($account);
        $first = null;
        $replyTo = null;

        foreach ($parts as $part) {
            $body = ['text' => $part];
            if ($replyTo) {
                $body['reply'] = ['in_reply_to_tweet_id' => $replyTo];
            }

            $response = Http::withToken($token)->post('https://api.twitter.com/2/tweets', $body);
            if ($response->failed()) {
                throw new RuntimeException($this->apiError($response));
            }

            $replyTo = (string) $response->json('data.id');
            $first ??= $replyTo;
        }

        return (string) $first;
    }

    public function likeTweet(ConnectedAccount $account, string $tweetId): void
    {
        $token = $this->accessToken($account);
        $userId = (string) ($account->platform_account_id ?: $this->authenticatedUserId($token));
        $response = Http::withToken($token)->post('https://api.twitter.com/2/users/'.$userId.'/likes', [
            'tweet_id' => $tweetId,
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->apiError($response));
        }
    }

    public function replyToTweet(ConnectedAccount $account, string $tweetId, string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('That reply is empty.');
        }

        $response = Http::withToken($this->accessToken($account))->post('https://api.twitter.com/2/tweets', [
            'text' => mb_substr($text, 0, 270),
            'reply' => ['in_reply_to_tweet_id' => $tweetId],
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->apiError($response));
        }

        return (string) $response->json('data.id');
    }

    /**
     * Followers, plus impressions from this account's recent posts and comments.
     * When the niche can be searched, X's relevancy order decides which of those count.
     *
     * @return array{followers: int, impressions: int, engagement_rate: float, ranked_for_niche: bool, tweets: list<array<string, mixed>>}
     */
    public function accountActivity(ConnectedAccount $account, ?string $niche = null): array
    {
        $nicheKey = substr(md5(mb_strtolower(trim((string) $niche))), 0, 12);

        return Cache::remember('x-activity:v2:'.$account->id.':'.$nicheKey, now()->addMinutes(10), function () use ($account, $niche) {
            $token = $this->accessToken($account);
            $me = Http::withToken($token)->get('https://api.twitter.com/2/users/me', [
                'user.fields' => 'public_metrics',
            ]);

            if ($me->failed()) {
                throw new RuntimeException($this->apiError($me));
            }

            $user = $me->json('data') ?? [];
            $timeline = $this->ownPostsAndComments($token, (string) ($user['id'] ?? 'me'));
            $byId = [];
            foreach ($timeline as $row) {
                $byId[$row['id']] = $row;
            }

            $rankedForNiche = false;
            try {
                $ranked = $this->nicheRankedOwn($token, $account, (string) $niche);
            } catch (\Throwable) {
                $ranked = [];
            }

            if ($ranked !== []) {
                $chosen = $this->withOwnedMetrics($ranked, $byId);
                $rankedForNiche = true;
            } else {
                [$chosen, $rankedForNiche] = $this->rankOwnForNiche($timeline, (string) $niche);
            }

            $impressions = 0;
            $engagements = 0;
            foreach ($chosen as $row) {
                $impressions += $row['impressions'];
                $engagements += $row['likes'] + $row['reposts'] + $row['replies'];
            }

            return [
                'followers' => (int) ($user['public_metrics']['followers_count'] ?? 0),
                'impressions' => $impressions,
                'engagement_rate' => $impressions > 0 ? round(($engagements / $impressions) * 100, 1) : 0,
                'ranked_for_niche' => $rankedForNiche,
                'tweets' => $chosen,
            ];
        });
    }

    /**
     * Topics X is showing for this account, or topics from the account's own posts.
     * Cached so opening the app twice does not call X again. No AI.
     *
     * @return array{source: string, topics: list<array{name: string, posts: int|null, impressions: int|null, likes: int|null, replies: int|null, figures: string}>}
     */
    public function accountTopics(ConnectedAccount $account): array
    {
        $cached = Cache::get($this->topicCacheKey($account));
        if (is_array($cached) && isset($cached['topics']) && is_array($cached['topics'])) {
            return $cached;
        }

        $token = $this->accessToken($account);
        $topics = $this->trendsForAccount($token);
        $source = 'trends';

        if ($topics === []) {
            $topics = $this->topicsFromOwnPosts($token, $account);
            $source = 'posts';
        }

        $payload = [
            'source' => $source,
            'topics' => $topics,
        ];

        if ($topics !== []) {
            Cache::put($this->topicCacheKey($account), $payload, now()->addMinutes(20));
        }

        return $payload;
    }

    /**
     * Mentions and replies on this account. Cached so the page can be reopened without another pull.
     *
     * @return array{posts: list<array<string, mixed>>, errors: list<string>}
     */
    public function replyFeed(ConnectedAccount $account): array
    {
        $cached = Cache::get($this->replyCacheKey($account));
        if (is_array($cached) && isset($cached['posts']) && is_array($cached['posts'])) {
            return $cached;
        }

        $ownId = (string) ($account->platform_account_id ?? '');
        $posts = [];
        $errors = [];

        foreach ([
            fn () => $this->mentions($account),
            fn () => $this->repliesToAccount($account),
        ] as $load) {
            try {
                $posts = array_merge($posts, $load());
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $seen = [];
        $unique = [];
        foreach ($posts as $post) {
            $id = (string) ($post['id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            if ($ownId !== '' && (string) ($post['author_id'] ?? '') === $ownId) {
                continue;
            }
            $seen[$id] = true;
            unset($post['author_id']);
            $unique[] = $post;
        }

        usort($unique, fn (array $a, array $b) => $this->replyRank($b) <=> $this->replyRank($a));

        $feed = [
            'posts' => array_slice($unique, 0, 5),
            'errors' => $errors,
        ];

        if ($errors === []) {
            Cache::put($this->replyCacheKey($account), $feed, now()->addMinutes(10));
        }

        return $feed;
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function replyRank(array $post): int
    {
        $engagement = ((int) ($post['likes'] ?? 0) * 2)
            + ((int) ($post['replies'] ?? 0) * 3)
            + (int) ($post['reposts'] ?? 0)
            + intdiv((int) ($post['impressions'] ?? 0), 50);
        $fresh = max(0, 180 - (int) ($post['minutes_ago'] ?? 0));

        return ($engagement * 10) + $fresh;
    }

    /**
     * @return list<array{name: string, posts: int|null, impressions: int|null, likes: int|null, replies: int|null, figures: string}>
     */
    private function trendsForAccount(string $token): array
    {
        foreach ([
            'https://api.twitter.com/2/users/personalized_trends',
            'https://api.twitter.com/2/trends/by/woeid/23424977',
            'https://api.twitter.com/2/trends/by/woeid/23424975',
        ] as $url) {
            try {
                $response = Http::withToken($token)->timeout(8)->get($url);
            } catch (\Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $topics = [];
            foreach ($this->topicsFromTrends($response->json() ?? []) as $topic) {
                if (! $this->isEnglishPhrase($topic['name'])) {
                    continue;
                }
                $posts = $topic['tweet_count'] > 0 ? $topic['tweet_count'] : null;
                $topics[] = $this->topicRow($topic['name'], $posts, null, null, null);
                if (count($topics) === 8) {
                    break;
                }
            }

            if ($topics !== []) {
                return $topics;
            }
        }

        return [];
    }

    /**
     * A topic people can read in English: a short phrase, not a hashtag or another language.
     */
    public function isEnglishPhrase(string $name): bool
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($name === '' || str_starts_with($name, '#')) {
            return false;
        }

        if (preg_match('/[#@]|[^\x00-\x7F]/', $name)) {
            return false;
        }

        $words = preg_split('/[^A-Za-z]+/', $name) ?: [];
        $words = array_values(array_filter($words, fn ($word) => strlen($word) >= 2));

        return count($words) >= 2;
    }

    /**
     * @return list<array{name: string, posts: int|null, impressions: int|null, likes: int|null, replies: int|null, figures: string}>
     */
    private function topicsFromOwnPosts(string $token, ConnectedAccount $account): array
    {
        try {
            $userId = (string) ($account->platform_account_id ?: $this->authenticatedUserId($token));
            $rows = $userId === '' ? [] : $this->ownPostsAndComments($token, $userId);
        } catch (\Throwable) {
            return [];
        }

        usort($rows, fn (array $a, array $b) => $b['impressions'] <=> $a['impressions']);
        $topics = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = trim((string) preg_replace('/https?:\/\/\S+/', '', (string) $row['content']));
            $name = trim((string) preg_replace('/#\S+/', '', $name));
            $name = trim((string) preg_replace('/\s+/', ' ', $name));
            $key = mb_strtolower($name);
            if ($key === '' || isset($seen[$key]) || ! $this->isEnglishPhrase($name)) {
                continue;
            }
            $seen[$key] = true;
            $topics[] = $this->topicRow(mb_substr($name, 0, 80), 1, $row['impressions'], $row['likes'], $row['replies']);
            if (count($topics) === 8) {
                break;
            }
        }

        return $topics;
    }

    /**
     * @return array{name: string, posts: int|null, impressions: int|null, likes: int|null, replies: int|null, figures: string}
     */
    private function topicRow(string $name, ?int $posts, ?int $impressions, ?int $likes, ?int $replies): array
    {
        $parts = [];
        if ($impressions !== null) {
            $parts[] = number_format($impressions).' impressions';
        }
        if ($likes !== null) {
            $parts[] = number_format($likes).' likes';
        }
        if ($replies !== null) {
            $parts[] = number_format($replies).' replies';
        }
        if ($posts !== null && $impressions === null) {
            $parts[] = number_format($posts).' posts';
        } elseif ($posts !== null && $posts > 1) {
            $parts[] = number_format($posts).' posts';
        }

        return [
            'name' => $name,
            'posts' => $posts,
            'impressions' => $impressions,
            'likes' => $likes,
            'replies' => $replies,
            'figures' => implode(' · ', $parts),
        ];
    }

    private function topicCacheKey(ConnectedAccount $account): string
    {
        return 'x-topics:en:'.$account->id;
    }

    private function replyCacheKey(ConnectedAccount $account): string
    {
        return 'x-replies:'.$account->id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mentions(ConnectedAccount $account): array
    {
        $token = $this->accessToken($account);
        $userId = (string) ($account->platform_account_id ?: $this->authenticatedUserId($token));
        $response = Http::withToken($token)->get('https://api.twitter.com/2/users/'.$userId.'/mentions', [
            'max_results' => 25,
            'tweet.fields' => 'public_metrics,created_at,author_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,public_metrics',
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->apiError($response));
        }

        return $this->normalizeTweets($response->json() ?? [], 'mention');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function repliesToAccount(ConnectedAccount $account): array
    {
        $username = strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $account->username));
        if ($username === '') {
            return [];
        }

        return $this->searchRecent(
            $account,
            'to:'.$username.' -from:'.$username.' -is:retweet',
            'mention',
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchNiche(ConnectedAccount $account, string $niche): array
    {
        $query = $this->nicheQuery($niche);
        if ($query === null) {
            return [];
        }

        return $this->searchRecent($account, $query, 'niche');
    }

    /**
     * @return list<array{name: string, tweet_count: int}>
     */
    public function trendingTopics(): array
    {
        $token = $this->appBearer();
        $response = Http::withToken($token)->get('https://api.twitter.com/2/trends/by/woeid/1');

        if ($response->successful()) {
            $topics = $this->topicsFromTrends($response->json() ?? []);
            if ($topics !== []) {
                return $topics;
            }
        }

        $search = Http::withToken($token)->get('https://api.twitter.com/2/tweets/search/recent', [
            'query' => '("breaking" OR announced OR "just in") -is:retweet lang:en',
            'max_results' => 10,
            'tweet.fields' => 'public_metrics',
        ]);

        if ($search->failed()) {
            throw new RuntimeException($this->apiError($response->failed() ? $response : $search));
        }

        $topics = [];
        foreach ($search->json('data') ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim(preg_replace('/\s+/', ' ', (string) ($row['text'] ?? '')) ?? '');
            if ($name === '') {
                continue;
            }
            $metrics = $row['public_metrics'] ?? [];
            $topics[] = [
                'name' => mb_substr($name, 0, 80),
                'tweet_count' => (int) (($metrics['like_count'] ?? 0) + ($metrics['retweet_count'] ?? 0)),
            ];
        }

        return $topics;
    }

    public function accessToken(ConnectedAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->gt(now()->addMinute()) && $account->access_token) {
            return $account->access_token;
        }

        if (! $account->refresh_token) {
            if (! $account->access_token) {
                throw new RuntimeException('Reconnect X to refresh this account.');
            }

            return $account->access_token;
        }

        $response = Http::asForm()
            ->withBasicAuth((string) config('services.x.client_id'), (string) config('services.x.client_secret'))
            ->post('https://api.twitter.com/2/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id' => config('services.x.client_id'),
            ]);

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException($this->apiError($response));
        }

        $account->access_token = $response->json('access_token');
        if (is_string($response->json('refresh_token'))) {
            $account->refresh_token = $response->json('refresh_token');
        }
        $account->token_expires_at = now()->addSeconds((int) ($response->json('expires_in') ?? 7200));
        $account->save();

        return $account->access_token;
    }

    /**
     * This account's posts and the comments it wrote. Retweets are left out; replies stay in.
     *
     * @return list<array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, created_at: string}>
     */
    private function ownPostsAndComments(string $token, string $userId): array
    {
        $response = Http::withToken($token)->get('https://api.twitter.com/2/users/'.$userId.'/tweets', [
            'max_results' => 100,
            'exclude' => 'retweets',
            'tweet.fields' => 'public_metrics,created_at,referenced_tweets',
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->apiError($response));
        }

        $rows = [];
        foreach ($response->json('data') ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = $this->activityRow($row);
            if ($normalized['id'] === '') {
                continue;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * X recent search, limited to this account, ordered the way X ranks the niche.
     *
     * @return list<array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, created_at: string}>
     */
    private function nicheRankedOwn(string $token, ConnectedAccount $account, string $niche): array
    {
        $username = strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $account->username));
        $nicheQuery = $this->nicheQuery($niche);
        if ($username === '' || $nicheQuery === null) {
            return [];
        }

        $response = Http::withToken($token)->timeout(6)->get('https://api.twitter.com/2/tweets/search/recent', [
            'query' => 'from:'.$username.' '.$nicheQuery,
            'max_results' => 100,
            'sort_order' => 'relevancy',
            'tweet.fields' => 'public_metrics,created_at,referenced_tweets,author_id',
        ]);

        if ($response->failed()) {
            return [];
        }

        $ownId = (string) ($account->platform_account_id ?? '');
        $rows = [];
        foreach ($response->json('data') ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $authorId = (string) ($row['author_id'] ?? '');
            if ($ownId !== '' && $authorId !== '' && $authorId !== $ownId) {
                continue;
            }
            $normalized = $this->activityRow($row);
            if ($normalized['id'] === '') {
                continue;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @param  list<array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, created_at: string}>  $ranked
     * @param  array<string, array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, created_at: string}>  $owned
     * @return list<array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, created_at: string}>
     */
    private function withOwnedMetrics(array $ranked, array $owned): array
    {
        $rows = [];
        foreach ($ranked as $row) {
            $live = $owned[$row['id']] ?? null;
            if ($live !== null) {
                $row['impressions'] = max($row['impressions'], $live['impressions']);
                $row['likes'] = max($row['likes'], $live['likes']);
                $row['reposts'] = max($row['reposts'], $live['reposts']);
                $row['replies'] = max($row['replies'], $live['replies']);
                if ($row['kind'] === 'post' && $live['kind'] === 'comment') {
                    $row['kind'] = 'comment';
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * When X relevancy is unavailable, keep posts and comments that match the niche
     * and order them by the impressions X already recorded.
     *
     * @param  list<array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, created_at: string}>  $rows
     * @return array{0: list<array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, created_at: string}>, 1: bool}
     */
    private function rankOwnForNiche(array $rows, string $niche): array
    {
        $words = $this->nicheWords($niche);
        $byImpressions = function (array $a, array $b): int {
            return $b['impressions'] <=> $a['impressions'];
        };

        if ($words === []) {
            usort($rows, $byImpressions);

            return [$rows, false];
        }

        $matched = [];
        foreach ($rows as $row) {
            $text = mb_strtolower($row['content']);
            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    $matched[] = $row;
                    break;
                }
            }
        }

        if ($matched === []) {
            usort($rows, $byImpressions);

            return [$rows, false];
        }

        usort($matched, $byImpressions);

        return [$matched, true];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, created_at: string}
     */
    private function activityRow(array $row): array
    {
        $metrics = is_array($row['public_metrics'] ?? null) ? $row['public_metrics'] : [];

        return [
            'id' => (string) ($row['id'] ?? ''),
            'content' => (string) ($row['text'] ?? ''),
            'kind' => $this->isComment($row) ? 'comment' : 'post',
            'impressions' => (int) ($metrics['impression_count'] ?? 0),
            'likes' => (int) ($metrics['like_count'] ?? 0),
            'reposts' => (int) ($metrics['retweet_count'] ?? 0),
            'replies' => (int) ($metrics['reply_count'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isComment(array $row): bool
    {
        foreach ($row['referenced_tweets'] ?? [] as $ref) {
            if (is_array($ref) && ($ref['type'] ?? '') === 'replied_to') {
                return true;
            }
        }

        return false;
    }

    /**
     * English posts people are writing in this niche right now.
     *
     * @return list<array{name: string, posts: int|null, impressions: int|null, likes: int|null, replies: int|null, figures: string}>
     */
    public function nicheConversations(ConnectedAccount $account, string $niche): array
    {
        $query = $this->nicheQuery($niche);
        if ($query === null) {
            return [];
        }

        $cacheKey = 'x-niche-topics:v3:'.$account->id.':'.md5(mb_strtolower($query));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $query .= ' lang:en';
        $ownId = (string) ($account->platform_account_id ?? '');

        try {
            $response = $this->recentNicheSearch($account, $query);
        } catch (\Throwable) {
            return [];
        }

        if ($response === null) {
            return [];
        }

        $users = collect($response->json('includes.users') ?? [])->keyBy('id');
        $topics = [];
        $seen = [];
        foreach ($response->json('data') ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $authorId = (string) ($row['author_id'] ?? '');
            if ($ownId !== '' && $authorId === $ownId) {
                continue;
            }
            $created = isset($row['created_at']) ? \Illuminate\Support\Carbon::parse($row['created_at']) : null;
            $minutesAgo = $created ? (int) abs($created->diffInMinutes(now())) : null;
            if ($minutesAgo !== null && $minutesAgo > 12 * 60) {
                continue;
            }
            $text = trim((string) preg_replace('/https?:\/\/\S+|#\S+|@\S+/', '', (string) ($row['text'] ?? '')));
            $text = trim((string) preg_replace('/\s+/', ' ', $text));
            $key = mb_strtolower($text);
            if ($key === '' || isset($seen[$key]) || ! $this->isEnglishPhrase($text)) {
                continue;
            }
            $seen[$key] = true;
            $metrics = is_array($row['public_metrics'] ?? null) ? $row['public_metrics'] : [];
            $author = $users->get($authorId);
            $username = is_array($author) ? (string) ($author['username'] ?? '') : '';
            $followers = (int) (is_array($author) ? ($author['public_metrics']['followers_count'] ?? 0) : 0);
            $topic = $this->topicRow(
                mb_substr($text, 0, 80),
                null,
                isset($metrics['impression_count']) ? (int) $metrics['impression_count'] : null,
                isset($metrics['like_count']) ? (int) $metrics['like_count'] : null,
                isset($metrics['reply_count']) ? (int) $metrics['reply_count'] : null,
            );
            $topic['text'] = mb_substr($text, 0, 240);
            $topic['tweet_id'] = (string) ($row['id'] ?? '');
            $topic['minutes_ago'] = $minutesAgo;
            $topic['author'] = $username !== '' ? '@'.$username : null;
            $topic['author_followers'] = $followers;
            $topics[] = $topic;
            if (count($topics) === 6) {
                break;
            }
        }

        Cache::put($cacheKey, $topics, now()->addMinutes($topics === [] ? 3 : 4));

        return $topics;
    }

    /**
     * Names that are loud on X right now, outside this account's own search.
     * Empty when the trends endpoint is unavailable. Cached so every account does not call it.
     *
     * @return list<array{name: string, tweet_count: int}>
     */
    public function loudRoom(): array
    {
        if (! $this->configured()) {
            return [];
        }

        $cached = Cache::get('x-loud-room:v1');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::withToken($this->appBearer())->timeout(6)->get('https://api.twitter.com/2/trends/by/woeid/1');
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            Cache::put('x-loud-room:v1', [], now()->addMinutes(8));

            return [];
        }

        $topics = [];
        foreach ($this->topicsFromTrends($response->json() ?? []) as $topic) {
            if (! $this->isEnglishPhrase($topic['name']) || $topic['tweet_count'] < 1500) {
                continue;
            }
            $topics[] = $topic;
            if (count($topics) === 4) {
                break;
            }
        }

        Cache::put('x-loud-room:v1', $topics, now()->addMinutes(8));

        return $topics;
    }

    private function recentNicheSearch(ConnectedAccount $account, string $query): ?\Illuminate\Http\Client\Response
    {
        $params = [
            'query' => $query,
            'max_results' => 20,
            'sort_order' => 'recency',
            'start_time' => now()->subHours(12)->utc()->format('Y-m-d\TH:i:s\Z'),
            'tweet.fields' => 'public_metrics,lang,created_at,author_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,public_metrics',
        ];

        $response = Http::withToken($this->accessToken($account))->timeout(8)->get(
            'https://api.twitter.com/2/tweets/search/recent',
            $params,
        );

        if ($response->successful()) {
            return $response;
        }

        unset($params['sort_order']);
        $retry = Http::withToken($this->accessToken($account))->timeout(8)->get(
            'https://api.twitter.com/2/tweets/search/recent',
            $params,
        );

        return $retry->successful() ? $retry : null;
    }

    /**
     * @return list<string>
     */
    private function nicheWords(string $niche): array
    {
        $phrase = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $niche) ?? '') ?? '');
        if ($phrase === '' || in_array(strtolower($phrase), ['your niche', 'general', 'niche'], true)) {
            return [];
        }

        $words = array_values(array_unique(array_filter(
            preg_split('/\s+/', mb_strtolower($phrase)) ?: [],
            fn ($word) => is_string($word)
                && mb_strlen($word) >= 3
                && ! in_array($word, ['the', 'and', 'for', 'with', 'your', 'from', 'that', 'this', 'you', 'are'], true)
        )));

        return array_slice($words, 0, 6);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchRecent(ConnectedAccount $account, string $query, string $kind): array
    {
        $token = $this->accessToken($account);
        $response = Http::withToken($token)->get('https://api.twitter.com/2/tweets/search/recent', [
            'query' => $query,
            'max_results' => 25,
            'tweet.fields' => 'public_metrics,created_at,author_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,public_metrics',
        ]);

        if ($response->failed()) {
            throw new RuntimeException($this->apiError($response));
        }

        return $this->normalizeTweets($response->json() ?? [], $kind);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    private function normalizeTweets(array $json, string $kind): array
    {
        $users = collect($json['includes']['users'] ?? [])->keyBy('id');
        $posts = [];

        foreach ($json['data'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $author = $users->get($row['author_id'] ?? '');
            $metrics = is_array($row['public_metrics'] ?? null) ? $row['public_metrics'] : [];
            $likes = (int) ($metrics['like_count'] ?? 0);
            $reposts = (int) ($metrics['retweet_count'] ?? 0);
            $replies = (int) ($metrics['reply_count'] ?? 0);
            $impressions = (int) ($metrics['impression_count'] ?? 0);
            $created = isset($row['created_at']) ? \Illuminate\Support\Carbon::parse($row['created_at']) : now();

            $posts[] = [
                'id' => (string) ($row['id'] ?? ''),
                'author_id' => (string) ($row['author_id'] ?? ''),
                'author' => '@'.(is_array($author) ? ($author['username'] ?? 'x') : 'x'),
                'author_followers' => (int) (is_array($author) ? ($author['public_metrics']['followers_count'] ?? 0) : 0),
                'content' => (string) ($row['text'] ?? ''),
                'likes' => $likes,
                'reposts' => $reposts,
                'replies' => $replies,
                'impressions' => $impressions,
                'minutes_ago' => max(0, (int) $created->diffInMinutes(now())),
                'opportunity_score' => (int) min(100, round(20 + ($replies * 2) + ($likes / 20) + ($reposts / 10))),
                'kind' => $kind,
            ];
        }

        return $posts;
    }

    private function nicheQuery(string $niche): ?string
    {
        $words = $this->nicheWords($niche);
        if ($words === []) {
            return null;
        }

        $terms = count($words) > 1
            ? '("'.implode(' ', array_slice($words, 0, 4)).'" OR '.implode(' OR ', $words).')'
            : $words[0];

        return $terms.' -is:retweet';
    }

    private function authenticatedUserId(string $token): string
    {
        $response = Http::withToken($token)->get('https://api.twitter.com/2/users/me');
        if ($response->failed() || ! is_string($response->json('data.id'))) {
            throw new RuntimeException($this->apiError($response));
        }

        return $response->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<array{name: string, tweet_count: int}>
     */
    private function topicsFromTrends(array $json): array
    {
        $rows = $json['data'] ?? [];
        if (isset($rows['trends']) && is_array($rows['trends'])) {
            $rows = $rows['trends'];
        }

        $topics = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $row['trend_name'] ?? $row['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $topics[] = [
                'name' => trim($name),
                'tweet_count' => (int) ($row['tweet_count'] ?? $row['tweet_volume'] ?? 0),
            ];
        }

        return $topics;
    }

    private function appBearer(): string
    {
        $token = Cache::remember('x:app-bearer', now()->addMinutes(50), function () {
            $response = Http::asForm()
                ->withBasicAuth((string) config('services.x.client_id'), (string) config('services.x.client_secret'))
                ->post('https://api.twitter.com/2/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->failed() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException($this->apiError($response));
            }

            return $response->json('access_token');
        });

        if (! is_string($token) || $token === '') {
            Cache::forget('x:app-bearer');
            throw new RuntimeException('X did not return an app token.');
        }

        return $token;
    }

    private function apiError(Response $response): string
    {
        $detail = $response->json('detail') ?? $response->json('title') ?? $response->json('error_description');
        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        $errors = $response->json('errors');
        if (is_array($errors) && isset($errors[0]['message']) && is_string($errors[0]['message'])) {
            return $errors[0]['message'];
        }

        return 'X did not accept that request.';
    }

    private function tokenCacheKey(string $xUserId): string
    {
        return 'x-oauth-token:'.$xUserId;
    }

    private function verifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
