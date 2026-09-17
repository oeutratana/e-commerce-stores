<?php

namespace App\Services;

class KhqrGenerator
{
    public const CURRENCY_USD = '840';

    public const CURRENCY_KHR = '116';

    /**
     * Create a Tag-Length-Value (TLV) string.
     */
    public static function formatTlv(string $tag, string $value): string
    {
        $len = strlen($value);

        return sprintf('%02s%02d%s', $tag, $len, $value);
    }

    /**
     * Calculate CRC-16/CCITT-FALSE checksum.
     * Polynomial: 0x1021, Initial: 0xFFFF, RefIn: false, RefOut: false.
     */
    public static function calculateCrc16(string $data): string
    {
        $crc = 0xFFFF;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 8);

            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Convert currency code (USD/KHR or 840/116) to EMV currency code.
     */
    public static function normalizeCurrency(string $currency): string
    {
        $upper = strtoupper(trim($currency));

        return match ($upper) {
            'USD', '840' => self::CURRENCY_USD,
            'KHR', '116' => self::CURRENCY_KHR,
            default => self::CURRENCY_USD,
        };
    }

    /**
     * Generate an EMVCo-compliant KHQR string for Bakong.
     *
     * @param array{
     *     bakong_account_id: string,
     *     merchant_name: string,
     *     merchant_city?: string,
     *     currency?: string,
     *     amount: float|int|string,
     *     bill_number?: string|int,
     *     store_label?: string,
     *     terminal_label?: string
     * } $params
     * @return array{qr: string, md5: string}
     */
    public static function generate(array $params): array
    {
        $bakongAccountId = $params['bakong_account_id'] ?? 'merchant@devb';
        $merchantName = substr($params['merchant_name'] ?? 'E-Commerce Store', 0, 25);
        $merchantCity = substr($params['merchant_city'] ?? 'Phnom Penh', 0, 15);
        $currencyCode = self::normalizeCurrency($params['currency'] ?? 'USD');
        $amount = number_format((float) ($params['amount'] ?? 0), 2, '.', '');
        $billNumber = (string) ($params['bill_number'] ?? '');

        // 00: Payload Format Indicator
        $payload = self::formatTlv('00', '01');

        // 01: Point of Initiation Method (12 = Dynamic with amount)
        $payload .= self::formatTlv('01', '12');

        // 29: Merchant Account Information (Individual / Bakong Account)
        // Subtag 00: Bakong Account ID
        $merchantAccountInfo = self::formatTlv('00', $bakongAccountId);
        $payload .= self::formatTlv('29', $merchantAccountInfo);

        // 52: Merchant Category Code (5999 = Miscellaneous Retail)
        $payload .= self::formatTlv('52', '5999');

        // 53: Transaction Currency
        $payload .= self::formatTlv('53', $currencyCode);

        // 54: Transaction Amount
        $payload .= self::formatTlv('54', $amount);

        // 58: Country Code
        $payload .= self::formatTlv('58', 'KH');

        // 59: Merchant Name
        $payload .= self::formatTlv('59', $merchantName);

        // 60: Merchant City
        $payload .= self::formatTlv('60', $merchantCity);

        // 62: Additional Data Field Template
        $additionalData = '';
        if ($billNumber !== '') {
            // Subtag 01: Bill Number
            $additionalData .= self::formatTlv('01', $billNumber);
        }
        if (! empty($params['terminal_label'])) {
            // Subtag 07: Terminal Label
            $additionalData .= self::formatTlv('07', (string) $params['terminal_label']);
        }
        if ($additionalData !== '') {
            $payload .= self::formatTlv('62', $additionalData);
        }

        // 63: CRC Checksum placeholder (tag 63, length 04)
        $rawWithCrcTag = $payload.'6304';
        $crc = self::calculateCrc16($rawWithCrcTag);
        $finalKhqr = $rawWithCrcTag.$crc;

        $md5 = md5($finalKhqr);

        return [
            'qr' => $finalKhqr,
            'md5' => $md5,
        ];
    }
}
