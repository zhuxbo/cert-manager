<?php

namespace Plugins\CloudDeploy\Services;

use Illuminate\Database\QueryException;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Models\CloudDeployRemoteCert;

class RemoteCertStore
{
    /**
     * 确保证书已上传到该云账号的证书服务，返回 remote_cert_id。
     * 去重键 = (access_id, store_kind, fingerprint)；store_kind 由 uploader 出，隔离不同标识空间（cas/slb/tencent_ssl）。
     * 并发竞态靠唯一索引兜底。
     *
     * @param  array<string,mixed>  $credentials
     */
    public function ensure(
        CertUploaderInterface $uploader,
        int $accessId,
        int $userId,
        int $certId,
        string $fingerprint,
        string $certPem,
        string $keyPem,
        string $chainPem,
        array $credentials,
    ): string {
        $storeKind = $uploader->storeKind();

        $existing = CloudDeployRemoteCert::where('access_id', $accessId)
            ->where('store_kind', $storeKind)
            ->where('fingerprint', $fingerprint)
            ->value('remote_cert_id');
        if ($existing !== null) {
            return $existing;
        }

        $remoteCertId = $uploader->upload($certPem, $keyPem, $chainPem, $credentials);

        try {
            CloudDeployRemoteCert::create([
                'user_id' => $userId,
                'access_id' => $accessId,
                'cert_id' => $certId,
                'fingerprint' => $fingerprint,
                'store_kind' => $storeKind,
                'remote_cert_id' => $remoteCertId,
            ]);
        } catch (QueryException $e) {
            // 1062 并发重复：另一个 job 已落库，回查复用（云端可能多传一张，可接受）
            if (($e->errorInfo[1] ?? null) === 1062) {
                return CloudDeployRemoteCert::where('access_id', $accessId)
                    ->where('store_kind', $storeKind)
                    ->where('fingerprint', $fingerprint)
                    ->value('remote_cert_id') ?? $remoteCertId;
            }
            throw $e;
        }

        return $remoteCertId;
    }
}
