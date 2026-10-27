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
     * @return array{followers: int, impressions: int, engagement_rate: float, profile_clicks: int, views_known: bool, ranked_for_niche: bool, tweets: list<array<string, mixed>>}
     */
    public function accountActivity(ConnectedAccount $account, ?string $niche = null): array
    {
        $nicheKey = substr(md5(mb_strtolower(trim((string) $niche))), 0, 12);
        $cacheKey = 'x-activity:v7:'.$account->id.':'.$nicheKey;
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['followers'], $cached['tweets'], $cached['views_known'])) {
            return $cached;
        }

        $token = $this->accessToken($account);
        $me = Http::withToken($token)->get('https://api.twitter.com/2/users/me', [
            'user.fields' => 'public_metrics',
        ]);

        if ($me->failed()) {
            throw new RuntimeException($this->apiError($me));
        }

        $user = $me->json('data') ?? [];
        $loaded = $this->ownPostsAndComments($token, (string) ($user['id'] ?? 'me'));
        $chosen = $loaded['rows'];

        $impressions = 0;
        $engagements = 0;
        $likes = 0;
        $replies = 0;
        $reposts = 0;
        $profileClicks = 0;
        foreach ($chosen as $row) {
            $impressions += $row['impressions'];
            $likes += $row['likes'];
            $replies += $row['replies'];
            $reposts += $row['reposts'];
            $engagements += $row['likes'] + $row['reposts'] + $row['replies'];
            $profileClicks += $row['profile_clicks'];
        }

        $likesKnown = $chosen === [] || $this->rowsKnowLikes($chosen);
        $metricsLoaded = $loaded['complete'] && $likesKnown;
        $viewsKnown = $metricsLoaded && $chosen !== [] && $loaded['views_known'] && $this->rowsKnowViews($chosen);
        $payload = [
            'followers' => (int) ($user['public_metrics']['followers_count'] ?? 0),
            'impressions' => $impressions,
            'engagements' => $engagements,
            'likes' => $likes,
            'replies' => $replies,
            'reposts' => $reposts,
            'engagement_rate' => $viewsKnown && $impressions > 0 ? round(($engagements / $impressions) * 100, 1) : 0,
            'profile_clicks' => $profileClicks,
            'views_known' => $viewsKnown,
            'metrics_loaded' => $metricsLoaded,
            'ranked_for_niche' => false,
            'tweets' => $chosen,
        ];

        if ($metricsLoaded) {
            $payload = $this->rememberTotals($account, $payload);
            Cache::put($cacheKey, $payload, now()->addMinutes(10));
        }

        return $payload;
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
            $loaded = $userId === '' ? ['rows' => [], 'complete' => true, 'views_known' => true] : $this->ownPostsAndComments($token, $userId);
            $rows = $loaded['rows'];
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
     * View counts live on organic_metrics, which X only returns for this account's
     * own posts from the last 30 days. public_metrics has likes, not views.
     *
     * @return array{rows: list<array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, profile_clicks: int, views_known: bool, created_at: string}>, complete: bool, views_known: bool}
     */
    private function ownPostsAndComments(string $token, string $userId): array
    {
        $public = $this->tweetPage($token, $userId, false);
        $owned = $this->tweetPage($token, $userId, true);

        if ($public === null && $owned === null) {
            return ['rows' => [], 'complete' => false, 'views_known' => false];
        }

        $rows = $public ?? $owned ?? [];
        if (is_array($public) && is_array($owned)) {
            $byId = [];
            foreach ($owned as $row) {
                $byId[$row['id']] = $row;
            }
            foreach ($rows as $i => $row) {
                $extra = $byId[$row['id']] ?? null;
                if (! is_array($extra) || ! ($extra['views_known'] ?? false) || (int) $extra['impressions'] <= 0) {
                    continue;
                }
                $rows[$i]['impressions'] = (int) $extra['impressions'];
                $rows[$i]['views_known'] = true;
                $rows[$i]['profile_clicks'] = max((int) ($row['profile_clicks'] ?? 0), (int) ($extra['profile_clicks'] ?? 0));
            }
        }

        return [
            'rows' => $rows,
            'complete' => true,
            'views_known' => $this->rowsKnowViews($rows),
        ];
    }

    /**
     * @return list<array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, profile_clicks: int, created_at: string}>|null
     */
    private function tweetPage(string $token, string $userId, bool $ownedMetrics): ?array
    {
        $query = [
            'max_results' => 100,
            'exclude' => 'retweets',
            'tweet.fields' => $ownedMetrics
                ? 'public_metrics,organic_metrics,non_public_metrics,created_at,referenced_tweets'
                : 'public_metrics,created_at,referenced_tweets',
        ];
        if ($ownedMetrics) {
            $query['start_time'] = now()->subDays(29)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $response = Http::withToken($token)->get('https://api.twitter.com/2/users/'.$userId.'/tweets', $query);
        if ($response->failed()) {
            return null;
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
                $row['profile_clicks'] = max((int) ($row['profile_clicks'] ?? 0), (int) ($live['profile_clicks'] ?? 0));
                if ($live['views_known'] ?? false) {
                    $row['views_known'] = true;
                }
                if ($row['kind'] === 'post' && $live['kind'] === 'comment') {
                    $row['kind'] = 'comment';
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: string, content: string, kind: string, impressions: int, likes: int, reposts: int, replies: int, profile_clicks: int, created_at: string}
     */
    private function activityRow(array $row): array
    {
        $public = is_array($row['public_metrics'] ?? null) ? $row['public_metrics'] : [];
        $organic = is_array($row['organic_metrics'] ?? null) ? $row['organic_metrics'] : [];
        $private = is_array($row['non_public_metrics'] ?? null) ? $row['non_public_metrics'] : [];
        $organicViews = array_key_exists('impression_count', $organic) ? (int) $organic['impression_count'] : null;
        $privateViews = array_key_exists('impression_count', $private) ? (int) $private['impression_count'] : null;
        $publicViews = array_key_exists('impression_count', $public) ? (int) $public['impression_count'] : null;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'content' => (string) ($row['text'] ?? ''),
            'kind' => $this->isComment($row) ? 'comment' : 'post',
            'impressions' => $organicViews ?? $privateViews ?? (($publicViews ?? 0) > 0 ? $publicViews : 0),
            'likes' => (int) ($public['like_count'] ?? $organic['like_count'] ?? 0),
            'reposts' => (int) ($public['retweet_count'] ?? $organic['retweet_count'] ?? 0),
            'replies' => (int) ($public['reply_count'] ?? $organic['reply_count'] ?? 0),
            'profile_clicks' => (int) ($organic['user_profile_clicks'] ?? $private['user_profile_clicks'] ?? 0),
            'views_known' => $organicViews !== null || $privateViews !== null || ($publicViews ?? 0) > 0,
            'likes_known' => $public !== [] || $organic !== [],
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function rowsKnowViews(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['views_known'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function rowsKnowLikes(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['likes_known'] ?? false) {
                return true;
            }
        }

        return false;
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
     * Posts this person wrote on X, so a draft can follow their voice.
     *
     * @return list<string>
     */
    public function voiceSamples(ConnectedAccount $account): array
    {
        try {
            $activity = $this->accountActivity($account);
        } catch (\Throwable) {
            return [];
        }

        $lines = [];
        foreach ($activity['tweets'] as $tweet) {
            if (($tweet['kind'] ?? '') === 'comment') {
                continue;
            }
            $text = trim((string) preg_replace('/\s+/', ' ', (string) ($tweet['content'] ?? '')));
            if ($text === '') {
                continue;
            }
            $lines[] = mb_substr($text, 0, 180);
            if (count($lines) === 5) {
                break;
            }
        }

        return $lines;
    }

    /**
     * Topics taken from posts this account wrote, when the home timeline has nothing that fits.
     *
     * @return list<array{name: string, posts: int|null, impressions: int|null, likes: int|null, replies: int|null, figures: string}>
     */
    public function ownPostTopics(ConnectedAccount $account): array
    {
        try {
            $activity = $this->accountActivity($account);
        } catch (\Throwable) {
            return [];
        }

        $topics = [];
        $seen = [];
        foreach ($activity['tweets'] as $tweet) {
            if (($tweet['kind'] ?? '') === 'comment') {
                continue;
            }
            $text = trim((string) preg_replace('/https?:\/\/\S+|#\S+|@\S+/', '', (string) ($tweet['content'] ?? '')));
            $text = trim((string) preg_replace('/\s+/', ' ', $text));
            $key = mb_strtolower($text);
            if ($key === '' || isset($seen[$key]) || ! $this->isEnglishPhrase($text) || $this->mentionsBlockedNews($text)) {
                continue;
            }
            $seen[$key] = true;
            $topics[] = $this->topicRow(
                mb_substr($text, 0, 80),
                1,
                ($tweet['views_known'] ?? false) ? (int) $tweet['impressions'] : null,
                (int) ($tweet['likes'] ?? 0),
                (int) ($tweet['replies'] ?? 0),
            );
            if (count($topics) === 5) {
                break;
            }
        }

        return $topics;
    }

    /**
     * @param  array{followers: int, impressions: int, engagements: int, likes: int, replies: int, reposts: int, engagement_rate: float, profile_clicks: int, views_known: bool, ranked_for_niche: bool, tweets: list<array<string, mixed>>}  $payload
     * @return array<string, mixed>
     */
    private function rememberTotals(ConnectedAccount $account, array $payload): array
    {
        $key = 'x-totals:v1:'.$account->id;
        $history = Cache::get($key);
        $history = is_array($history) ? $history : [];
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $previous = is_array($history[$yesterday] ?? null) ? $history[$yesterday] : null;

        if ($payload['views_known']) {
            $impressions = (int) $payload['impressions'];
        } elseif (is_array($history[$today] ?? null) && isset($history[$today]['impressions'])) {
            $impressions = (int) $history[$today]['impressions'];
            $payload['impressions'] = $impressions;
            $payload['views_known'] = $impressions > 0;
            $payload['impressions_held'] = true;
        } elseif ($previous && isset($previous['impressions']) && (int) $previous['impressions'] > 0) {
            $impressions = (int) $previous['impressions'];
            $payload['impressions'] = $impressions;
            $payload['views_known'] = true;
            $payload['impressions_held'] = true;
        } else {
            $impressions = null;
        }

        $history[$today] = [
            'impressions' => $impressions,
            'likes' => (int) $payload['likes'],
            'replies' => (int) $payload['replies'],
            'reposts' => (int) $payload['reposts'],
            'followers' => (int) $payload['followers'],
        ];
        $history = array_slice($history, -14, null, true);
        Cache::put($key, $history, now()->addDays(45));

        $payload['likes_change'] = $previous ? (int) $payload['likes'] - (int) ($previous['likes'] ?? 0) : null;
        $payload['impressions_change'] = $payload['views_known'] && $previous && isset($previous['impressions'])
            ? (int) $payload['impressions'] - (int) $previous['impressions']
            : null;

        return $payload;
    }

    /**
     * Recent posts on this account's home timeline that belong to the niche.
     * One small pull. It does not search the rest of X.
     *
     * @return list<array{name: string, posts: int|null, impressions: int|null, likes: int|null, replies: int|null, figures: string}>
     */
    public function homeFeed(ConnectedAccount $account, string $niche, string $purpose = ''): array
    {
        $userId = (string) ($account->platform_account_id ?? '');
        $purposeWords = $this->purposeWords($purpose);
        if ($userId === '' || ($this->nicheWords($niche) === [] && $purposeWords === [])) {
            return [];
        }

        $cacheKey = 'x-home-feed:v4:'.$account->id.':'.md5(mb_strtolower(trim($niche).'|'.$purpose));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::withToken($this->accessToken($account))->timeout(8)->get(
                'https://api.twitter.com/2/users/'.$userId.'/timelines/reverse_chronological',
                [
                    'max_results' => 20,
                    'tweet.fields' => 'public_metrics,created_at,author_id,lang',
                    'expansions' => 'author_id',
                    'user.fields' => 'username,public_metrics',
                ],
            );
        } catch (\Throwable) {
            return [];
        }

        if ($response->failed()) {
            return [];
        }

        $users = collect($response->json('includes.users') ?? [])->keyBy('id');
        $candidates = [];
        $seen = [];
        foreach ($response->json('data') ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $authorId = (string) ($row['author_id'] ?? '');
            if ($authorId !== '' && $authorId === $userId) {
                continue;
            }
            $lang = (string) ($row['lang'] ?? '');
            if ($lang !== '' && $lang !== 'en') {
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
            $candidates[] = [
                'text' => $text,
                'tweet_id' => (string) ($row['id'] ?? ''),
                'minutes_ago' => $minutesAgo,
                'author' => $username !== '' ? '@'.$username : null,
                'author_followers' => (int) (is_array($author) ? ($author['public_metrics']['followers_count'] ?? 0) : 0),
                'metrics' => $metrics,
            ];
        }

        $topics = [];
        $newsIsTheAccount = $this->mentionsBlockedNews($niche.' '.$purpose);
        foreach ($candidates as $candidate) {
            $text = $candidate['text'];
            $inNiche = $this->textTouchesNiche($text, $niche);
            $forLoyalty = str_contains(mb_strtolower($text), 'united states')
                ? str_ireplace('united states', '', $text)
                : $text;
            $loyalty = $inNiche ? null : $this->loyaltyIn($forLoyalty, $purposeWords);
            if (! $inNiche && $loyalty === null) {
                continue;
            }
            if ($this->mentionsBlockedNews($text) && ! $newsIsTheAccount) {
                continue;
            }
            $metrics = $candidate['metrics'];
            $topic = $this->topicRow(
                mb_substr($text, 0, 80),
                null,
                isset($metrics['impression_count']) ? (int) $metrics['impression_count'] : null,
                isset($metrics['like_count']) ? (int) $metrics['like_count'] : null,
                isset($metrics['reply_count']) ? (int) $metrics['reply_count'] : null,
            );
            $topic['text'] = mb_substr($text, 0, 240);
            $topic['tweet_id'] = $candidate['tweet_id'];
            $topic['minutes_ago'] = $candidate['minutes_ago'];
            $topic['author'] = $candidate['author'];
            $topic['author_followers'] = $candidate['author_followers'];
            if ($loyalty !== null) {
                $topic['loyalty'] = $loyalty;
            }
            $topics[] = $topic;
            if (count($topics) === 6) {
                break;
            }
        }

        Cache::put($cacheKey, $topics, now()->addMinutes($topics === [] ? 3 : 4));

        return $topics;
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

        $cacheKey = 'x-niche-topics:v4:'.$account->id.':'.md5(mb_strtolower($query));
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
            if ($key === '' || isset($seen[$key]) || ! $this->isEnglishPhrase($text) || ! $this->textHasNicheWords($text, $niche)) {
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

        return implode(' ', $words).' -is:retweet';
    }

    private function textHasNicheWords(string $text, string $niche): bool
    {
        $words = $this->nicheWords($niche);
        if ($words === []) {
            return false;
        }

        $haystack = mb_strtolower($text);
        foreach ($words as $word) {
            if (! str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Words from the description and the audience. A timeline post has to share one.
     *
     * @return list<string>
     */
    private function purposeWords(string $purpose): array
    {
        return array_slice($this->distinctiveWords($purpose), 0, 12);
    }

    /**
     * @param  list<string>  $worlds
     */
    private function loyaltyIn(string $text, array $worlds): ?string
    {
        $haystack = mb_strtolower($text);
        $found = [];
        foreach ($worlds as $word) {
            if (str_contains($haystack, $word)) {
                $found[] = $word;
            }
        }
        if ($found === []) {
            return null;
        }

        usort($found, fn (string $a, string $b) => mb_strpos($haystack, $a) <=> mb_strpos($haystack, $b));

        return implode(' ', array_map(
            fn (string $word) => mb_convert_case($word, MB_CASE_TITLE, 'UTF-8'),
            array_slice($found, 0, 2),
        ));
    }

    public function isOutsideTheAccount(string $text, string $purpose): bool
    {
        return $this->mentionsBlockedNews($text) && ! $this->mentionsBlockedNews($purpose);
    }

    private function mentionsBlockedNews(string $text): bool
    {
        $haystack = mb_strtolower($text);
        foreach (['war', 'wars', 'trump', 'iran', 'israel', 'gaza', 'putin', 'ukraine', 'nato', 'biden', 'hamas', 'missile', 'missiles', 'airstrike', 'invasion', 'politician', 'election', 'bombing', 'bombs'] as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function distinctiveWords(string $text): array
    {
        $phrase = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text));
        $words = preg_split('/\s+/', trim($phrase)) ?: [];
        $stop = [
            'that', 'this', 'with', 'from', 'your', 'have', 'been', 'were', 'will', 'they', 'them',
            'what', 'when', 'where', 'which', 'while', 'after', 'before', 'their', 'there', 'about',
            'would', 'could', 'should', 'other', 'first', 'next', 'last', 'time', 'said', 'says',
            'really', 'think', 'know', 'people', 'because', 'thing', 'things', 'something', 'someone',
            'everyone', 'nothing', 'always', 'never', 'today', 'tonight', 'again', 'still', 'more',
            'some', 'into', 'over', 'than', 'then', 'only', 'even', 'also', 'back', 'down', 'like',
            'just', 'want', 'need', 'make', 'made', 'take', 'took', 'come', 'came', 'look', 'looks',
            'feel', 'feels', 'going', 'good', 'bad', 'best', 'much', 'many', 'very', 'well', 'right',
            'lost', 'loss', 'scored', 'score', 'game', 'goal', 'goals', 'match', 'team', 'side',
            'fans', 'club', 'manager', 'coach', 'season', 'league', 'points', 'night', 'morning',
            'week', 'year', 'years', 'post', 'posts', 'news', 'live', 'home', 'here', 'dont', 'does',
            'getting', 'being', 'been', 'just', 'who', 'him', 'his', 'her', 'she', 'the', 'and',
            'account', 'content', 'followers', 'follower', 'promote', 'business', 'customers',
            'customer', 'audience', 'brand', 'growth', 'social', 'media', 'online', 'shop', 'sells',
            'sell', 'also', 'handmade', 'people', 'young',
        ];
        $out = [];
        foreach ($words as $word) {
            if (! is_string($word) || mb_strlen($word) < 4 || in_array($word, $stop, true)) {
                continue;
            }
            $out[$word] = true;
        }

        return array_keys($out);
    }

    private function textTouchesNiche(string $text, string $niche): bool
    {
        $words = array_values(array_filter(
            $this->nicheWords($niche),
            fn (string $word) => ! in_array($word, ['man', 'news', 'sports', 'today', 'live', 'official', 'updates', 'media', 'world', 'people', 'account', 'home'], true)
        ));
        if ($words === []) {
            return $this->textHasNicheWords($text, $niche);
        }

        $haystack = mb_strtolower($text);
        foreach ($words as $word) {
            if (str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
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
