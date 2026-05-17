<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'business_name' => 'Cemilan Qontas',
            'default_expiry_days' => '21',
            'default_warning_threshold_days' => '10',
            'default_target_interval_days' => '14',
            'default_margin_assumption' => '0.3000',
            'default_commission_rate' => '0.2000',
            'whatsapp_owner' => '+62 896 1353 1255',
            'currency_symbol' => 'Rp',
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }
}
