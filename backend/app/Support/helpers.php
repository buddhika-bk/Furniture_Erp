<?php
if (!function_exists('auth_user')) {
    function auth_user(): ?\App\Models\User { return request()?->attributes->get('erp_user'); }
}
if (!function_exists('auth_user_id')) {
    function auth_user_id(): ?int { return auth_user()?->id; }
}
