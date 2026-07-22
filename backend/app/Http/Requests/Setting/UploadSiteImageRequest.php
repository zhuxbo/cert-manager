<?php

namespace App\Http\Requests\Setting;

use App\Http\Requests\BaseRequest;
use Closure;
use Illuminate\Http\UploadedFile;

class UploadSiteImageRequest extends BaseRequest
{
    public function rules(): array
    {
        $kind = $this->route('kind');
        $isLogo = in_array($kind, ['logo', 'logo-expanded'], true);
        $requiresSquare = in_array($kind, ['logo', 'qrcode'], true);
        $maxDimension = $isLogo ? 200 : 800;

        return [
            'file' => [
                'bail',
                'required',
                'file',
                $isLogo ? 'image:allow_svg' : 'image',
                $isLogo ? 'mimes:jpg,jpeg,png,webp,svg' : 'mimes:jpg,jpeg,png,webp',
                $isLogo ? 'max:200' : 'max:1024',
                function (string $attribute, mixed $value, Closure $fail) use ($isLogo, $kind, $maxDimension, $requiresSquare): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    // SVG 为矢量图，尺寸上限对矢量无意义，体积已由 max 规则限制；
                    // 普通 Logo 仍按 viewBox 或 width/height 校验为正方形。
                    if ($value->getMimeType() === 'image/svg+xml') {
                        if (! $this->isSafeSvg($value)) {
                            $fail('SVG 文件格式不合法');

                            return;
                        }
                        if ($requiresSquare && ! $this->isSquareSvg($value)) {
                            $fail('普通 Logo 必须为正方形');
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

                        return;
                    }
                    if ($requiresSquare && $width !== $height) {
                        $fail($kind === 'logo' ? '普通 Logo 必须为正方形' : '二维码必须为正方形');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        $isLogo = in_array($this->route('kind'), ['logo', 'logo-expanded'], true);

        return [
            'file.required' => '请选择图片',
            'file.image' => '上传文件必须是图片',
            'file.mimes' => $isLogo
                ? 'Logo 仅支持 JPG、PNG、WebP、SVG 格式'
                : '二维码仅支持 JPG、PNG、WebP 格式',
            'file.max' => $isLogo
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

    private function isSquareSvg(UploadedFile $file): bool
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || preg_match('/<svg\b([^>]*)>/is', $contents, $root) !== 1) {
            return false;
        }

        $hasWidth = $this->hasSvgLengthAttribute($root[1], 'width');
        $hasHeight = $this->hasSvgLengthAttribute($root[1], 'height');
        if ($hasWidth || $hasHeight) {
            $width = $this->svgLength($root[1], 'width');
            $height = $this->svgLength($root[1], 'height');

            return $width !== null
                && $height !== null
                && $width['value'] > 0
                && $width === $height;
        }

        if (preg_match('/\bviewBox\s*=\s*(["\'])(.*?)\1/is', $root[1], $viewBox) === 1) {
            $values = preg_split('/[\s,]+/', trim($viewBox[2]));
            if (is_array($values) && count($values) === 4 && is_numeric($values[2]) && is_numeric($values[3])) {
                return (float) $values[2] > 0 && (float) $values[2] === (float) $values[3];
            }
        }

        return false;
    }

    private function hasSvgLengthAttribute(string $attributes, string $name): bool
    {
        $pattern = sprintf('/(?:^|\s)%s\s*=/i', preg_quote($name, '/'));

        return preg_match($pattern, $attributes) === 1;
    }

    /** @return array{value: float, unit: string}|null */
    private function svgLength(string $attributes, string $name): ?array
    {
        $pattern = sprintf('/(?:^|\s)%s\s*=\s*(["\'])\s*([0-9]+(?:\.[0-9]+)?)\s*([a-z%%]*)\s*\1/i', preg_quote($name, '/'));
        if (preg_match($pattern, $attributes, $match) !== 1) {
            return null;
        }

        return [
            'value' => (float) $match[2],
            'unit' => strtolower($match[3]),
        ];
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
