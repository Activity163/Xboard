<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class MailLinkService
{
    /**
     * 处理Token登录
     * 
     * @param string $token 登录令牌
     * @return int|null 用户ID或null
     */
    public function handleTokenLogin(string $token): ?int
    {
        $key = CacheKey::get('TEMP_TOKEN', $token);
        $userId = Cache::get($key);

        if (!$userId) {
            return null;
        }

        $user = User::find($userId);

        if (!$user || $user->banned) {
            return null;
        }

        Cache::forget($key);

        return $userId;
    }
}
