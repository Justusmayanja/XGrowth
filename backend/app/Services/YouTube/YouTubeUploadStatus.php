<?php

namespace App\Services\YouTube;

use App\Models\ConnectedAccount;
use App\Models\User;

class YouTubeUploadStatus
{
    public function __construct(
        private readonly YouTubeUploadConsentService $consent,
        private readonly YouTubeAllowanceService $allowance,
        private readonly YouTubeInsertBudget $budget,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $account = ConnectedAccount::query()
            ->where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->first();
        $connected = (bool) $account?->is_active;
        $granted = $this->consent->granted($account);
        $auditApproved = (bool) config('services.youtube.upload_audit_approved');
        $allowance = $this->allowance->status($user);

        return [
            'audit_approved' => $auditApproved,
            'scope' => [
                'connected' => $connected,
                'upload_granted' => $granted,
                'needs_upload_consent' => $connected && ! $granted,
            ],
            'allowance' => [
                'limit' => $allowance['limit'],
                'used' => $allowance['used'],
                'remaining' => $allowance['remaining'],
                'period_end' => $allowance['plan_found'] ? $allowance['period_end'] : null,
                'plan_found' => $allowance['plan_found'],
            ],
            'daily_budget' => [
                'limit' => $this->budget->limit(),
                'used' => $this->budget->used(),
                'remaining' => $this->budget->remaining(),
                'resets_on' => $this->budget->resetsOn(),
            ],
            'privacy_policy' => $auditApproved
                ? 'The upload audit is approved. A send can ask for the visibility you choose.'
                : 'The upload audit is not approved. A send is private, or the file stays on this server. Public and unlisted are not requested.',
        ];
    }
}
