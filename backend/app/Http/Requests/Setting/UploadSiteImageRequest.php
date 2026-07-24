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
        if ($kind === 'favicon') {
            return [
                'file' => [
                    'bail',
                    'required',
                    'file',
                    'max:200',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (! $value instanceof UploadedFile) {
                            return;
                        }

                        if (strtolower($value->getClientOriginalExtension()) !== 'ico' || ! $this->isValidIco($value)) {
                            $fail('Favicon 仅支持 ICO 格式');
                        }
                    },
                ],
            ];
        }

        $isLogo = in_array($kind, ['logo', 'logo-expanded'], true);
        $isLoginImage = $kind === 'login-image';
        $requiresSquare = in_array($kind, ['logo', 'qrcode'], true);
        $maxDimension = $isLogo ? 200 : ($isLoginImage ? 2560 : 800);
        $kindLabel = $isLogo ? 'Logo' : ($isLoginImage ? '登录配图' : '二维码');

        return [
            'file' => [
                'bail',
                'required',
                'file',
                $isLogo ? 'image:allow_svg' : 'image',
                $isLogo ? 'mimes:jpg,jpeg,png,webp,svg' : 'mimes:jpg,jpeg,png,webp',
                $isLogo ? 'max:200' : ($isLoginImage ? 'max:2048' : 'max:1024'),
                function (string $attribute, mixed $value, Closure $fail) use ($isLogo, $kind, $kindLabel, $maxDimension, $requiresSquare): void {
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
                        $fail($isLogo
                            ? '无法读取 Logo 尺寸'
                            : "无法读取{$kindLabel}尺寸");

                        return;
                    }

                    [$width, $height] = $dimensions;
                    if ($width > $maxDimension || $height > $maxDimension) {
                        $fail($isLogo
                            ? 'Logo 尺寸不能超过 200×200 像素'
                            : "{$kindLabel}尺寸不能超过 {$maxDimension}×{$maxDimension} 像素");

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
        if ($this->route('kind') === 'favicon') {
            return [
                'file.required' => '请选择 Favicon',
                'file.max' => 'Favicon 大小不能超过 200KB',
            ];
        }

        $isLogo = in_array($this->route('kind'), ['logo', 'logo-expanded'], true);
        $isLoginImage = $this->route('kind') === 'login-image';

        return [
            'file.required' => '请选择图片',
            'file.image' => '上传文件必须是图片',
            'file.mimes' => $isLogo
                ? 'Logo 仅支持 JPG、PNG、WebP、SVG 格式'
                : ($isLoginImage ? '登录配图仅支持 JPG、PNG、WebP 格式' : '二维码仅支持 JPG、PNG、WebP 格式'),
            'file.max' => $isLogo
                ? 'Logo 大小不能超过 200KB'
                : ($isLoginImage ? '登录配图大小不能超过 2MB' : '二维码大小不能超过 1MB'),
        ];
    }

    private function isValidIco(UploadedFile $file): bool
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || strlen($contents) < 22) {
            return false;
        }

        $header = unpack('vreserved/vtype/vcount', substr($contents, 0, 6));
        $count = is_array($header) ? (int) ($header['count'] ?? 0) : 0;
        $directoryLength = 6 + ($count * 16);
        if (($header['reserved'] ?? -1) !== 0
            || ($header['type'] ?? -1) !== 1
            || $count < 1
            || $count > 256
            || strlen($contents) < $directoryLength) {
            return false;
        }

        for ($index = 0; $index < $count; $index++) {
            $directoryEntry = substr($contents, 6 + ($index * 16), 16);
            $entry = unpack(
                'Vsize/Voffset',
                substr($directoryEntry, 8, 8),
            );
            $size = is_array($entry) ? (int) ($entry['size'] ?? 0) : 0;
            $offset = is_array($entry) ? (int) ($entry['offset'] ?? 0) : 0;
            if ($size < 1 || $offset < $directoryLength || $offset + $size > strlen($contents)) {
                return false;
            }

            $payload = substr($contents, $offset, $size);
            if (! $this->isValidIcoPng($payload) && ! $this->isValidIcoDib($payload)) {
                return false;
            }
        }

        return true;
    }

    private function isValidIcoPng(string $payload): bool
    {
        if (! str_starts_with($payload, "\x89PNG\r\n\x1a\n")) {
            return false;
        }

        $dimensions = @getimagesizefromstring($payload);

        return is_array($dimensions)
            && $dimensions['mime'] === 'image/png'
            && $dimensions[0] > 0
            && $dimensions[1] > 0;
    }

    private function isValidIcoDib(string $payload): bool
    {
        if (strlen($payload) < 40) {
            return false;
        }

        $header = unpack(
            'Vsize/Vwidth/Vheight/vplanes/vbitCount/Vcompression/VimageSize/VxPelsPerMeter/VyPelsPerMeter/VcolorsUsed/VcolorsImportant',
            substr($payload, 0, 40),
        );
        if (! is_array($header)) {
            return false;
        }

        $headerSize = (int) ($header['size'] ?? 0);
        $width = (int) ($header['width'] ?? 0);
        $combinedHeight = (int) ($header['height'] ?? 0);
        $planes = (int) ($header['planes'] ?? 0);
        $bitCount = (int) ($header['bitCount'] ?? 0);
        $compression = (int) ($header['compression'] ?? -1);
        if (! in_array($headerSize, [40, 52, 56, 108, 124], true)
            || strlen($payload) < $headerSize
            || $width < 1
            || $combinedHeight < 2
            || $combinedHeight % 2 !== 0
            || $planes !== 1
            || ! in_array($bitCount, [1, 4, 8, 16, 24, 32], true)
            || ! in_array($compression, [0, 3, 6], true)) {
            return false;
        }

        $height = intdiv($combinedHeight, 2);
        $colorsUsed = (int) ($header['colorsUsed'] ?? 0);
        $paletteEntries = $bitCount <= 8 ? ($colorsUsed ?: 1 << $bitCount) : 0;
        $externalMasksLength = $headerSize === 40 && in_array($compression, [3, 6], true)
            ? ($compression === 6 ? 16 : 12)
            : 0;
        $pixelOffset = $headerSize + $externalMasksLength + ($paletteEntries * 4);
        $xorBytes = intdiv(($width * $bitCount) + 31, 32) * 4 * $height;
        $andBytes = intdiv($width + 31, 32) * 4 * $height;

        return strlen($payload) >= $pixelOffset + $xorBytes + $andBytes;
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
