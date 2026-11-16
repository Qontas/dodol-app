<?php

namespace Tests\Feature\Owner;

use App\Models\Cluster;
use App\Models\Kiosk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Navigasi halaman Laporan Kedai per peran (bug prod 16 Nov 2026: super admin 404 + sidebar owner). */
class KioskInsightNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> href internal (path) pada HTML, tanpa fragmen/skema luar. */
    private function internalLinks(string $html): array
    {
        preg_match_all('~<a\b[^>]*\bhref="([^"]+)"~i', $html, $m);
        $links = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href);
            if (str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }
            $parts = parse_url($href);
            if (isset($parts['host']) && $parts['host'] !== 'localhost') {
                continue;
            }
            $links[] = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        return array_values(array_unique($links));
    }

    private function assertAllLinksResolve(User $user, string $startUrl): void
    {
        $response = $this->actingAs($user)->get($startUrl);
        $response->assertOk();
        $checked = 0;
        foreach ($this->internalLinks($response->getContent()) as $link) {
            if (preg_match('~/(logout|download)\b|^/storage/~', $link)) {
                continue; // logout = POST; download butuh export nyata.
            }
            $r = $this->actingAs($user)->get($link);
            // Follow legitimate role redirects, then require an actual usable page (not only a redirect).
            for ($i = 0; $r->isRedirection() && $i < 5; $i++) {
                $r = $this->actingAs($user)->get($r->headers->get('Location'));
            }
            $this->assertSame(200, $r->getStatusCode(), "link {$link} → {$r->getStatusCode()} (halaman {$startUrl})");
            $checked++;
        }
        $this->assertGreaterThan(3, $checked);
    }

    public function test_super_admin_sees_laporan_kedai_in_admin_sidebar_and_page_has_no_owner_sidebar(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $res = $this->actingAs($admin)->get('/admin/laporan-kedai');
        $res->assertOk()->assertSee('Export Excel + Foto')->assertSee('Semua owner');
        $res->assertSee('Laporan Kedai')->assertSee('Invoice Fee');
        $res->assertDontSee('Panel Owner');
        $res->assertDontSee('Manajemen Kios');
        $res->assertSee(route('filament.admin.pages.dashboard'), false);
        $res->assertDontSee('/admin/kiosks', false);
        // Menu juga muncul di dashboard admin.
        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('/admin/laporan-kedai', false);
    }

    public function test_super_admin_opening_owner_url_is_sent_to_admin_panel(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->actingAs($admin)->get('/kiosk-insights')->assertRedirect(route('filament.admin.pages.laporan-kedai'));
    }

    public function test_owner_stays_in_owner_layout_with_back_link_to_kiosk_management(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $res = $this->actingAs($owner)->get('/kiosk-insights');
        $res->assertOk()->assertSee('Panel Owner')->assertSee('← Manajemen Kios');
        $res->assertSee(route('filament.owner.resources.kiosks.index'), false);
        $this->actingAs($owner)->get(route('filament.owner.resources.kiosks.index'))->assertOk();
    }

    public function test_owner_cannot_open_admin_report_page_and_operator_cannot_open_either(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $operator = User::factory()->create(['role' => 'operator', 'owner_id' => $owner->id, 'is_active' => true]);
        $this->actingAs($owner)->get('/admin/laporan-kedai')->assertForbidden();
        $this->actingAs($operator)->get('/admin/laporan-kedai')->assertForbidden();
        $this->actingAs($operator)->get('/kiosk-insights')->assertForbidden();
    }

    // Satu test per peran: panel aktif Filament adalah state statis per proses, jadi memeriksa dua peran
    // berurutan dalam satu test bisa mencampur sidebar (artefak test, bukan perilaku produksi).
    public function test_every_link_on_the_report_page_resolves_for_owner(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'is_active' => true]);
        $cluster = Cluster::factory()->create(['owner_id' => $owner->id]);
        Kiosk::factory()->create(['cluster_id' => $cluster->id]);

        $this->assertAllLinksResolve($owner, '/kiosk-insights');
    }

    public function test_every_link_on_the_report_page_resolves_for_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->assertAllLinksResolve($admin, '/admin/laporan-kedai');
    }
}
