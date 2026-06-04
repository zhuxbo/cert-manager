<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\PluginServiceProvider;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\AuthServiceProvider;
use Illuminate\Auth\Passwords\PasswordResetServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Cookie\CookieServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Providers\ConsoleSupportServiceProvider;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Notifications\NotificationServiceProvider;
use Illuminate\Pipeline\PipelineServiceProvider;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Redis\RedisServiceProvider;
use Illuminate\Session\SessionServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Tymon\JWTAuth\Providers\LaravelServiceProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'SSL'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'Asia/Shanghai'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => 'zh_CN',

    'fallback_locale' => 'en',

    'faker_locale' => 'zh_CN',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    'providers' => [
        // Laravel Framework Service Providers...
        AuthServiceProvider::class,
        BusServiceProvider::class,
        CacheServiceProvider::class,
        CookieServiceProvider::class,
        ConsoleSupportServiceProvider::class,
        DatabaseServiceProvider::class,
        EncryptionServiceProvider::class,
        FilesystemServiceProvider::class,
        FoundationServiceProvider::class,
        HashServiceProvider::class,
        MailServiceProvider::class,
        NotificationServiceProvider::class,
        PipelineServiceProvider::class,
        QueueServiceProvider::class,
        RedisServiceProvider::class,
        PasswordResetServiceProvider::class,
        SessionServiceProvider::class,
        ValidationServiceProvider::class,
        TranslationServiceProvider::class,
        ViewServiceProvider::class,

        // Application Service Providers...
        AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
        EventServiceProvider::class,
        PluginServiceProvider::class,
        RouteServiceProvider::class,
        LaravelServiceProvider::class,
    ],

    'aliases' => [
        // 保留常用的门面
        'Auth' => Auth::class,
        'Cache' => Cache::class,
        'Config' => Config::class,
        'DB' => DB::class,
        'Eloquent' => Model::class,
        'Event' => Event::class,
        'File' => File::class,
        'Gate' => Gate::class,
        'Hash' => Hash::class,
        'Http' => Http::class,
        'Log' => Log::class,
        'Queue' => Queue::class,
        'Redis' => Redis::class,
        'Request' => Request::class,
        'Response' => Response::class,
        'Route' => Route::class,
        'Storage' => Storage::class,
        'URL' => URL::class,
        'Validator' => Validator::class,
    ],

    'snowflake' => [
        'machine_id' => env('MACHINE_ID', 0),
    ],
];
