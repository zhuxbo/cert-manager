<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Setting\ClearPayCacheRequest;
use App\Http\Requests\Setting\GetIdsRequest;
use App\Http\Requests\Setting\StoreRequest;
use App\Http\Requests\Setting\UpdateRequest;
use App\Http\Requests\Setting\UploadSiteImageRequest;
use App\Models\Setting;
use App\Models\SettingGroup;
use App\Services\Payment\PayConfigCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SettingController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取所有设置
     */
    public function index(): void
    {
        // 获取所有设置组及其设置项
        $groups = SettingGroup::with(['settings' => function ($query) {
            $query->orderBy('weight', 'asc')->orderBy('id', 'asc');
        }])
            ->orderBy('weight')
            ->orderBy('id')
            ->get();

        $this->success([
            'groups' => $groups,
        ]);
    }

    /**
     * 获取指定组的设置项
     */
    public function getByGroup($groupId): void
    {
        $group = SettingGroup::with(['settings' => function ($query) {
            $query->orderBy('weight', 'asc')->orderBy('id', 'asc');
        }])->find($groupId);

        if (! $group) {
            $this->error('设置组不存在');
        }

        $this->success([
            'group' => $group,
        ]);
    }

    /**
     * 添加设置
     */
    public function store(StoreRequest $request): void
    {
        $setting = Setting::create($request->validated());

        if (! $setting->exists) {
            $this->error('添加失败');
        }

        $this->success(['id' => $setting->id]);
    }

    /**
     * 获取设置资料
     */
    public function show($id): void
    {
        $setting = Setting::find($id);
        if (! $setting) {
            $this->error('设置不存在');
        }

        $this->success($setting->toArray());
    }

    /**
     * 批量获取设置资料
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $settings = Setting::whereIn('id', $ids)->get();
        if ($settings->isEmpty()) {
            $this->error('设置不存在');
        }

        $this->success($settings->toArray());
    }

    /**
     * 批量更新设置
     */
    public function batchUpdate(UpdateRequest $request): void
    {
        $settings = $request->validated('settings');

        if (! is_array($settings) || empty($settings)) {
            $this->error('设置数据不能为空');
        }

        foreach ($settings as $settingData) {
            if (! isset($settingData['id']) || ! isset($settingData['value'])) {
                continue;
            }

            $setting = Setting::find($settingData['id']);
            if (! $setting) {
                continue;
            }

            // 只更新值字段
            $setting->value = $settingData['value'];
            $setting->save();
        }

        $this->success();
    }

    /**
     * 更新设置资料
     */
    public function update(UpdateRequest $request, $id): void
    {
        $setting = Setting::find($id);
        if (! $setting) {
            $this->error('设置不存在');
        }

        $setting->fill($request->validated());
        $setting->save();

        $this->success();
    }

    /**
     * 删除设置
     */
    public function destroy($id): void
    {
        $setting = Setting::find($id);
        if (! $setting) {
            $this->error('设置不存在');
        }

        $setting->delete();
        $this->success();
    }

    /**
     * 批量删除设置
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $settings = Setting::whereIn('id', $ids)->get();
        if ($settings->isEmpty()) {
            $this->error('设置不存在');
        }

        Setting::destroy($ids);
        $this->success();
    }

    /**
     * 清除所有设置缓存
     */
    public function clearCache(): void
    {
        Setting::clearAllCache();
        $this->success();
    }

    /**
     * 清除支付配置缓存并删除已落盘的支付证书（下次调用支付时按当前设置重新落盘）。
     *
     * 不传 type 清全部支付类型；支付设置组保存后由 Setting::clearGroupCache 自动清理，
     * 本端点用于设置未变但磁盘证书需强制重建的场景。
     */
    public function clearPayCache(ClearPayCacheRequest $request): void
    {
        $type = $request->validated('type');
        if (is_string($type) && $type !== '') {
            PayConfigCache::forget($type);
        } else {
            PayConfigCache::forgetAll();
        }
        $this->success();
    }

    /**
     * 清除系统全部缓存
     */
    public function clearAllCache(): void
    {
        try {
            Artisan::call('cache:clear-all', ['--quick' => true, '--without-composer' => true]);
        } catch (Throwable) {
            $this->error('缓存清除失败');
        }
        $this->success();
    }

    /**
     * 上传站点 Favicon、Logo、展开版 Logo、客服二维码或用户端登录配图。
     */
    public function uploadSiteImage(UploadSiteImageRequest $request, string $kind): void
    {
        $settingKey = match ($kind) {
            'logo-expanded' => 'logoExpanded',
            'login-image' => 'loginImage',
            default => $kind,
        };
        $isLogo = in_array($kind, ['logo', 'logo-expanded'], true);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/svg+xml' => $isLogo ? 'svg' : null,
            default => null,
        };
        if ($kind === 'favicon') {
            $extension = 'ico';
        }
        if ($extension === null) {
            $this->error('不支持的图片格式');
        }

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            $this->error('读取上传图片失败');
        }

        $path = 'site/'.$kind.'-'.hash('sha256', $contents).'.'.$extension;
        $group = SettingGroup::where('name', 'site')->first();
        if (! $group) {
            $this->error('站点设置不存在');
        }

        $setting = Setting::where('group_id', $group->id)
            ->where('key', $settingKey)
            ->where('type', 'image')
            ->first();
        if (! $setting) {
            $this->error('站点图片设置不存在');
        }

        $oldUrl = is_string($setting->value) ? $setting->value : '';
        $oldPath = $this->managedSiteImagePath($oldUrl, $kind);
        $url = '/api/meta/site-image/'.basename($path);
        if (! Storage::disk('public')->put($path, $contents)) {
            $this->error('保存图片失败');
        }

        try {
            $setting->value = $url;
            $setting->save();
        } catch (Throwable) {
            if ($oldPath !== $path) {
                Storage::disk('public')->delete($path);
            }
            $this->error('保存站点图片设置失败');
        }

        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->success(['url' => $url]);
    }

    /**
     * 清除站点图片设置并删除托管文件（恢复默认回落资源）。
     */
    public function deleteSiteImage(string $kind): void
    {
        $settingKey = match ($kind) {
            'logo-expanded' => 'logoExpanded',
            'login-image' => 'loginImage',
            default => $kind,
        };

        $group = SettingGroup::where('name', 'site')->first();
        if (! $group) {
            $this->error('站点设置不存在');
        }

        $setting = Setting::where('group_id', $group->id)
            ->where('key', $settingKey)
            ->where('type', 'image')
            ->first();
        if (! $setting) {
            $this->error('站点图片设置不存在');
        }

        $oldUrl = is_string($setting->value) ? $setting->value : '';
        $oldPath = $this->managedSiteImagePath($oldUrl, $kind);

        $setting->value = '';
        $setting->save();

        // 仅删除本系统托管的文件；外部 URL 只清配置不动文件
        if ($oldPath !== null) {
            Storage::disk('public')->delete($oldPath);
        }

        $this->success();
    }

    private function managedSiteImagePath(string $url, string $kind): ?string
    {
        $prefix = "/api/meta/site-image/$kind-";
        if (! str_starts_with($url, $prefix)) {
            return null;
        }

        $path = 'site/'.substr($url, strlen('/api/meta/site-image/'));

        $kindPattern = preg_quote($kind, '/');
        $extensions = match ($kind) {
            'favicon' => 'ico',
            'qrcode', 'login-image' => 'jpg|png|webp',
            default => 'jpg|png|webp|svg',
        };

        return preg_match("/^site\/$kindPattern-[a-f0-9]{64}\.($extensions)$/", $path) === 1
            ? $path
            : null;
    }
}
