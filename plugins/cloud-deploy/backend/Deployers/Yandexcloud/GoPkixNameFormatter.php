<?php

namespace Plugins\CloudDeploy\Deployers\Yandexcloud;

use phpseclib3\File\ASN1;
use phpseclib3\File\ASN1\Element;
use phpseclib3\File\ASN1\Maps\AttributeValue;
use phpseclib3\File\X509;

/** 复刻 Go crypto/x509/pkix.Name.String() 的证书 DN 输出。 */
class GoPkixNameFormatter
{
    /** Go Name.ToRDNSequence 的标准属性顺序；输出时整体逆序。 */
    private const STANDARD_TYPES = [
        'id-at-countryName' => 'C',
        'id-at-stateOrProvinceName' => 'ST',
        'id-at-localityName' => 'L',
        'id-at-streetAddress' => 'STREET',
        'id-at-postalCode' => 'POSTALCODE',
        'id-at-organizationName' => 'O',
        'id-at-organizationalUnitName' => 'OU',
        'id-at-commonName' => 'CN',
        'id-at-serialNumber' => 'SERIALNUMBER',
    ];

    /** @return array{subject:string,issuer:string} */
    public function fromCertificate(string $certificatePem): array
    {
        $x509 = new X509;
        $certificate = $x509->loadX509($certificatePem);
        if (! is_array($certificate)) {
            throw new YandexcloudApiException('BadRequest', '证书内容无法解析');
        }

        $subject = $certificate['tbsCertificate']['subject']['rdnSequence'] ?? null;
        $issuer = $certificate['tbsCertificate']['issuer']['rdnSequence'] ?? null;
        if (! is_array($subject) || ! is_array($issuer)) {
            throw new YandexcloudApiException('BadRequest', '证书 DN 无法解析');
        }

        return [
            'subject' => $this->format($subject),
            'issuer' => $this->format($issuer),
        ];
    }

    /** @param list<list<array<string, mixed>>> $rdnSequence */
    private function format(array $rdnSequence): string
    {
        /** @var array<string,list<string>> $standardValues */
        $standardValues = [];
        /** @var list<string> $extraValues */
        $extraValues = [];

        foreach ($rdnSequence as $rdn) {
            foreach ($rdn as $attribute) {
                $type = isset($attribute['type']) ? (string) $attribute['type'] : '';
                $value = $attribute['value'] ?? null;
                if (isset(self::STANDARD_TYPES[$type])) {
                    $standardValues[$type][] = $this->stringValue($value);

                    continue;
                }

                $oid = ASN1::getOID($type);
                $extraValues[] = $oid.'=#'.bin2hex($this->goMarshalValue($value));
            }
        }

        $parts = [];
        foreach (array_reverse(self::STANDARD_TYPES, true) as $type => $label) {
            if (! isset($standardValues[$type])) {
                continue;
            }
            $values = array_map(
                fn (string $value): string => $label.'='.$this->escapeValue($value),
                $standardValues[$type],
            );
            $parts[] = implode('+', $values);
        }

        foreach (array_reverse($extraValues) as $value) {
            $parts[] = $value;
        }

        return implode(',', $parts);
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value) && count($value) === 1) {
            $typeName = (string) array_key_first($value);
            $raw = $value[$typeName];
            $type = array_search($typeName, ASN1::ANY_MAP, true);
            if (is_int($type) && isset(ASN1::STRING_TYPE_SIZE[$type]) && is_string($raw)) {
                return ASN1::convert($raw, $type, ASN1::TYPE_UTF8_STRING);
            }
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new YandexcloudApiException('BadRequest', '证书 DN 属性无法解析');
    }

    /** Go asn1.Marshal(interface-string) 会统一重新编码为 UTF8String。 */
    private function goMarshalValue(mixed $value): string
    {
        if (is_array($value) && count($value) === 1) {
            $typeName = (string) array_key_first($value);
            $type = array_search($typeName, ASN1::ANY_MAP, true);
            if (is_int($type) && isset(ASN1::STRING_TYPE_SIZE[$type])) {
                return $this->encodeUtf8String($this->stringValue($value));
            }
        }

        if ($value instanceof Element) {
            return $value->element;
        }

        return ASN1::encodeDER($value, AttributeValue::MAP);
    }

    private function encodeUtf8String(string $value): string
    {
        $length = strlen($value);
        if ($length < 128) {
            return "\x0C".chr($length).$value;
        }

        $bytes = '';
        for ($remaining = $length; $remaining > 0; $remaining >>= 8) {
            $bytes = chr($remaining & 0xFF).$bytes;
        }

        return "\x0C".chr(0x80 | strlen($bytes)).$bytes.$value;
    }

    private function escapeValue(string $value): string
    {
        $escaped = (string) preg_replace('/([,+"\\\\<>;])/', '\\\\$1', $value);
        if ($value !== '' && ($value[0] === ' ' || $value[0] === '#')) {
            $escaped = '\\'.$escaped;
        }
        if (strlen($value) > 1 && str_ends_with($value, ' ')) {
            $escaped = substr($escaped, 0, -1).'\\ ';
        }

        return $escaped;
    }
}
