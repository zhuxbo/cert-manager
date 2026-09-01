<?php

declare(strict_types=1);

namespace App\Services\Logs;

final class CoreLogRetentionPolicy
{
    /** @var array<string, list<string>> */
    private const USER_AUDIT_ACTIONS = [
        'Auth' => ['login', 'register', 'resetPassword', 'updateUsername', 'updatePassword', 'bindEmail', 'bindMobile', 'logout'],
        'Order' => ['new', 'batchNew', 'renew', 'reissue', 'pay', 'batchPay', 'commit', 'batchCommit', 'commitCancel', 'batchCommitCancel', 'revokeCancel', 'batchRevokeCancel', 'updateDCV', 'uploadDocument', 'updateDocument', 'deleteDocument', 'submitDocuments', 'markRenewed', 'updateAutoSettings'],
        'Acme' => ['new', 'pay', 'batchPay', 'commit', 'batchCommit', 'commitCancel', 'batchCommitCancel', 'revokeCancel', 'batchRevokeCancel'],
        'Setting' => ['updateApiToken', 'updateCallback', 'updateDeployToken', 'deleteDeployToken', 'updateAutoPreferences'],
        'TopUp' => ['alipay', 'wechat'],
    ];

    private const USER_DIAGNOSTIC_ACTIONS = [
        'index', 'show', 'batchShow', 'me', 'refreshToken', 'revalidate', 'batchRevalidate',
        'sync', 'batchSync', 'get', 'list', 'products', 'notifications',
    ];

    private const ADMIN_DIAGNOSTIC_ACTIONS = [
        'revalidate', 'batchRevalidate', 'sync', 'batchSync', 'check', 'test', 'detect',
        'preview', 'refresh', 'health',
    ];

    private const ADMIN_AUDIT_ACTIONS = [
        'login', 'logout', 'updateProfile', 'updatePassword', 'clearCache', 'clearAllCache',
        'clearPayCache', 'directLogin', 'createUser', 'store', 'batchStore', 'update',
        'batchUpdate', 'destroy', 'batchDestroy', 'new', 'batchNew', 'renew', 'reissue',
        'transfer', 'input', 'pay', 'batchPay', 'commit', 'batchCommit', 'updateDCV',
        'commitCancel', 'batchCommitCancel', 'revokeCancel', 'batchRevokeCancel',
        'markRenewed', 'remark', 'sendActive', 'updateAutoSettings', 'updateAmount',
        'updateApplicant', 'uploadDocument', 'updateDocument', 'deleteDocument',
        'submitDocuments', 'batchStart', 'batchStop', 'batchExecute', 'reverse', 'refunds',
        'reset', 'uploadSiteImage', 'deleteSiteImage', 'set', 'initialization', 'import',
        'export', 'batchDelegation', 'batchCopyEab', 'sendTest', 'resend', 'install',
        'uninstall', 'failStaleOperation', 'retryOperation', 'cancelFailedUpdateOperation',
        'uninstallFailedOperation', 'restore', 'downloadToken', 'execute', 'rollback',
        'deleteBackup', 'setChannel', 'freeze', 'unfreeze', 'smoke', 'lookup',
    ];

    private const API_AUDIT_ACTIONS = [
        'new', 'renew', 'reissue', 'cancel', 'updateDCV', 'uploadDocument',
        'update', 'callback', 'toggleAutoReissue',
    ];

    private const API_DIAGNOSTIC_ACTIONS = [
        'getProducts', 'getOrders', 'get', 'getOrderIdByReferId', 'download', 'health',
        'revalidate', 'heartbeat', 'config', 'status',
    ];

    private const CA_AUDIT_ACTIONS = ['new', 'renew', 'reissue', 'cancel', 'update-dcv', 'upload-document'];

    private const CA_DIAGNOSTIC_ACTIONS = ['get', 'get-products', 'revalidate', 'sync', 'status'];

    private const ERROR_DIAGNOSTIC_ACTIONS = [
        'revalidate', 'batchRevalidate', 'sync', 'batchSync', 'health', 'check', 'get',
    ];

    private const CALLBACK_AUDIT_ACTIONS = ['alipayNotify', 'wechatNotify', 'index'];

    /**
     * @return array{audit: bool, classified: bool}
     */
    public function classify(
        string $channel,
        ?string $module,
        ?string $action,
        ?string $method,
        ?string $url,
        ?string $api,
        ?int $status,
    ): array {
        $action ??= $this->actionFromUrl($channel, $url);

        return match ($channel) {
            'user' => $this->classifyUser($module, $action, $method),
            'admin' => $this->classifyAdmin($action, $method),
            'api' => $this->classifyFromLists($action, self::API_AUDIT_ACTIONS, self::API_DIAGNOSTIC_ACTIONS),
            'callback' => $this->classifyCallback($action, $status),
            'ca' => $this->classifyFromLists($api, self::CA_AUDIT_ACTIONS, self::CA_DIAGNOSTIC_ACTIONS),
            'error' => $this->classifyError($action),
            default => ['audit' => true, 'classified' => false],
        };
    }

    /** @return array<string, list<string>> */
    public function userAuditActions(): array
    {
        return self::USER_AUDIT_ACTIONS;
    }

    /** @return list<string> */
    public function userDiagnosticActions(): array
    {
        return self::USER_DIAGNOSTIC_ACTIONS;
    }

    /** @return list<string> */
    public function adminDiagnosticActions(): array
    {
        return self::ADMIN_DIAGNOSTIC_ACTIONS;
    }

    /** @return list<string> */
    public function adminAuditActions(): array
    {
        return self::ADMIN_AUDIT_ACTIONS;
    }

    /** @return list<string> */
    public function apiAuditActions(): array
    {
        return self::API_AUDIT_ACTIONS;
    }

    /** @return list<string> */
    public function apiDiagnosticActions(): array
    {
        return self::API_DIAGNOSTIC_ACTIONS;
    }

    /** @return list<string> */
    public function caDiagnosticActions(): array
    {
        return self::CA_DIAGNOSTIC_ACTIONS;
    }

    /** @return list<string> */
    public function caAuditActions(): array
    {
        return self::CA_AUDIT_ACTIONS;
    }

    /** @return list<string> */
    public function errorDiagnosticActions(): array
    {
        return self::ERROR_DIAGNOSTIC_ACTIONS;
    }

    /** @return list<string> */
    public function callbackAuditActions(): array
    {
        return self::CALLBACK_AUDIT_ACTIONS;
    }

    /** @return array{audit: bool, classified: bool} */
    private function classifyUser(?string $module, ?string $action, ?string $method): array
    {
        if (in_array(strtoupper((string) $method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return ['audit' => false, 'classified' => true];
        }

        if ($module !== null && $action !== null && in_array($action, self::USER_AUDIT_ACTIONS[$module] ?? [], true)) {
            return ['audit' => true, 'classified' => true];
        }

        if ($action !== null && in_array($action, self::USER_DIAGNOSTIC_ACTIONS, true)) {
            return ['audit' => false, 'classified' => true];
        }

        return ['audit' => true, 'classified' => false];
    }

    /** @return array{audit: bool, classified: bool} */
    private function classifyAdmin(?string $action, ?string $method): array
    {
        if (in_array(strtoupper((string) $method), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return ['audit' => false, 'classified' => true];
        }

        if ($action !== null && in_array($action, self::ADMIN_DIAGNOSTIC_ACTIONS, true)) {
            return ['audit' => false, 'classified' => true];
        }

        if ($action !== null && in_array($action, self::ADMIN_AUDIT_ACTIONS, true)) {
            return ['audit' => true, 'classified' => true];
        }

        return ['audit' => true, 'classified' => false];
    }

    /** @return array{audit: bool, classified: bool} */
    private function classifyCallback(?string $action, ?int $status): array
    {
        if ($status !== 1) {
            return ['audit' => false, 'classified' => true];
        }

        if ($action !== null && in_array($action, self::CALLBACK_AUDIT_ACTIONS, true)) {
            return ['audit' => true, 'classified' => true];
        }

        return ['audit' => true, 'classified' => false];
    }

    /** @return array{audit: bool, classified: bool} */
    private function classifyError(?string $action): array
    {
        if ($action !== null && in_array($action, self::ERROR_DIAGNOSTIC_ACTIONS, true)) {
            return ['audit' => false, 'classified' => true];
        }

        return ['audit' => true, 'classified' => false];
    }

    /**
     * @param  list<string>  $audit
     * @param  list<string>  $diagnostic
     * @return array{audit: bool, classified: bool}
     */
    private function classifyFromLists(?string $value, array $audit, array $diagnostic): array
    {
        if ($value !== null && in_array($value, $audit, true)) {
            return ['audit' => true, 'classified' => true];
        }

        if ($value !== null && in_array($value, $diagnostic, true)) {
            return ['audit' => false, 'classified' => true];
        }

        return ['audit' => true, 'classified' => false];
    }

    private function actionFromUrl(string $channel, ?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $last = basename(rtrim($path, '/'));

        if ($channel === 'callback') {
            return match ($last) {
                'alipay' => 'alipayNotify',
                'wechat' => 'wechatNotify',
                default => $last !== '' ? 'index' : null,
            };
        }

        return match ($last) {
            'get-products' => 'getProducts',
            'get-orders' => 'getOrders',
            'get-order-id-by-refer-id' => 'getOrderIdByReferId',
            'update-dcv' => 'updateDCV',
            'upload-document' => 'uploadDocument',
            'toggle-auto-reissue' => 'toggleAutoReissue',
            default => $last !== '' ? $last : null,
        };
    }
}
