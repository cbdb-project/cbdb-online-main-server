<?php

namespace App\Mcp\Tools;

use App\Services\NameSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SearchPersonByNameTool extends Tool {
    public string $name = 'search_person_by_name';

    public string $description = 'Find CBDB persons by name — matches the main name AND courtesy names (字), style names (号), aliases, and other alternate names, the same way the CBDB homepage person search does. Prefer this over query_table on BIOG_MAIN when looking someone up by any form of their name, because BIOG_MAIN only holds the main name (字/号/别名 live in ALTNAME_DATA). Returns each matching person once (personid, main name, dynasty, index address) with the alternate-name forms that matched and their type. A numeric keyword is treated as an exact personid. Latin/pinyin keywords fall back to romanized-name columns.';

    public function schema(JsonSchema $schema): array {
        return [
            'keyword' => $schema->string()->description('Name to search: Chinese main name / 字 / 号 / alias, or a numeric personid.')->required(),
            'name_type_codes' => $schema->array()->description('Optional filter by alternate-name type code (ALTNAME_CODES): 4=字, 5=室名/别号, 3=别名/曾用名, 6=谥号, 7=行第, 9/10=小名/小字, 18=本姓… Omit to search all names incl. main name. Only affects Chinese matches.'),
            'dynasty' => $schema->integer()->description('Optional dynasty filter on BIOG_MAIN.c_dy (e.g. 19=明, 15=宋).'),
            'limit' => $schema->integer()->description('Max persons to return (1-100).')->default(20),
            'offset' => $schema->integer()->description('Persons to skip (pagination).')->default(0),
        ];
    }

    public function handle(Request $request): Response {
        $service = app(NameSearchService::class);

        $typeCodes = $request->get('name_type_codes', []);
        if (!is_array($typeCodes)) {
            $typeCodes = [];
        }

        $dynasty = $request->get('dynasty');
        $dynasty = ($dynasty === null || $dynasty === '') ? null : (int) $dynasty;

        return Response::text(json_encode(
            $service->searchPersons(
                (string) $request->get('keyword', ''),
                $dynasty,
                $typeCodes,
                (int) $request->get('limit', 20),
                (int) $request->get('offset', 0)
            ),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }
}
