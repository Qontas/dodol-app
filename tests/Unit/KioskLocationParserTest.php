<?php

namespace Tests\Unit;

use App\Support\KioskLocationParser;
use Tests\TestCase;

class KioskLocationParserTest extends TestCase
{
    /**
     * Medan: lat ~3.5, lng ~98.6. Kalau lat/lng ketuker, hasilnya nyasar jauh
     * (lat ~98 tidak valid / lng ~3 nyasar ke lepas pantai Afrika Barat) — jadi
     * assert eksplisit lat < lng di sini menangkap regresi urutan yang terbalik.
     */
    private const MEDAN_LAT = 3.5896;

    private const MEDAN_LNG = 98.6739;

    public function test_parses_plain_coordinate_pair(): void
    {
        $result = KioskLocationParser::parse('3.5896, 98.6739');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(self::MEDAN_LAT, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(self::MEDAN_LNG, $result['lng'], 0.0001);
    }

    public function test_parses_plain_coordinate_pair_without_space(): void
    {
        $result = KioskLocationParser::parse('3.5896,98.6739');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(self::MEDAN_LAT, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(self::MEDAN_LNG, $result['lng'], 0.0001);
    }

    public function test_parses_negative_coordinates(): void
    {
        $result = KioskLocationParser::parse('-6.200000, 106.816666');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(-6.2, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(106.816666, $result['lng'], 0.0001);
    }

    public function test_parses_long_url_with_at_lat_lng(): void
    {
        $result = KioskLocationParser::parse(
            'https://www.google.com/maps/place/Kedai+Bunda/@3.5896,98.6739,17z/data=!3m1!4b1'
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(self::MEDAN_LAT, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(self::MEDAN_LNG, $result['lng'], 0.0001);
    }

    public function test_prefers_3d_4d_data_param_over_at_viewport_center(): void
    {
        // @ = pusat viewport peta (bisa geser kalau user scroll), !3d!4d = titik
        // marker/place asli — !3d!4d harus menang kalau keduanya ada & beda.
        $result = KioskLocationParser::parse(
            'https://www.google.com/maps/place/X/@3.0,98.0,17z/data=!4m5!3m4!8m2!3d3.5896!4d98.6739'
        );

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(self::MEDAN_LAT, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(self::MEDAN_LNG, $result['lng'], 0.0001);
    }

    public function test_parses_query_param_q(): void
    {
        $result = KioskLocationParser::parse('https://www.google.com/maps?q=3.5896,98.6739');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(self::MEDAN_LAT, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(self::MEDAN_LNG, $result['lng'], 0.0001);
    }

    public function test_parses_query_param_ll(): void
    {
        $result = KioskLocationParser::parse('https://maps.google.com/?ll=3.5896,98.6739');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(self::MEDAN_LAT, $result['lat'], 0.0001);
        $this->assertEqualsWithDelta(self::MEDAN_LNG, $result['lng'], 0.0001);
    }

    public function test_still_parses_dms_format(): void
    {
        $result = KioskLocationParser::parse('3° 35\' 22.56" N 98° 40\' 26.04" E');

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(self::MEDAN_LAT, $result['lat'], 0.001);
        $this->assertEqualsWithDelta(self::MEDAN_LNG, $result['lng'], 0.001);
    }

    public function test_returns_null_for_unrecognized_text(): void
    {
        $this->assertNull(KioskLocationParser::parse('Jl. Persatuan No. 33, Medan'));
    }

    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull(KioskLocationParser::parse('   '));
    }

    public function test_returns_null_for_short_link_not_yet_resolved(): void
    {
        // Link pendek belum ada koordinatnya di dalam URL itu sendiri — harus
        // di-resolve dulu lewat GoogleMapsShortLinkResolver, bukan tugas parser ini.
        $this->assertNull(KioskLocationParser::parse('https://maps.app.goo.gl/abc123XYZ'));
    }

    public function test_rejects_out_of_range_latitude(): void
    {
        $this->assertNull(KioskLocationParser::parse('999, 98.6739'));
    }

    public function test_rejects_out_of_range_longitude(): void
    {
        $this->assertNull(KioskLocationParser::parse('3.5896, 999'));
    }
}
