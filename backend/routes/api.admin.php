<?php

use App\Http\Controllers\Admin\AcmeController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\AutoDeployReportController;
use App\Http\Controllers\Admin\CallbackController;
use App\Http\Controllers\Admin\CertController;
use App\Http\Controllers\Admin\ChainController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DatabaseBackupController;
use App\Http\Controllers\Admin\DatabaseRestoreStatusController;
use App\Http\Controllers\Admin\DelegationController;
use App\Http\Controllers\Admin\DeployTokenController;
use App\Http\Controllers\Admin\EnterpriseLookupController;
use App\Http\Controllers\Admin\FundController;
use App\Http\Controllers\Admin\LogsController;
use App\Http\Controllers\Admin\MetricsController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\PluginController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductPriceController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SettingGroupController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UpgradeController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserLevelController;
use App\Http\Controllers\Admin\ZipcodeLookupController;
use App\Http\Middleware\AdminAuthenticate;
use App\Utils\RouteHelper;
use Illuminate\Support\Facades\Route;

// 无需认证
Route::prefix('admin')->group(function () {
    Route::middleware('login.limiter:admin')->post('login', [AuthController::class, 'login']);
});

// 刷新Token
Route::prefix('admin')->middleware('api.admin.refresh')->group(function () {
    Route::post('refresh-token', [AuthController::class, 'refreshToken']);
});

// 数据库备份的下载端点 — 由一次性 token 鉴权，不走 admin 中间件
// 目的：浏览器原生流式下载，避免前端把整文件读进 blob
Route::prefix('admin/database')->group(function () {
    Route::get('backups/download', [DatabaseBackupController::class, 'download']);
    Route::get('jobs/{token}', DatabaseRestoreStatusController::class)
        ->middleware('throttle:database-job-status')
        ->where('token', '[A-Za-z0-9_-]{32}');
});

// 需要认证的路由
Route::prefix('admin')->middleware('api.admin')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::patch('update-profile', [AuthController::class, 'updateProfile']);
    Route::patch('update-password', [AuthController::class, 'updatePassword']);
    Route::delete('logout', [AuthController::class, 'logout']);

    // 管理端首页统计数据路由
    Route::prefix('dashboard')->group(function () {
        Route::get('overview', [DashboardController::class, 'overview']);
        Route::get('system-overview', [DashboardController::class, 'systemOverview']);
        Route::get('realtime', [DashboardController::class, 'realtime']);
        Route::get('trends', [DashboardController::class, 'trends']);
        Route::get('top-products', [DashboardController::class, 'topProducts']);
        Route::get('brand-stats', [DashboardController::class, 'brandStats']);
        Route::get('user-level-distribution', [DashboardController::class, 'userLevelDistribution']);
        Route::get('finance-overview', [DashboardController::class, 'financeOverview']);
        Route::post('clear-cache', [DashboardController::class, 'clearCache']);
    });

    // 系统运维指标：admin 后台"系统状态"页面消费，只返回数据，不参与 503 判定
    Route::get('metrics', [MetricsController::class, 'index'])->name('admin.metrics');

    // 使用工具类注册标准资源路由
    RouteHelper::registerResourceRoutes('admin', AdminController::class);
    RouteHelper::registerResourceRoutes('user', UserController::class);
    Route::prefix('user')->group(function () {
        Route::post('direct-login', [UserController::class, 'directLogin']);
        Route::post('create-user', [UserController::class, 'createUser']);
    });
    RouteHelper::registerResourceRoutes('user-level', UserLevelController::class);
    Route::prefix('user-level')->group(function () {
        Route::get('batch-codes', [UserLevelController::class, 'batchShowInCodes']);
    });

    // 资源路由
    RouteHelper::registerResourceRoutes('chain', ChainController::class);
    RouteHelper::registerResourceRoutes('contact', ContactController::class);
    RouteHelper::registerResourceRoutes('organization', OrganizationController::class);
    RouteHelper::registerResourceRoutes('callback', CallbackController::class);
    RouteHelper::registerResourceRoutes('api-token', ApiTokenController::class);
    RouteHelper::registerResourceRoutes('deploy-token', DeployTokenController::class);
    RouteHelper::registerResourceRoutes('product', ProductController::class);
    Route::prefix('product')->group(function () {
        Route::post('batch-delegation', [ProductController::class, 'batchDelegation']);
        Route::post('import', [ProductController::class, 'import']);
        Route::get('cost/{id}', [ProductController::class, 'getCost'])->where('id', '[0-9]+');
        Route::patch('cost/{id}', [ProductController::class, 'updateCost'])->where('id', '[0-9]+');
        Route::get('source', [ProductController::class, 'getSourceList']);
        Route::post('export', [ProductController::class, 'export']);
    });
    RouteHelper::registerResourceRoutes('product-price', ProductPriceController::class);
    Route::prefix('product-price')->group(function () {
        Route::post('initialize', [ProductPriceController::class, 'initialization']);
        Route::get('prices', [ProductPriceController::class, 'get']);
        // set 是按 (product_id, level_code, period) 的子集 upsert，不删未提及行 → PATCH
        Route::patch('prices', [ProductPriceController::class, 'set']);
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
        Route::post('transfer', [OrderController::class, 'transfer']);
        Route::post('input', [OrderController::class, 'input']);
        Route::post('pay/{id}', [OrderController::class, 'pay'])->where('id', '[0-9]+');
        Route::post('commit/{id}', [OrderController::class, 'commit'])->where('id', '[0-9]+');
        Route::post('revalidate/{id}', [OrderController::class, 'revalidate'])->where('id', '[0-9]+');
        Route::post('update-dcv/{id}', [OrderController::class, 'updateDCV'])->where('id', '[0-9]+');
        Route::post('sync/{id}', [OrderController::class, 'sync'])->where('id', '[0-9]+');
        Route::post('commit-cancel/{id}', [OrderController::class, 'commitCancel'])->where('id', '[0-9]+');
        Route::post('revoke-cancel/{id}', [OrderController::class, 'revokeCancel'])->where('id', '[0-9]+');
        Route::post('mark-renewed/{id}', [OrderController::class, 'markRenewed'])->where('id', '[0-9]+');
        Route::post('remark/{id}', [OrderController::class, 'remark'])->where('id', '[0-9]+');
        Route::get('download', [OrderController::class, 'download']);
        Route::get('download-validate-file/{id}', [OrderController::class, 'downloadValidateFile'])->where('id', '[0-9]+');
        Route::post('send-active/{id}', [OrderController::class, 'sendActive'])->where('id', '[0-9]+');
        Route::post('batch-pay', [OrderController::class, 'batchPay']);
        Route::post('batch-commit', [OrderController::class, 'batchCommit']);
        Route::post('batch-revalidate', [OrderController::class, 'batchRevalidate']);
        Route::post('batch-sync', [OrderController::class, 'batchSync']);
        Route::post('batch-commit-cancel', [OrderController::class, 'batchCommitCancel']);
        Route::post('batch-revoke-cancel', [OrderController::class, 'batchRevokeCancel']);
        Route::patch('auto-settings/{id}', [OrderController::class, 'updateAutoSettings'])->where('id', '[0-9]+');
        Route::patch('amount/{id}', [OrderController::class, 'updateAmount'])->where('id', '[0-9]+');
        Route::patch('applicant/{id}', [OrderController::class, 'updateApplicant'])->where('id', '[0-9]+');
        Route::get('deploy-commands', [OrderController::class, 'deployCommands']);
        Route::post('upload-document/{id}', [OrderController::class, 'uploadDocument'])->where('id', '[0-9]+');
        // document-preview 改走短时签名 URL（signed）而非 JWT：access_token 不进 URL，仅验证签名
        // withoutMiddleware 移除 api.admin 组中间件（依赖 ApiMiddleware 中 api.admin 组定义 = AdminAuthenticate）
        Route::get('document-preview/{id}', [OrderController::class, 'previewDocument'])
            ->where('id', '[0-9]+')
            ->withoutMiddleware([AdminAuthenticate::class])
            ->middleware('signed')
            ->name('admin.order.document-preview');
        // 取预览/下载短时签名 URL（走 JWT header 鉴权，admin 全局可为任意 docId 生成）
        Route::get('document-preview-url/{id}', [OrderController::class, 'previewDocumentUrl'])->where('id', '[0-9]+');
        Route::get('documents/{id}', [OrderController::class, 'getDocuments'])->where('id', '[0-9]+');
        Route::patch('document/{id}', [OrderController::class, 'updateDocument'])->where('id', '[0-9]+');
        Route::delete('document/{id}', [OrderController::class, 'deleteDocument'])->where('id', '[0-9]+');
        Route::post('submit-documents/{id}', [OrderController::class, 'submitDocuments'])->where('id', '[0-9]+');
    });

    // 证书路由
    Route::prefix('cert')->group(function () {
        Route::get('/', [CertController::class, 'index']);
        Route::get('{id}', [CertController::class, 'show'])->where('id', '[0-9]+');
        Route::get('batch', [CertController::class, 'batchShow']);
    });

    // 任务管理
    Route::prefix('task')->group(function () {
        Route::get('/', [TaskController::class, 'index']);
        Route::get('{id}', [TaskController::class, 'show'])->where('id', '[0-9]+');
        Route::delete('{id}', [TaskController::class, 'destroy'])->where('id', '[0-9]+');
        Route::delete('batch', [TaskController::class, 'batchDestroy']);
        Route::post('batch-start', [TaskController::class, 'batchStart']);
        Route::post('batch-stop', [TaskController::class, 'batchStop']);
        Route::post('batch-execute', [TaskController::class, 'batchExecute']);
    });

    // 财务管理路由
    RouteHelper::registerResourceRoutes('fund', FundController::class);
    Route::prefix('fund')->group(function () {
        Route::post('reverse/{id}', [FundController::class, 'reverse'])->where('id', '[0-9]+');
        Route::post('refunds/{id}', [FundController::class, 'refunds'])->where('id', '[0-9]+');
        Route::post('check/{id}', [FundController::class, 'check'])->where('id', '[0-9]+');
    });
    Route::get('transaction', [TransactionController::class, 'index']);

    // 设置组和设置项路由
    RouteHelper::registerResourceRoutes('setting-group', SettingGroupController::class);
    RouteHelper::registerResourceRoutes('setting', SettingController::class);
    RouteHelper::registerResourceRoutes('notification-template', NotificationTemplateController::class);
    Route::post('notification-template/reset', [NotificationTemplateController::class, 'reset']);
    Route::prefix('setting')->group(function () {
        Route::post('site-image/{kind}', [SettingController::class, 'uploadSiteImage'])
            ->where('kind', 'favicon|logo|logo-expanded|qrcode|login-image');
        Route::delete('site-image/{kind}', [SettingController::class, 'deleteSiteImage'])
            ->where('kind', 'favicon|logo|logo-expanded|qrcode|login-image');
        Route::get('group/{groupId}', [SettingController::class, 'getByGroup'])
            ->where('groupId', '[0-9]+');
        Route::patch('batch-update', [SettingController::class, 'batchUpdate']);
        Route::post('clear-cache', [SettingController::class, 'clearCache']);
        Route::post('clear-all-cache', [SettingController::class, 'clearAllCache']);
        // 清除支付配置缓存并删除已落盘的支付证书（仅 admin 可调）
        Route::post('clear-pay-cache', [SettingController::class, 'clearPayCache']);
    });

    // 日志路由
    Route::prefix('logs')->group(function () {
        Route::get('{type}/{id}', [LogsController::class, 'get'])
            ->where('type', 'admin|user|api|callback|ca|error')
            ->where('id', '[0-9]+');
        Route::get('admin', [LogsController::class, 'admin']);
        Route::get('user', [LogsController::class, 'user']);
        Route::get('api', [LogsController::class, 'api']);
        Route::get('callback', [LogsController::class, 'callback']);
        Route::get('ca', [LogsController::class, 'ca']);
        Route::get('error', [LogsController::class, 'errors']);
    });

    // ACME 路由
    Route::prefix('acme')->group(function () {
        Route::get('/', [AcmeController::class, 'index']);
        Route::get('{id}', [AcmeController::class, 'show'])->where('id', '[0-9]+');
        Route::get('batch', [AcmeController::class, 'batchShow']);
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

    Route::get('auto-deploy-report', [AutoDeployReportController::class, 'index']);

    // 通知管理
    Route::prefix('notification')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('{id}', [NotificationController::class, 'show'])->where('id', '[0-9]+');
        Route::post('test-send', [NotificationController::class, 'sendTest']);
        Route::post('resend/{id}', [NotificationController::class, 'resend'])->where('id', '[0-9]+');
    });

    // 插件管理
    Route::prefix('plugin')->group(function () {
        Route::get('installed', [PluginController::class, 'installed']);
        Route::get('check-updates', [PluginController::class, 'checkUpdates']);
        Route::get('operations', [PluginController::class, 'operations']);
        Route::get('operations/{uuid}', [PluginController::class, 'operation']);
        Route::post('operations/{uuid}/fail-stale', [PluginController::class, 'failStaleOperation']);
        Route::post('operations/{uuid}/retry', [PluginController::class, 'retryOperation']);
        Route::post('operations/{uuid}/cancel', [PluginController::class, 'cancelFailedUpdateOperation']);
        Route::post('operations/{uuid}/uninstall', [PluginController::class, 'uninstallFailedOperation']);
        Route::post('install', [PluginController::class, 'install']);
        Route::post('update', [PluginController::class, 'update']);
        Route::post('uninstall', [PluginController::class, 'uninstall']);
    });

    // 数据库备份管理
    Route::prefix('database')->group(function () {
        Route::get('backups', [DatabaseBackupController::class, 'index']);
        Route::post('backups', [DatabaseBackupController::class, 'store']);
        Route::delete('backups/{backupId}', [DatabaseBackupController::class, 'destroy'])
            ->where('backupId', '[a-z_]+_[0-9]{8}_[0-9]{6}');
        Route::get('backups/{backupId}/restore-preflight', [DatabaseBackupController::class, 'restorePreflight'])
            ->where('backupId', '[a-z_]+_[0-9]{8}_[0-9]{6}');
        Route::post('backups/{backupId}/restore', [DatabaseBackupController::class, 'restore'])
            ->where('backupId', '[a-z_]+_[0-9]{8}_[0-9]{6}');
        Route::post('backups/{backupId}/download-token', [DatabaseBackupController::class, 'downloadToken'])
            ->where('backupId', '[a-z_]+_[0-9]{8}_[0-9]{6}');
    });

    // 系统升级管理
    Route::prefix('upgrade')->group(function () {
        Route::get('version', [UpgradeController::class, 'version']);
        Route::get('check', [UpgradeController::class, 'check']);
        Route::get('releases', [UpgradeController::class, 'releases']);
        Route::post('execute', [UpgradeController::class, 'execute']);
        Route::get('status', [UpgradeController::class, 'status']);
        Route::get('backups', [UpgradeController::class, 'backups']);
        Route::post('rollback', [UpgradeController::class, 'rollback']);
        Route::delete('backup', [UpgradeController::class, 'deleteBackup']);
        Route::post('channel', [UpgradeController::class, 'setChannel']);
        Route::post('freeze', [UpgradeController::class, 'freeze']);
        Route::post('unfreeze', [UpgradeController::class, 'unfreeze']);
        Route::post('smoke', [UpgradeController::class, 'smoke']);
        Route::get('binary-health', [UpgradeController::class, 'binaryHealth']);
    });

    // 工商信息查询
    Route::get('enterprise-lookup/status', [EnterpriseLookupController::class, 'status']);
    Route::post('enterprise-lookup', [EnterpriseLookupController::class, 'lookup'])
        ->middleware('throttle:enterprise-lookup');

    // 邮编查询(本地数据)
    Route::post('zipcode-lookup', [ZipcodeLookupController::class, 'lookup'])
        ->middleware('throttle:zipcode-lookup');
});
