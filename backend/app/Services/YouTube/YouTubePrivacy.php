<?php

namespace App\Services\YouTube;

/**
 * Decides the visibility an insert may request.
 * While the upload audit is off, public and unlisted are never requested.
 */
class YouTubePrivacy
{
    public const PUBLIC = 'public';

    public const UNLISTED = 'unlisted';

    public const PRIVATE = 'private';

    /**
     * @return array{action: string, privacy: string|null, note: string}
     */
    public function decide(bool $auditApproved, ?string $desired): array
    {
        $desired = $this->known($desired) ? $desired : self::PRIVATE;

        if (! $auditApproved && $desired !== self::PRIVATE) {
            return [
                'action' => 'hold',
                'privacy' => null,
                'note' => 'This send asked for '.$desired.' visibility. The upload audit is not approved, so the file stays on this server. Public and unlisted are not requested.',
            ];
        }

        if (! $auditApproved) {
            return [
                'action' => 'insert',
                'privacy' => self::PRIVATE,
                'note' => 'The upload audit is not approved, so this insert is private. Public and unlisted are not requested.',
            ];
        }

        return [
            'action' => 'insert',
            'privacy' => $desired,
            'note' => 'The upload audit is approved. YouTube is asked for '.$desired.' visibility.',
        ];
    }

    public function acceptable(bool $auditApproved, ?string $applied): bool
    {
        if (! $this->known($applied)) {
            return false;
        }

        return $auditApproved || $applied === self::PRIVATE;
    }

    private function known(?string $privacy): bool
    {
        return in_array($privacy, [self::PUBLIC, self::UNLISTED, self::PRIVATE], true);
    }
}
