<?php

namespace Tests\Feature;

use App\Http\Controllers\BasicInformationOfficesController;
use App\Repositories\BiogMainRepository;
use App\Repositories\OperationRepository;
use App\Repositories\ToolsRepository;
use App\Services\PersonMapPointsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BasicInformationOfficesControllerTest extends TestCase {
    #[Test]
    public function testSerialAddrFallsBackToEnglishAndUsesOfficePostingCompositeKey(): void {
        $controller = new class (
            $this->createMock(BiogMainRepository::class),
            $this->createMock(OperationRepository::class),
            $this->createMock(ToolsRepository::class)
        ) extends BasicInformationOfficesController {
            public function callSerialAddr(array $array, PersonMapPointsService $mapPoints): array {
                return $this->serialAddr($array, $mapPoints);
            }
        };

        $result = $controller->callSerialAddr([
            [
                'c_name_chn' => '',
                'c_name' => 'English Place',
                'pivot' => ['c_office_id' => 10, 'c_posting_id' => 20],
            ],
            [
                'c_name_chn' => '中文地名',
                'c_name' => 'Ignored English Name',
                'pivot' => ['c_office_id' => 10, 'c_posting_id' => 20],
            ],
            [
                'c_name_chn' => '另一地名',
                'c_name' => 'Another Place',
                'pivot' => ['c_office_id' => 11, 'c_posting_id' => 20],
            ],
            [
                'c_name_chn' => '',
                'c_name' => '',
                'pivot' => ['c_office_id' => 12, 'c_posting_id' => 30],
            ],
        ], app(PersonMapPointsService::class));

        $this->assertSame([
            '10:20' => ['English Place', '中文地名'],
            '11:20' => ['另一地名'],
        ], $result);
    }
}
