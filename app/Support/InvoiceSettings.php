<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Pengaturan penerbit invoice fee aplikasi — satu baris JSON di tabel `settings`
 * (key terpisah dari setting lain). Super admin yang mengubah; invoice TERBIT
 * menyalin (snapshot) nilainya, jadi perubahan di sini tak mengubah invoice lama.
 */
class InvoiceSettings
{
    public const KEY = 'app_fee_invoice_settings';

    public static function defaults(): array
    {
        return [
            'issuer_name' => 'Cemilan Qontas',
            'address' => '',
            'phone' => '',
            'email' => '',
            'bank_name' => '',
            'account_number' => '',
            'account_holder' => '',
            'due_days' => 7,
            'footer_note' => '',
        ];
    }

    public static function get(): array
    {
        $stored = json_decode((string) Setting::where('key', self::KEY)->value('value'), true);

        $merged = array_merge(self::defaults(), is_array($stored) ? array_intersect_key($stored, self::defaults()) : []);
        $merged['due_days'] = max(1, (int) $merged['due_days']);

        return $merged;
    }

    public static function save(array $data): void
    {
        $clean = array_intersect_key($data, self::defaults());
        foreach ($clean as $k => $v) {
            $clean[$k] = $k === 'due_days' ? (int) $v : trim((string) $v);
        }

        Setting::updateOrCreate(
            ['key' => self::KEY],
            ['value' => json_encode(array_merge(self::defaults(), $clean)), 'notes' => 'Pengaturan invoice fee aplikasi (super admin)']
        );
    }
}
