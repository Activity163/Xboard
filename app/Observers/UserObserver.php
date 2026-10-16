<?php

namespace App\Observers;

use App\Models\User;
use App\Services\TrafficResetService;

class UserObserver
{
  public bool $afterCommit = true;

  public function __construct(
    private readonly TrafficResetService $trafficResetService
  ) {
  }

  public function updated(User $user): void
  {
    // With $afterCommit = true, isDirty() is always false after commit.
    // Use wasChanged() to detect what was actually modified.
    if ($user->wasChanged(['plan_id', 'expired_at'])) {
      $this->recalculateNextResetAt($user);
    }
  }

  public function created(User $user): void
  {
    $this->recalculateNextResetAt($user);
  }

  /**
   * 根据当前用户状态重新计算 next_reset_at
   */
  private function recalculateNextResetAt(User $user): void
  {
    $user->refresh();
    User::withoutEvents(function () use ($user) {
      $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
      $user->next_reset_at = $nextResetTime?->timestamp;
      $user->save();
    });
  }
}
