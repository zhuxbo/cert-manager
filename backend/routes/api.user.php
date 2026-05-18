<?php

use App\Http\Controllers\User\AcmeController;
use App\Http\Controllers\User\AuthController;
use App\Http\Controllers\User\ContactController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\DelegationController;
use App\Http\Controllers\User\EnterpriseLookupController;
use App\Http\Controllers\User\FundController;
use App\Http\Controllers\User\OrderController;
use App\Http\Controllers\User\OrganizationController;
use App\Http\Controllers\User\ProductController;
use App\Http\Controllers\User\SettingController;
use App\Http\Controllers\User\TopUpController;
use App\Http\Controllers\User\TransactionController;
use App\Http\Controllers\User\VerifyCodeController;
use App\Http\Controllers\User\ZipcodeLookupController;
use App\Utils\RouteHelper;
use Illuminate\Support\Facades\Route;

// 无需认证
Route::middleware('login.limiter:user')->post('login', [AuthController::class, 'login']);
Route::post('register', [AuthController::class, 'register']);
Route::post('reset-password', [AuthController::class, 'resetPassword']);

// 发送邮箱验证码 无需认证
Route::post('send-email-code', [VerifyCodeController::class, 'sendEmail']);

// 刷新Token
Route::middleware('api.user.refresh')->group(function () {
    Route::post('refresh-token', [AuthController::class, 'refreshToken']);
});

// 产品路由
Route::prefix('product')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('{id}', [ProductController::class, 'show'])->where('id', '[0-9]+');
});

// 需要认证的用户接口
Route::middleware('api.user')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::patch('update-username', [AuthController::class, 'updateUsername']);
    Route::patch('update-password', [AuthController::class, 'updatePassword']);
    Route::patch('bind-email', [AuthController::class, 'bindEmail']);
    Route::patch('bind-mobile', [AuthController::class, 'bindMobile']);
    Route::delete('logout', [AuthController::class, 'logout']);
    // 发送短信验证码 需要认证
    Route::post('send-sms-code', [VerifyCodeController::class, 'sendSms']);

    // 首页统计数据路由
    Route::prefix('dashboard')->group(function () {
        Route::get('overview', [DashboardController::class, 'overview']);
        Route::get('assets', [DashboardController::class, 'assets']);
        Route::get('orders', [DashboardController::class, 'orders']);
        Route::get('trend', [DashboardController::class, 'trend']);
        Route::get('monthly-comparison', [DashboardController::class, 'monthlyComparison']);
    });

    // 产品路由
    Route::prefix('product')->group(function () {
        Route::post('export', [ProductController::class, 'export']);
    });

    // 订单路由
    Route::prefix('order')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('{id}', [OrderController::class, 'show'])->where('id', '[0-9]+');
        Route::get('batch', [OrderController::class, 'batchShow']);
        Route::post('new', [OrderController::class, 'new']);
        Route::post('batch-new', [OrderController::class, 'batchNew']);
        Route::post('renew', [OrderController::class, 'renew']);
        Route::post('reissue', [OrderController::class, 'reissue']);
        Route::post('pay/{id}', [OrderController::class, 'pay'])->where('id', '[0-9]+');
        Route::post('commit/{id}', [OrderController::class, 'commit'])->where('id', '[0-9]+');
        Route::post('revalidate/{id}', [OrderController::class, 'revalidate'])->where('id', '[0-9]+');
        Route::post('update-dcv/{id}', [OrderController::class, 'updateDCV'])->where('id', '[0-9]+');
        Route::post('sync/{id}', [OrderController::class, 'sync'])->where('id', '[0-9]+');
        Route::post('commit-cancel/{id}', [OrderController::class, 'commitCancel'])->where('id', '[0-9]+');
        Route::post('revoke-cancel/{id}', [OrderController::class, 'revokeCancel'])->where('id', '[0-9]+');
        Route::post('remark/{id}', [OrderController::class, 'remark'])->where('id', '[0-9]+');
        Route::get('download', [OrderController::class, 'download']);
        Route::get('download-validate-file/{id}', [OrderController::class, 'downloadValidateFile'])->where('id', '[0-9]+');
        Route::get('send-active/{id}', [OrderController::class, 'sendActive'])->where('id', '[0-9]+');
        Route::post('batch-pay', [OrderController::class, 'batchPay']);
        Route::post('batch-commit', [OrderController::class, 'batchCommit']);
        Route::post('batch-revalidate', [OrderController::class, 'batchRevalidate']);
        Route::post('batch-sync', [OrderController::class, 'batchSync']);
        Route::post('batch-commit-cancel', [OrderController::class, 'batchCommitCancel']);
        Route::post('batch-revoke-cancel', [OrderController::class, 'batchRevokeCancel']);
        Route::patch('auto-settings/{id}', [OrderController::class, 'updateAutoSettings'])->where('id', '[0-9]+');
        Route::get('{id}/certs', [OrderController::class, 'certs'])->where('id', '[0-9]+');
        Route::get('deploy-commands', [OrderController::class, 'deployCommands']);
        Route::post('upload-document/{id}', [OrderController::class, 'uploadDocument'])->where('id', '[0-9]+');
        Route::get('document-preview/{id}', [OrderController::class, 'previewDocument'])->where('id', '[0-9]+');
        Route::get('documents/{id}', [OrderController::class, 'getDocuments'])->where('id', '[0-9]+');
        Route::patch('document/{id}', [OrderController::class, 'updateDocument'])->where('id', '[0-9]+');
        Route::delete('document/{id}', [OrderController::class, 'deleteDocument'])->where('id', '[0-9]+');
        Route::post('submit-documents/{id}', [OrderController::class, 'submitDocuments'])->where('id', '[0-9]+');
    });

    // 联系人路由
    RouteHelper::registerResourceRoutes('contact', ContactController::class);

    // 资金管理路由
    Route::prefix('fund')->group(function () {
        Route::get('/', [FundController::class, 'index']);
        Route::post('check/{id}', [FundController::class, 'check'])->where('id', '[0-9]+');
    });

    // 组织路由
    RouteHelper::registerResourceRoutes('organization', OrganizationController::class);

    // 交易记录路由
    Route::prefix('transaction')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
    });

    // 设置路由
    Route::prefix('setting')->group(function () {
        Route::get('api-token', [SettingController::class, 'getApiToken']);
        Route::put('api-token', [SettingController::class, 'updateApiToken']);
        Route::get('callback', [SettingController::class, 'getCallback']);
        Route::put('callback', [SettingController::class, 'updateCallback']);
        Route::get('notification-preferences', [SettingController::class, 'getNotificationPreferences']);
        Route::put('notification-preferences', [SettingController::class, 'updateNotificationPreferences']);
        Route::get('deploy-token', [SettingController::class, 'getDeployToken']);
        Route::put('deploy-token', [SettingController::class, 'updateDeployToken']);
        Route::delete('deploy-token', [SettingController::class, 'deleteDeployToken']);
        Route::get('auto-preferences', [SettingController::class, 'getAutoPreferences']);
        Route::put('auto-preferences', [SettingController::class, 'updateAutoPreferences']);
    });

    // 支付宝支付
    Route::prefix('top-up')->group(function () {
        Route::post('alipay', [TopUpController::class, 'alipay']);
        Route::post('wechat', [TopUpController::class, 'wechat']);
        Route::get('check/{id}', [TopUpController::class, 'check'])->where('id', '[0-9]+');
        Route::get('get-bank-account', [TopUpController::class, 'getBankAccount']);
        // 清除支付配置缓存
        Route::get('clear-pay-config', [TopUpController::class, 'clearConfigCache']);
    });

    // ACME 路由
    Route::prefix('acme')->group(function () {
        Route::get('/', [AcmeController::class, 'index']);
        Route::get('{id}', [AcmeController::class, 'show'])->where('id', '[0-9]+');
        Route::post('new', [AcmeController::class, 'new']);
        Route::post('pay/{id}', [AcmeController::class, 'pay'])->where('id', '[0-9]+');
        Route::post('commit/{id}', [AcmeController::class, 'commit'])->where('id', '[0-9]+');
        Route::post('sync/{id}', [AcmeController::class, 'sync'])->where('id', '[0-9]+');
        Route::post('commit-cancel/{id}', [AcmeController::class, 'commitCancel'])->where('id', '[0-9]+');
        Route::post('revoke-cancel/{id}', [AcmeController::class, 'revokeCancel'])->where('id', '[0-9]+');
        Route::post('remark/{id}', [AcmeController::class, 'remark'])->where('id', '[0-9]+');
        Route::post('batch-pay', [AcmeController::class, 'batchPay']);
        Route::post('batch-commit', [AcmeController::class, 'batchCommit']);
        Route::post('batch-sync', [AcmeController::class, 'batchSync']);
        Route::post('batch-commit-cancel', [AcmeController::class, 'batchCommitCancel']);
        Route::post('batch-revoke-cancel', [AcmeController::class, 'batchRevokeCancel']);
        Route::post('batch-copy-eab', [AcmeController::class, 'batchCopyEab']);
    });

    // CNAME 委托管理路由
    RouteHelper::registerResourceRoutes('delegation', DelegationController::class);
    Route::prefix('delegation')->group(function () {
        Route::post('check/{id}', [DelegationController::class, 'check'])->where('id', '[0-9]+');
        Route::post('batch-store', [DelegationController::class, 'batchStore']);
    });

    // 工商信息查询
    Route::get('enterprise-lookup/status', [EnterpriseLookupController::class, 'status']);
    Route::post('enterprise-lookup', [EnterpriseLookupController::class, 'lookup'])
        ->middleware('throttle:enterprise-lookup');

    // 邮编查询(本地数据)
    Route::post('zipcode-lookup', [ZipcodeLookupController::class, 'lookup'])
        ->middleware('throttle:zipcode-lookup');
});
