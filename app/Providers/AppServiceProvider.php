<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (CanResetPassword $user, string $token): string {
            $webAdminUrl = rtrim((string) config('services.web_admin_url'), '/');

            if (filter_var($webAdminUrl, FILTER_VALIDATE_URL) === false) {
                throw new LogicException('WEB_ADMIN_URL must be configured as an absolute URL.');
            }

            if (app()->environment('production') && parse_url($webAdminUrl, PHP_URL_SCHEME) !== 'https') {
                throw new LogicException('WEB_ADMIN_URL must use HTTPS in production.');
            }

            $query = http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);

            return $webAdminUrl.'/reset-password#'.$query;
        });
    }
}
