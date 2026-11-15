<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\InvoiceSettingsPage;
use App\Filament\Resources\AppFeeInvoiceResource;
use App\Filament\Resources\AppFeeInvoiceResource\Pages\ListAppFeeInvoices;
use App\Filament\Widgets\AppFeeDailyWidget;
use App\Models\AppFeeInvoice;
use App\Models\Cluster;
use App\Models\Delivery;
use App\Models\Kiosk;
use App\Models\ProductVariant;
use App\Models\Trip;
use App\Models\User;
use App\Support\AppFeeCalculator;
use App\Support\AppFeeInvoicePdf;
use App\Support\AppFeeInvoiceService;
use App\Support\InvoiceException;
use App\Support\InvoiceSettings;
use App\Support\Terbilang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BRIEF invoice fee aplikasi — super admin saja. "Sekarang" dibekukan di 3 Des 2026
 * sehingga periode 2026-11 sudah LEWAT (bisa terbit) dan 2026-12 = bulan berjalan (draft).
 */
class AppFeeInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-11';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 12, 3)->setTime(10, 0));
        $this->admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function owner(float $rate = 300, string $business = 'Kuali Stories'): User
    {
        return User::factory()->create([
            'role' => 'owner', 'is_active' => true, 'app_fee_per_pack' => $rate, 'business_name' => $business,
        ]);
    }

    /** Satu trip selesai dengan 1 delivery cash_sale. */
    private function sale(User $owner, string $date, int $qty, float $rate = 300, int $tripNo = 1): Trip
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);
        $cluster = Cluster::create(['name' => 'C-'.$owner->id.'-'.$date.$tripNo, 'owner_id' => $owner->id]);
        $kiosk = Kiosk::factory()->create(['cluster_id' => $cluster->id]);
        $variant = ProductVariant::factory()->create(['is_active' => true]);

        $trip = Trip::create([
            'owner_id' => $owner->id, 'operator_id' => $operator->id,
            'trip_date' => $date, 'trip_number_of_day' => $tripNo,
            'started_at' => $date.' 08:00:00', 'ended_at' => $date.' 12:00:00',
            'app_fee_per_pack_snapshot' => $rate,
        ]);
        Delivery::factory()->create([
            'kiosk_id' => $kiosk->id, 'trip_id' => $trip->id, 'product_variant_id' => $variant->id,
            'delivery_type' => 'cash_sale', 'qty_delivered' => $qty,
        ]);

        return $trip;
    }

    /** Owner dgn 2 hari fee: 10 mika (3 Nov) + 4 mika (9 Nov) = 14 mika × 300 = Rp 4.200. */
    private function ownerWithNovemberFee(): User
    {
        $owner = $this->owner();
        $this->sale($owner, '2026-11-03', 10);
        $this->sale($owner, '2026-11-09', 4);

        return $owner;
    }

    // ---- angka = AppFeeCalculator -------------------------------------------------

    public function test_totals_equal_app_fee_calculator(): void
    {
        $owner = $this->ownerWithNovemberFee();
        $other = $this->owner(300, 'Lain');
        $this->sale($other, '2026-11-05', 99);

        $calc = AppFeeInvoiceService::compute($owner, self::PERIOD);
        $expected = collect(AppFeeCalculator::summaryByOwnerAndDate(2026, 11))->where('owner_id', $owner->id);

        $this->assertSame((int) $expected->sum('mika_kena_fee'), $calc['total_mika']);
        $this->assertSame((float) $expected->sum('fee'), $calc['total_fee']);
        $this->assertSame(14, $calc['total_mika']);
        $this->assertSame(4200.0, $calc['total_fee']);
        $this->assertCount(2, $calc['lines']); // owner lain tak ikut
    }

    // ---- terbit --------------------------------------------------------------------

    public function test_issue_only_for_past_months(): void
    {
        $owner = $this->owner();
        $this->sale($owner, '2026-12-01', 5); // bulan berjalan

        $this->expectException(InvoiceException::class);
        AppFeeInvoiceService::issue($owner, '2026-12', $this->admin);
    }

    public function test_issue_rejected_for_future_and_invalid_period(): void
    {
        $owner = $this->ownerWithNovemberFee();

        foreach (['2027-01', '2026-13', 'abc'] as $p) {
            try {
                AppFeeInvoiceService::issue($owner, $p, $this->admin);
                $this->fail("Periode {$p} seharusnya ditolak");
            } catch (InvoiceException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, AppFeeInvoice::count());
    }

    public function test_issue_creates_numbered_invoice_with_due_date_and_snapshot(): void
    {
        InvoiceSettings::save(['issuer_name' => 'Cemilan Qontas', 'due_days' => 10, 'bank_name' => 'BCA', 'account_number' => '123', 'account_holder' => 'Ismi']);
        $owner = $this->ownerWithNovemberFee();

        $inv = AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);

        $this->assertSame('CQ-INV-202611-001', $inv->number);
        $this->assertSame('issued', $inv->status);
        $this->assertSame('2026-12-13', $inv->due_date->toDateString());
        $this->assertSame(14, $inv->total_mika);
        $this->assertSame(4200.0, $inv->total_fee);
        $this->assertSame($this->admin->id, $inv->created_by);
        $this->assertSame('BCA', $inv->issuer['bank_name']);
        $this->assertSame('Kuali Stories', $inv->billed_to['business']);
    }

    public function test_numbers_are_sequential_per_period_across_owners(): void
    {
        $a = $this->ownerWithNovemberFee();
        $b = $this->owner(300, 'Warung B');
        $this->sale($b, '2026-11-04', 3);
        $c = $this->owner(300, 'Warung C');
        $this->sale($c, '2026-10-04', 3);

        $this->assertSame('CQ-INV-202611-001', AppFeeInvoiceService::issue($a, '2026-11', $this->admin)->number);
        $this->assertSame('CQ-INV-202611-002', AppFeeInvoiceService::issue($b, '2026-11', $this->admin)->number);
        $this->assertSame('CQ-INV-202610-001', AppFeeInvoiceService::issue($c, '2026-10', $this->admin)->number);
    }

    public function test_only_one_active_invoice_per_owner_and_period(): void
    {
        $owner = $this->ownerWithNovemberFee();
        AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);

        try {
            AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);
            $this->fail('Terbit ganda seharusnya ditolak');
        } catch (InvoiceException $e) {
            $this->assertStringContainsString('CQ-INV-202611-001', $e->getMessage());
        }
        $this->assertSame(1, AppFeeInvoice::count());

        // Sudah lunas pun tetap dianggap aktif.
        AppFeeInvoiceService::markPaid(AppFeeInvoice::first(), today(), null);
        $this->expectException(InvoiceException::class);
        AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);
    }

    public function test_owner_with_zero_fee_cannot_be_invoiced(): void
    {
        $owner = $this->owner(0);
        $this->sale($owner, '2026-11-03', 10, rate: 0); // mika ada, tarif 0 → fee Rp 0

        $this->expectException(InvoiceException::class);
        AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);
    }

    public function test_owner_without_activity_cannot_be_invoiced_and_has_no_draft_download(): void
    {
        $owner = $this->owner();

        $this->actingAs($this->admin)
            ->get(route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => self::PERIOD]))
            ->assertStatus(422);
        $this->assertSame(0, AppFeeInvoice::count());
    }

    // ---- batal & terbit ulang ------------------------------------------------------

    public function test_void_requires_reason_then_reissue_gets_new_number(): void
    {
        $owner = $this->ownerWithNovemberFee();
        $first = AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);

        try {
            AppFeeInvoiceService::void($first, '   ');
            $this->fail('Alasan kosong seharusnya ditolak');
        } catch (InvoiceException) {
            $this->assertSame('issued', $first->fresh()->status);
        }

        $voided = AppFeeInvoiceService::void($first, 'Salah tarif');
        $this->assertSame('void', $voided->status);
        $this->assertSame('Salah tarif', $voided->void_reason);
        $this->assertNotNull($voided->voided_at);

        $second = AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);
        $this->assertSame('CQ-INV-202611-002', $second->number); // nomor lama tak dipakai ulang
        $this->assertSame('void', $first->fresh()->status);
    }

    public function test_voided_invoice_pdf_still_downloadable_with_stamp_data(): void
    {
        $owner = $this->ownerWithNovemberFee();
        $inv = AppFeeInvoiceService::void(AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin), 'Batal');

        $this->actingAs($this->admin)->get(route('admin.invoice-pdf.show', $inv))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame('void', AppFeeInvoicePdf::dataFromInvoice($inv)['mode']);
    }

    // ---- lunas ---------------------------------------------------------------------

    public function test_mark_paid_and_unmark(): void
    {
        $inv = AppFeeInvoiceService::issue($this->ownerWithNovemberFee(), self::PERIOD, $this->admin);

        $paid = AppFeeInvoiceService::markPaid($inv, now()->subDay(), 'Transfer BCA');
        $this->assertSame('paid', $paid->status);
        $this->assertSame('2026-12-02', $paid->paid_at->toDateString());
        $this->assertSame('Transfer BCA', $paid->paid_note);

        $back = AppFeeInvoiceService::unmarkPaid($paid);
        $this->assertSame('issued', $back->status);
        $this->assertNull($back->paid_at);
        $this->assertNull($back->paid_note);
    }

    public function test_paid_invoice_can_be_voided_and_future_payment_date_rejected(): void
    {
        $inv = AppFeeInvoiceService::issue($this->ownerWithNovemberFee(), self::PERIOD, $this->admin);

        try {
            AppFeeInvoiceService::markPaid($inv, now()->addDays(2), null);
            $this->fail('Tanggal bayar masa depan seharusnya ditolak');
        } catch (InvoiceException) {
            $this->assertSame('issued', $inv->fresh()->status);
        }

        AppFeeInvoiceService::markPaid($inv, today(), 'Transfer BCA');
        $void = AppFeeInvoiceService::void($inv->fresh(), 'Salah invoice');
        $this->assertSame('void', $void->status);
        $this->assertSame('Transfer BCA', $void->paid_note); // riwayat bayar tetap tersimpan
    }

    public function test_overdue_is_display_only_not_a_db_status(): void
    {
        $inv = AppFeeInvoiceService::issue($this->ownerWithNovemberFee(), self::PERIOD, $this->admin);
        $this->assertFalse($inv->fresh()->isOverdue()); // jatuh tempo 10 Des

        $this->travelTo(now()->setDate(2026, 12, 11)->setTime(9, 0));
        $fresh = $inv->fresh();
        $this->assertTrue($fresh->isOverdue());
        $this->assertSame('issued', $fresh->status);

        AppFeeInvoiceService::markPaid($fresh, today(), null);
        $this->assertFalse($inv->fresh()->isOverdue());
    }

    // ---- snapshot beku -------------------------------------------------------------

    public function test_issued_invoice_does_not_change_after_data_or_settings_change(): void
    {
        InvoiceSettings::save(['issuer_name' => 'Cemilan Qontas', 'bank_name' => 'BCA', 'account_number' => '111']);
        $owner = $this->ownerWithNovemberFee();
        $trip = Trip::where('owner_id', $owner->id)->orderBy('trip_date')->first();
        $inv = AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);
        $before = AppFeeInvoicePdf::dataFromInvoice($inv->fresh());

        $trip->delete(); // arsip trip → widget/AppFeeCalculator berubah
        InvoiceSettings::save(['issuer_name' => 'Nama Baru', 'bank_name' => 'Mandiri', 'account_number' => '999']);
        $owner->update(['business_name' => 'Nama Usaha Baru']);

        $this->assertSame(4, AppFeeInvoiceService::compute($owner, self::PERIOD)['total_mika'], 'data memang berubah');

        $after = AppFeeInvoicePdf::dataFromInvoice($inv->fresh());
        $this->assertEquals($before, $after);
        $this->assertSame(14, $after['total_mika']);
        $this->assertSame('BCA', $after['issuer']['bank_name']);
        $this->assertSame('Kuali Stories', $after['billed_to']['business']);
        $this->assertStringContainsString('KualiStories', AppFeeInvoicePdf::filename($after));
    }

    // ---- draft ---------------------------------------------------------------------

    public function test_draft_is_live_unnumbered_and_not_stored(): void
    {
        $owner = $this->owner();
        $this->sale($owner, '2026-12-01', 5); // bulan berjalan

        $draft = AppFeeInvoicePdf::dataForDraft($owner, '2026-12');
        $this->assertSame('draft', $draft['mode']);
        $this->assertNull($draft['number']);
        $this->assertSame(5, $draft['total_mika']);

        $this->sale($owner, '2026-12-02', 5, tripNo: 2);
        $this->assertSame(10, AppFeeInvoicePdf::dataForDraft($owner, '2026-12')['total_mika'], 'draft dihitung live');

        $res = $this->actingAs($this->admin)->get(route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => '2026-12']));
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('Invoice DRAFT KualiStories Desember 2026.pdf', $res->headers->get('content-disposition'));
        $this->assertSame(0, AppFeeInvoice::count());
    }

    public function test_draft_for_past_month_without_invoice_and_future_month_blocked(): void
    {
        $owner = $this->ownerWithNovemberFee();

        $this->actingAs($this->admin)
            ->get(route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => self::PERIOD]))
            ->assertOk();
        $this->actingAs($this->admin)
            ->get(route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => '2027-02']))
            ->assertStatus(422);
    }

    // ---- PDF -----------------------------------------------------------------------

    public function test_issued_pdf_200_pdf_content_type_and_filename(): void
    {
        $inv = AppFeeInvoiceService::issue($this->ownerWithNovemberFee(), self::PERIOD, $this->admin);

        $res = $this->actingAs($this->admin)->get(route('admin.invoice-pdf.show', $inv));
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $disp = $res->headers->get('content-disposition');
        $this->assertStringStartsWith('attachment', $disp);
        $this->assertStringContainsString('Invoice CQ-INV-202611-001 KualiStories November 2026.pdf', $disp);
    }

    public function test_owner_period_route_prefers_issued_invoice_over_draft(): void
    {
        $owner = $this->ownerWithNovemberFee();
        AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);

        $res = $this->actingAs($this->admin)->get(route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => self::PERIOD]));
        $this->assertStringContainsString('CQ-INV-202611-001', $res->headers->get('content-disposition'));
    }

    public function test_pdf_owner_param_must_be_an_owner(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.invoice-pdf.owner-period', ['owner' => $this->admin->id, 'period' => self::PERIOD]))
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->get(route('admin.invoice-pdf.owner-period', ['owner' => 999999, 'period' => self::PERIOD]))
            ->assertNotFound();
    }

    public function test_terbilang(): void
    {
        $this->assertSame('Empat ratus dua puluh ribu tiga ratus rupiah', Terbilang::rupiah(420300));
        $this->assertSame('Seribu rupiah', Terbilang::rupiah(1000));
        $this->assertSame('Seratus sebelas rupiah', Terbilang::rupiah(111));
        $this->assertSame('Dua juta lima ratus ribu rupiah', Terbilang::rupiah(2500000));
        $this->assertSame('Dua belas ribu rupiah', Terbilang::rupiah(12000));
        $this->assertSame('Nol rupiah', Terbilang::rupiah(0));
    }

    // ---- akses ---------------------------------------------------------------------

    public function test_owner_and_operator_get_403_everywhere_and_guest_redirected(): void
    {
        $owner = $this->ownerWithNovemberFee();
        $inv = AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => true, 'owner_id' => $owner->id]);

        $urls = [
            route('admin.invoice-pdf.show', $inv),
            route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => self::PERIOD]),
            AppFeeInvoiceResource::getUrl('index'),
            InvoiceSettingsPage::getUrl(),
        ];

        // Tamu dicek PERTAMA: request halaman Filament/Livewire meninggalkan Redirector Livewire
        // di container test yang membuat redirect middleware auth berikutnya 500 (artefak test).
        $this->get($urls[0])->assertRedirect();

        foreach ([$owner, $operator] as $user) {
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }
    }

    public function test_owner_cannot_use_invoice_ui_even_via_livewire(): void
    {
        $owner = $this->ownerWithNovemberFee();
        $this->actingAs($owner);

        $this->assertFalse(AppFeeInvoiceResource::canAccess());
        $this->assertFalse(AppFeeInvoiceResource::canViewAny());
        $this->assertFalse(InvoiceSettingsPage::canAccess());
        Livewire::test(ListAppFeeInvoices::class)->assertForbidden();
        Livewire::test(InvoiceSettingsPage::class)->assertForbidden();
    }

    public function test_invoice_data_never_leaks_into_owner_panel_pages(): void
    {
        $owner = $this->ownerWithNovemberFee();
        $inv = AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);

        foreach (['/owner/dashboard', '/owner/trips', '/owner/reports/monthly', '/owner/reports/periode', '/owner/settings'] as $path) {
            $body = $this->actingAs($owner)->get($path)->assertOk()->getContent();
            $this->assertStringNotContainsString($inv->number, $body, $path);
            $this->assertStringNotContainsString('Invoice Fee', $body, $path);
        }
    }

    // ---- UI super admin ------------------------------------------------------------

    public function test_super_admin_sees_list_and_filters_by_status(): void
    {
        $a = $this->ownerWithNovemberFee();
        $b = $this->owner(300, 'Warung B');
        $this->sale($b, '2026-11-04', 3);
        $invA = AppFeeInvoiceService::issue($a, self::PERIOD, $this->admin);
        $invB = AppFeeInvoiceService::issue($b, self::PERIOD, $this->admin);
        AppFeeInvoiceService::markPaid($invB, today(), null);

        Livewire::actingAs($this->admin)->test(ListAppFeeInvoices::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$invA, $invB])
            ->filterTable('status', 'paid')
            ->assertCanSeeTableRecords([$invB])
            ->assertCanNotSeeTableRecords([$invA]);
    }

    public function test_table_actions_issue_void_pay(): void
    {
        $owner = $this->ownerWithNovemberFee();

        Livewire::actingAs($this->admin)->test(ListAppFeeInvoices::class)
            ->callAction('issue', ['owner_id' => $owner->id, 'period' => self::PERIOD])
            ->assertHasNoActionErrors();
        $inv = AppFeeInvoice::firstOrFail();
        $this->assertSame('issued', $inv->status);

        $list = Livewire::actingAs($this->admin)->test(ListAppFeeInvoices::class);
        $list->callTableAction('markPaid', $inv, ['paid_at' => today()->toDateString(), 'paid_note' => 'Transfer BCA']);
        $this->assertSame('paid', $inv->fresh()->status);
        $list->callTableAction('unmarkPaid', $inv->fresh());
        $this->assertSame('issued', $inv->fresh()->status);

        $list->callTableAction('void', $inv->fresh(), ['reason' => ''])->assertHasTableActionErrors(['reason']); // wajib alasan
        $this->assertSame('issued', $inv->fresh()->status);
        $list->callTableAction('void', $inv->fresh(), ['reason' => 'Salah owner']);
        $this->assertSame('void', $inv->fresh()->status);

        $list->callTableAction('reissue', $inv->fresh());
        $this->assertSame(2, AppFeeInvoice::count());
        $this->assertSame('CQ-INV-202611-002', AppFeeInvoice::latest('id')->first()->number);
    }

    public function test_issue_action_for_current_month_creates_nothing(): void
    {
        $owner = $this->owner();
        $this->sale($owner, '2026-12-01', 5);

        Livewire::actingAs($this->admin)->test(ListAppFeeInvoices::class)
            ->callAction('issue', ['owner_id' => $owner->id, 'period' => '2026-12']);
        $this->assertSame(0, AppFeeInvoice::count());
    }

    public function test_settings_page_saves_and_validates(): void
    {
        Livewire::actingAs($this->admin)->test(InvoiceSettingsPage::class)
            ->assertOk()
            ->fillForm(['issuer_name' => 'CQ Test', 'bank_name' => 'BCA', 'account_number' => '1234 5678', 'account_holder' => 'Ismi', 'due_days' => 14])
            ->call('save')
            ->assertHasNoFormErrors();

        $s = InvoiceSettings::get();
        $this->assertSame('CQ Test', $s['issuer_name']);
        $this->assertSame(14, $s['due_days']);

        Livewire::actingAs($this->admin)->test(InvoiceSettingsPage::class)
            ->fillForm(['issuer_name' => '', 'account_number' => 'abc', 'due_days' => 0])
            ->call('save')
            ->assertHasFormErrors(['issuer_name', 'account_number', 'due_days']);
        $this->assertSame(14, InvoiceSettings::get()['due_days']); // tak tertimpa
    }

    public function test_zero_tariff_with_nonzero_mika_blocks_download_and_disables_widget(): void
    {
        $owner = $this->owner(0);
        $this->sale($owner, '2026-11-03', 10, rate: 0);
        $url = route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => self::PERIOD]);
        $this->actingAs($this->admin)->get($url)->assertStatus(422);
        Livewire::actingAs($this->admin)->test(AppFeeDailyWidget::class)
            ->set('month', self::PERIOD)->set('ownerId', $owner->id)
            ->assertSee('Fee Rp 0')->assertDontSeeHtml($url);
    }

    public function test_widget_can_download_snapshot_after_all_original_trips_archived(): void
    {
        $owner = $this->ownerWithNovemberFee();
        AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);
        Trip::where('owner_id', $owner->id)->get()->each->delete();
        $this->assertSame(0.0, AppFeeInvoiceService::compute($owner, self::PERIOD)['total_fee']);
        Livewire::actingAs($this->admin)->test(AppFeeDailyWidget::class)
            ->set('month', self::PERIOD)->set('ownerId', $owner->id)
            ->assertSeeHtml(route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => self::PERIOD]));
    }

    public function test_zero_fee_disables_modal_submit_but_frozen_invoice_stays_downloadable(): void
    {
        $empty = $this->owner(0);
        $this->sale($empty, '2026-11-03', 10, rate: 0);
        foreach (['draft', 'issue'] as $action) {
            $page = Livewire::actingAs($this->admin)->test(ListAppFeeInvoices::class)
                ->mountAction($action)
                ->setActionData(['owner_id' => $empty->id, 'period' => self::PERIOD])
                ->assertSee('tidak perlu invoice');
            $this->assertTrue($page->instance()->getMountedAction()->getModalSubmitAction()->isDisabled());
        }

        $owner = $this->ownerWithNovemberFee();
        AppFeeInvoiceService::issue($owner, self::PERIOD, $this->admin);
        Trip::where('owner_id', $owner->id)->get()->each->delete();
        $page = Livewire::actingAs($this->admin)->test(ListAppFeeInvoices::class)
            ->mountAction('draft')->setActionData(['owner_id' => $owner->id, 'period' => self::PERIOD]);
        $this->assertFalse($page->instance()->getMountedAction()->getModalSubmitAction()->isDisabled());
    }

    public function test_uniform_tariff_label_uses_actual_rates_not_daily_average(): void
    {
        $owner = $this->owner();
        $this->sale($owner, '2026-11-03', 10, rate: 200);
        $this->sale($owner, '2026-11-03', 10, rate: 400, tripNo: 2);
        $this->sale($owner, '2026-11-09', 10, rate: 300);
        $data = AppFeeInvoicePdf::dataForDraft($owner, self::PERIOD);
        $this->assertNull($data['uniform_rate'], 'Rp 300 rata-rata bukan tarif seragam');
        $this->assertSame(9000.0, $data['total_fee']);
        $single = $this->ownerWithNovemberFee();
        $this->assertSame(300.0, AppFeeInvoicePdf::dataForDraft($single, self::PERIOD)['uniform_rate']);
    }

    public function test_settings_and_list_recheck_role_on_livewire_update(): void
    {
        $list = Livewire::actingAs($this->admin)->test(ListAppFeeInvoices::class)->assertOk();
        $settings = Livewire::actingAs($this->admin)->test(InvoiceSettingsPage::class)->assertOk();
        $this->admin->update(['role' => 'owner']);
        $list->call('$refresh')->assertForbidden();
        $settings->call('save')->assertForbidden();
    }

    public function test_widget_download_button_only_with_single_owner_and_disabled_for_zero_fee(): void
    {
        $owner = $this->ownerWithNovemberFee();
        $empty = $this->owner(300, 'Kosong');

        Livewire::actingAs($this->admin)->test(AppFeeDailyWidget::class)
            ->set('month', self::PERIOD)->assertDontSee('Unduh Invoice')
            ->set('ownerId', $owner->id)->assertSee('Unduh Invoice')
            ->assertSeeHtml(route('admin.invoice-pdf.owner-period', ['owner' => $owner->id, 'period' => self::PERIOD]))
            ->set('ownerId', $empty->id)->assertSee('Unduh Invoice')
            ->assertDontSeeHtml(route('admin.invoice-pdf.owner-period', ['owner' => $empty->id, 'period' => self::PERIOD]))
            ->set('ownerId', $owner->id)->set('month', '2027-06')->assertDontSee('Unduh Invoice');
    }
}
