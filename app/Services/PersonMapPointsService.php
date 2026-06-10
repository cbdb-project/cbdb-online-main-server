<?php

namespace App\Services;

use App\Models\BiogAddr;
use App\Models\BiogMain;
use App\Support\CoordinateValidator;
use Illuminate\Support\Facades\DB;

/**
 * 人物地圖點位服務
 *
 * 彙整某人物的所有地址（addresses）與官職地點（offices），標注每個地點是否
 * 具有效座標（可連結／可在地圖顯示）。同一份資料同時供：
 *  - personPoints 端點（只取 linkable 的點繪在地圖上）
 *  - addresses／offices 列表頁（依 linkable 決定 Place Name 是否渲染為連結）
 *
 * 設計見 docs/CHGIS_MAP_PLACE_LINK.md §5.2、§5.3。
 */
class PersonMapPointsService {
    /**
     * 該人物所有「有效座標」的點位（addresses + offices），供地圖繪製。
     *
     * @return array<int, array<string, mixed>>
     */
    public function points(int $personId): array {
        $points = [];

        foreach ($this->addressEntries($personId) as $entry) {
            if ($entry['linkable']) {
                $points[] = $entry;
            }
        }

        foreach ($this->officePlacesByPosting($personId) as $places) {
            foreach ($places as $entry) {
                if ($entry['linkable']) {
                    $points[] = $entry;
                }
            }
        }

        return $points;
    }

    /**
     * 該人物所有地址列（含無效座標），每筆標注 linkable。
     *
     * @return array<int, array<string, mixed>>
     */
    public function addressEntries(int $personId): array {
        $rows = BiogAddr::where('c_personid', $personId)
            ->with('addr')
            ->orderBy('c_sequence')
            ->get();

        $entries = [];
        foreach ($rows as $row) {
            $addr = $row->addr; // 孤兒外鍵時可能為 null
            $lon = $addr?->x_coord;
            $lat = $addr?->y_coord;
            $linkable = $addr !== null && CoordinateValidator::isValid($lon, $lat);
            $nameChn = $addr?->c_name_chn;
            $nameEn = $addr?->c_name;

            $entries[] = [
                'key' => 'addr:' . $row->c_addr_id . ':' . $row->c_addr_type . ':' . $row->c_sequence,
                'source' => 'address',
                'addr_id' => (int) $row->c_addr_id,
                'name_chn' => $nameChn,
                'name' => $nameEn,
                'lon' => $linkable ? (float) $lon : null,
                'lat' => $linkable ? (float) $lat : null,
                'linkable' => $linkable,
                'first_year' => $row->c_firstyear,
                'last_year' => $row->c_lastyear,
                'label' => $this->displayName($nameChn, $nameEn, ''),
            ];
        }

        return $entries;
    }

    /**
     * 該人物官職地點，依官職任命（c_office_id + c_posting_id）分組（含無效座標）。
     *
     * 注意：c_posting_id 在 POSTED_TO_OFFICE_DATA 並非全域唯一（主鍵為
     * (c_office_id, c_posting_id)），故分組鍵與點位 key 都必須帶 c_office_id，
     * 避免不同官職誤合併或官名張冠李戴。分組鍵格式為 "{c_office_id}:{c_posting_id}"。
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function officePlacesByPosting(int $personId): array {
        if (!BiogMain::whereKey($personId)->exists()) {
            return [];
        }

        $officeByKey = $this->officeMetadataByPosting($personId);

        $grouped = [];
        foreach ($this->officeAddressRows($personId) as $row) {
            $officeId = $row->c_office_id;
            $postingId = $row->c_posting_id;
            $groupKey = $this->postingKey($officeId, $postingId);
            $lon = $row->x_coord;
            $lat = $row->y_coord;
            $linkable = CoordinateValidator::isValid($lon, $lat);
            $info = $officeByKey[$groupKey] ?? [];
            $officeName = $this->displayName(
                $info['office_chn'] ?? null,
                $info['office_name'] ?? null,
                'office_id:' . $officeId
            );
            $placeName = $this->displayName($row->c_name_chn, $row->c_name, 'addr_id:' . $row->c_addr_id);

            $grouped[$groupKey][] = [
                'key' => 'office:' . $officeId . ':' . $postingId . ':' . $row->c_addr_id,
                'source' => 'office',
                'addr_id' => (int) $row->c_addr_id,
                'name_chn' => $row->c_name_chn,
                'name' => $row->c_name,
                'lon' => $linkable ? (float) $lon : null,
                'lat' => $linkable ? (float) $lat : null,
                'linkable' => $linkable,
                'first_year' => $info['first_year'] ?? null,
                'last_year' => $info['last_year'] ?? null,
                'label' => $officeName ? ($officeName . ' · ' . $placeName) : $placeName,
            ];
        }

        // 每組內依 addr_id 穩定排序，確保多地點時輸出順序一致
        foreach ($grouped as &$places) {
            usort($places, static fn ($a, $b) => $a['addr_id'] <=> $b['addr_id']);
        }
        unset($places);

        return $grouped;
    }

    /**
     * 官職任命的複合分組鍵（"{c_office_id}:{c_posting_id}"）。
     */
    public function postingKey(int|string|null $officeId, int|string|null $postingId): string {
        return $officeId . ':' . $postingId;
    }

    /**
     * @return array<string, array{office_chn:?string, office_name:?string, first_year:mixed, last_year:mixed}>
     */
    private function officeMetadataByPosting(int $personId): array {
        $rows = DB::table('POSTED_TO_OFFICE_DATA as posting')
            ->leftJoin('OFFICE_CODES as office', 'office.c_office_id', '=', 'posting.c_office_id')
            ->where('posting.c_personid', $personId)
            ->orderByRaw('CASE WHEN posting.c_sequence IS NULL THEN 1 ELSE 0 END')
            ->orderBy('posting.c_sequence')
            ->select([
                'posting.c_office_id',
                'posting.c_posting_id',
                'posting.c_firstyear',
                'posting.c_lastyear',
                'office.c_office_chn',
                'office.c_office_trans',
            ])
            ->get();

        $byKey = [];
        foreach ($rows as $row) {
            $key = $this->postingKey($row->c_office_id, $row->c_posting_id);
            $byKey[$key] = [
                'office_chn' => $row->c_office_chn,
                'office_name' => $row->c_office_trans,
                'first_year' => $row->c_firstyear,
                'last_year' => $row->c_lastyear,
            ];
        }

        return $byKey;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function officeAddressRows(int $personId) {
        return DB::table('POSTED_TO_ADDR_DATA as posting_addr')
            ->leftJoin('ADDR_CODES as addr', 'addr.c_addr_id', '=', 'posting_addr.c_addr_id')
            ->where('posting_addr.c_personid', $personId)
            ->where('posting_addr.c_office_id', '!=', -1)
            ->orderBy('posting_addr.c_office_id')
            ->orderBy('posting_addr.c_posting_id')
            ->orderBy('posting_addr.c_addr_id')
            ->select([
                'posting_addr.c_office_id',
                'posting_addr.c_posting_id',
                'posting_addr.c_addr_id',
                'addr.c_name_chn',
                'addr.c_name',
                'addr.x_coord',
                'addr.y_coord',
            ])
            ->get();
    }

    private function displayName(?string $nameChn, ?string $name, string $fallback): string {
        $nameChn = trim((string) ($nameChn ?? ''));
        if ($nameChn !== '') {
            return $nameChn;
        }

        $name = trim((string) ($name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return $fallback;
    }
}
