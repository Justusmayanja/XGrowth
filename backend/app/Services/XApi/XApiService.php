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

    /**
     * @return array{followers: int, impressions: int, engagement_rate: float, tweets: list<array<string, mixed>>}
     */
    public function accountActivity(ConnectedAccount $account): array
    {
        return Cache::remember('x-activity:'.$account->id, now()->addMinutes(10), function () use ($account) {
            $token = $this->accessToken($account);
            $me = Http::withToken($token)->get('https://api.twitter.com/2/users/me', [
                'user.fields' => 'public_metrics',
            ]);

            if ($me->failed()) {
                throw new RuntimeException($this->apiError($me));
            }

            $user = $me->json('data') ?? [];
            $tweets = Http::withToken($token)->get('https://api.twitter.com/2/users/'.($user['id'] ?? 'me').'/tweets', [
                'max_results' => 100,
                'exclude' => 'retweets',
                'tweet.fields' => 'public_metrics,created_at',
            ]);

            if ($tweets->failed()) {
                throw new RuntimeException($this->apiError($tweets));
            }

            $rows = $tweets->json('data') ?? [];
            $normalized = [];
            $impressions = 0;
            $engagements = 0;

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $metrics = $row['public_metrics'] ?? [];
                $impressionCount = (int) ($metrics['impression_count'] ?? 0);
                $likes = (int) ($metrics['like_count'] ?? 0);
                $reposts = (int) ($metrics['retweet_count'] ?? 0);
                $replies = (int) ($metrics['reply_count'] ?? 0);
                $impressions += $impressionCount;
                $engagements += $likes + $reposts + $replies;
                $normalized[] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'content' => (string) ($row['text'] ?? ''),
                    'impressions' => $impressionCount,
                    'likes' => $likes,
                    'reposts' => $reposts,
                    'replies' => $replies,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }

            return [
                'followers' => (int) ($user['public_metrics']['followers_count'] ?? 0),
                'impressions' => $impressions,
                'engagement_rate' => $impressions > 0 ? round(($engagements / $impressions) * 100, 1) : 0,
                'tweets' => $normalized,
            ];
        });
    }

    /**
     * Mentions, direct replies, and niche posts from X.
     *
     * @return array{posts: list<array<string, mixed>>, errors: list<string>}
     */
    public function replyFeed(ConnectedAccount $account, string $niche): array
    {
        $ownId = (string) ($account->platform_account_id ?? '');
        $posts = [];
        $errors = [];

        foreach ([
            fn () => $this->mentions($account),
            fn () => $this->repliesToAccount($account),
            fn () => $this->searchNiche($account, $niche),
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

        usort($unique, fn (array $a, array $b) => ($a['minutes_ago'] ?? 0) <=> ($b['minutes_ago'] ?? 0));

        return [
            'posts' => array_slice($unique, 0, 20),
            'errors' => $errors,
        ];
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
                'minutes_ago' => max(0, (int) $created->diffInMinutes(now())),
                'opportunity_score' => (int) min(100, round(20 + ($replies * 2) + ($likes / 20) + ($reposts / 10))),
                'kind' => $kind,
            ];
        }

        return $posts;
    }

    private function nicheQuery(string $niche): ?string
    {
        $phrase = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $niche) ?? '') ?? '');
        if ($phrase === '' || in_array(strtolower($phrase), ['your niche', 'general', 'niche'], true)) {
            return null;
        }

        $words = array_values(array_unique(array_filter(
            preg_split('/\s+/', mb_strtolower($phrase)) ?: [],
            fn ($word) => is_string($word)
                && mb_strlen($word) >= 3
                && ! in_array($word, ['the', 'and', 'for', 'with', 'your', 'from', 'that', 'this', 'you', 'are'], true)
        )));
        $words = array_slice($words, 0, 6);
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
