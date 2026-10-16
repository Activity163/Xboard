<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;

class CommController extends Controller
{
    public function config()
    {
        $data = [
            'currency' => admin_setting('currency', 'CNY'),
            'currency_symbol' => admin_setting('currency_symbol', '¥'),
        ];
        return $this->success($data);
    }
}
