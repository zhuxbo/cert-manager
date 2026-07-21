<?php

namespace App\Http\Requests\Setting;

use App\Http\Requests\BaseRequest;
use Closure;
use Illuminate\Http\UploadedFile;

class UploadSiteImageRequest extends BaseRequest
{
    public function rules(): array
    {
        $isLogo = $this->route('kind') === 'logo';
        $maxDimension = $isLogo ? 200 : 800;

        return [
            'file' => [
                'bail',
                'required',
                'file',
                $isLogo ? 'image:allow_svg' : 'image',
                $isLogo ? 'mimes:jpg,jpeg,png,webp,svg' : 'mimes:jpg,jpeg,png,webp',
                $isLogo ? 'max:200' : 'max:1024',
                function (string $attribute, mixed $value, Closure $fail) use ($isLogo, $maxDimension): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    // SVG 为矢量图，无固定像素尺寸；仅做安全校验（拒 DOCTYPE 防实体注入），
                    // 尺寸上限对矢量无意义，体积已由 max 规则限制
                    if ($value->getMimeType() === 'image/svg+xml') {
                        if (! $this->isSafeSvg($value)) {
                            $fail('SVG 文件格式不合法');
                        }

                        return;
                    }

                    $dimensions = $this->imageDimensions($value);
                    if ($dimensions === null) {
                        $fail($isLogo ? '无法读取 Logo 尺寸' : '无法读取二维码尺寸');

                        return;
                    }

                    [$width, $height] = $dimensions;
                    if ($width > $maxDimension || $height > $maxDimension) {
                        $fail($isLogo
                            ? 'Logo 尺寸不能超过 200×200 像素'
                            : '二维码尺寸不能超过 800×800 像素');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => '请选择图片',
            'file.image' => '上传文件必须是图片',
            'file.mimes' => $this->route('kind') === 'logo'
                ? 'Logo 仅支持 JPG、PNG、WebP、SVG 格式'
                : '二维码仅支持 JPG、PNG、WebP 格式',
            'file.max' => $this->route('kind') === 'logo'
                ? 'Logo 大小不能超过 200KB'
                : '二维码大小不能超过 1MB',
        ];
    }

    /**
     * 合法 SVG：不含 DOCTYPE（防实体注入）且以 <svg> 根标签开头，
     * 容忍前置 BOM / XML 声明 / 注释（Inkscape、Illustrator 等常在 <svg> 前输出 Generator 注释）。
     */
    private function isSafeSvg(UploadedFile $file): bool
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || stripos($contents, '<!DOCTYPE') !== false) {
            return false;
        }

        return preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml[^>]*>\s*)?(?:<!--.*?-->\s*)*<svg\b[^>]*>/is', $contents) === 1;
    }

    /** @return array{float, float}|null */
    private function imageDimensions(UploadedFile $file): ?array
    {
        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions === false) {
            return null;
        }

        return [(float) $dimensions[0], (float) $dimensions[1]];
    }
}
