{{--
    TUGAS 1 (BRIEF 23 Okt 2026, lanjutan) — "Rincian Fee Aplikasi Harian".
    Tabel manual (bukan Filament\Tables\Table) karena kuncinya KOMPOSIT
    (tanggal, owner) + butuh baris TOTAL — lihat docblock kelas
    AppFeeDailyWidget. wire:poll.30s SAMA seperti AppFeeWidget (TUGAS 1d).
--}}
<x-filament-widgets::widget wire:poll.30s>
    <x-filament::section :heading="$this->getHeading()">
        <x-slot name="headerEnd">
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::input.wrapper class="w-36">
                    <x-filament::input
                        type="month"
                        wire:model.live="month"
                    />
                </x-filament::input.wrapper>

                <x-filament::input.wrapper class="w-48">
                    <x-filament::input.select wire:model.live="ownerId">
                        <option value="0">Semua Owner (bertarif &gt; 0)</option>
                        @foreach ($owners as $owner)
                            <option value="{{ $owner->id }}">{{ $owner->name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </x-slot>

        <div class="overflow-x-auto -mx-6 px-6 sm:mx-0 sm:px-0">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-500 dark:text-gray-400 text-xs uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left whitespace-nowrap">Tanggal</th>
                        <th class="px-3 py-2 text-left">Owner</th>
                        <th class="px-3 py-2 text-right whitespace-nowrap">Mika Diantar</th>
                        <th class="px-3 py-2 text-right whitespace-nowrap">Mika Kena Fee</th>
                        <th class="px-3 py-2 text-right whitespace-nowrap">Fee (Rp)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5 text-gray-700 dark:text-gray-300">
                    @forelse ($rows as $row)
                        <tr wire:key="fee-harian-{{ $row['date'] }}-{{ $row['owner_id'] }}">
                            <td class="px-3 py-2 whitespace-nowrap">{{ $row['date_label'] }}</td>
                            <td class="px-3 py-2">{{ $row['owner_name'] }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($row['mika_diantar'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($row['mika_kena_fee'], 0, ',', '.') }}</td>
                            <td class="px-3 py-2 text-right font-medium text-gray-950 dark:text-white">Rp {{ number_format($row['fee'], 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-3 py-6 text-center text-gray-400 dark:text-gray-500">
                                Tidak ada aktivitas di bulan ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-white/5 font-bold text-gray-950 dark:text-white">
                    <tr>
                        <td class="px-3 py-2" colspan="2">TOTAL</td>
                        <td class="px-3 py-2 text-right">{{ number_format($total['mika_diantar'], 0, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($total['mika_kena_fee'], 0, ',', '.') }}</td>
                        <td class="px-3 py-2 text-right">Rp {{ number_format($total['fee'], 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
