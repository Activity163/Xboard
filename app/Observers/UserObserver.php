<?php

namespace App\Observers;

use App\Models\Plan;
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
    // ⚠️ applyPlan() 内部会再 save 一次并重置 changes，所以这里必须先取快照。
    $planChanged = $user->wasChanged('plan_id');
    $expiredChanged = $user->wasChanged('expired_at');

    if ($planChanged && $user->plan_id) {
      $this->applyPlan($user);
    }

    if ($planChanged || $expiredChanged) {
      $this->recalculateNextResetAt($user);
    }
  }

  public function created(User $user): void
  {
    if ($user->plan_id) {
      $this->applyPlan($user);
    }
    $this->recalculateNextResetAt($user);
  }

  /**
   * 把订阅（v2_plan）上的默认值落实到用户字段上。
   *
   * 精简版删掉了下单流程（上游 OrderService 原本负责这件事），
   * 所以后台直接给用户选套餐时必须在这里补上，
   * 否则 plan_id 只是个悬空数字，group_id 依旧是 NULL、连不上任何节点。
   */
  private function applyPlan(User $user): void
  {
    $plan = Plan::find($user->plan_id);
    if (!$plan) {
      return;
    }

    User::withoutEvents(function () use ($user, $plan) {
      if ($plan->group_id) {
        $user->group_id = $plan->group_id;
      }
      if ($plan->transfer_enable !== null) {
        $user->transfer_enable = $plan->transfer_enable;
      }
      if ($plan->speed_limit !== null) {
        $user->speed_limit = $plan->speed_limit;
      }
      if ($plan->device_limit !== null) {
        $user->device_limit = $plan->device_limit;
      }
      // 订阅本身不设到期时间（配合 reset_traffic_method=2 不重置），
      // 分配套餐即永久有效。NULL 才是"永久"，0 会被判定为已过期。
      $user->expired_at = null;
      $user->save();
    });
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
