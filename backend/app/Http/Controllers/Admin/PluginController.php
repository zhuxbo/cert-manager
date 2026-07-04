<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\PluginOperationJob;
use App\Models\PluginOperation;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginOperationService;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class PluginController extends BaseController
{
    public function __construct(
        protected PluginManager $pluginManager,
        protected PluginOperationService $pluginOperations,
    ) {
        parent::__construct();
    }

    /**
     * 已安装插件列表
     */
    public function installed(): void
    {
        $plugins = $this->pluginManager->getInstalledPlugins();

        $this->success(['plugins' => $plugins]);
    }

    /**
     * 检查所有插件更新
     */
    public function checkUpdates(): void
    {
        $updates = $this->pluginManager->checkUpdates();

        $this->success(['updates' => $updates]);
    }

    /**
     * 安装插件（远程安装或上传安装）
     */
    public function install(Request $request): void
    {
        try {
            if ($request->hasFile('file')) {
                $operation = $this->pluginOperations->createUploadInstall(
                    (int) auth('admin')->id(),
                    $request->file('file')
                );
            } else {
                $name = $request->input('name');
                if (! $name) {
                    $this->error('请指定插件名称或上传 ZIP 文件');
                }

                $operation = $this->pluginOperations->createRemoteInstall(
                    (int) auth('admin')->id(),
                    $name,
                    $request->input('release_url'),
                    $request->input('version')
                );
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
        }

        $this->dispatchOperation($operation);

        $this->success(['operation' => $this->pluginOperations->toPublicArray($operation)]);
    }

    /**
     * 更新插件
     */
    public function update(Request $request): void
    {
        $name = $request->input('name');
        if (! $name) {
            $this->error('请指定插件名称');
        }

        $version = $request->input('version');

        try {
            $operation = $this->pluginOperations->createUpdate(
                (int) auth('admin')->id(),
                $name,
                $version
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
        }

        $this->dispatchOperation($operation);

        $this->success(['operation' => $this->pluginOperations->toPublicArray($operation)]);
    }

    /**
     * 卸载插件
     */
    public function uninstall(Request $request): void
    {
        $name = $request->input('name');
        if (! $name) {
            $this->error('请指定插件名称');
        }

        $removeData = (bool) $request->input('remove_data', false);
        try {
            $this->pluginOperations->assertNoActiveOperation($name);
            $result = $this->pluginOperations->withPluginMutex(
                $name,
                fn () => $this->pluginManager->uninstall($name, $removeData)
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
        }

        $this->success($result);
    }

    public function operations(): void
    {
        $this->success(['operations' => $this->pluginOperations->listVisible()]);
    }

    public function operation(string $uuid): void
    {
        $operation = $this->pluginOperations->findVisible($uuid);

        $this->success(['operation' => $this->pluginOperations->toPublicArray($operation)]);
    }

    public function failStaleOperation(string $uuid): void
    {
        try {
            $operation = $this->pluginOperations->failStale($uuid);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
        }

        $this->success(['operation' => $this->pluginOperations->toPublicArray($operation)]);
    }

    private function dispatchOperation(PluginOperation $operation): void
    {
        try {
            PluginOperationJob::dispatch($operation->uuid)
                ->afterCommit()
                ->onQueue(config('queue.names.tasks'));
        } catch (Throwable $e) {
            $this->pluginOperations->markQueuedFailed($operation, $e);
            $this->error('插件任务入队失败，请检查队列配置后重试');
        }
    }
}
