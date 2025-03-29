<?php
$baseUrl = 'https://api.hh.uz/vacancies';
$areasUrl = 'https://api.hh.uz/areas';
$searchKeyword = 'Android%20Developer';
$uzbekistanAreaId = '97';
$perPage = 100;
$maxPages = 20;

$filteredVacancies = [];
$uzbekistanAreas = [];

function makeApiRequest($url)
{
    echo "Making request to: $url\n";

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'VacancyFetcher/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // dev mode

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $httpCode !== 200) {
        $error = curl_error($ch);
        error_log("API request failed: $error, HTTP code: $httpCode");
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg());
        return null;
    }

    return $data;
}

function fetchUzbekistanAreas($areasUrl, $uzbekistanAreaId)
{
    $url = "$areasUrl/$uzbekistanAreaId";
    $areaData = makeApiRequest($url);

    if (!$areaData || !isset($areaData['areas'])) {
        echo "Failed to fetch areas for Uzbekistan\n";
        return [];
    }

    $areas = [$uzbekistanAreaId];

    foreach ($areaData['areas'] as $area) {
        $areas[] = $area['id'];
        echo "Found Uzbekistan area: " . $area['name'] . " (ID: " . $area['id'] . ")\n";
    }

    echo "Total Uzbekistan areas found: " . count($areas) . "\n";
    return $areas;
}

function isAreaInUzbekistan($areaId, $uzbekistanAreas)
{
    return in_array($areaId, $uzbekistanAreas);
}

function fetchVacancyDetails($vacancyId, $vacancyUrl)
{
    $vacancyData = makeApiRequest($vacancyUrl);

    if (!$vacancyData) {
        echo "Failed to fetch details for vacancy ID: $vacancyId\n";
        return null;
    }

    if (!isset($vacancyData['key_skills'])) {
        echo "No key skills found for vacancy ID: $vacancyId\n";
        return [
            'id' => $vacancyId,
            'url' => $vacancyUrl,
            'key_skills' => []
        ];
    }

    $keySkills = array_map(function ($skill) {
        return $skill['name'];
    }, $vacancyData['key_skills']);

    return [
        'id' => $vacancyId,
        'url' => $vacancyUrl,
        'key_skills' => $keySkills
    ];
}

function countSkills($vacancies)
{
    $skillCount = [];

    foreach ($vacancies as $vacancy) {
        if (isset($vacancy['key_skills']) && is_array($vacancy['key_skills'])) {
            foreach ($vacancy['key_skills'] as $skill) {
                if (!isset($skillCount[$skill])) {
                    $skillCount[$skill] = 0;
                }
                $skillCount[$skill]++;
            }
        }
    }

    arsort($skillCount);

    return $skillCount;
}

try {
    echo "Starting vacancy search for '$searchKeyword' in Uzbekistan areas\n";
    $startTime = microtime(true);

    $uzbekistanAreas = fetchUzbekistanAreas($areasUrl, $uzbekistanAreaId);

    if (empty($uzbekistanAreas)) {
        echo "No areas found for Uzbekistan. Cannot continue.\n";
        exit(1);
    }

    $totalFound = 0;
    $totalProcessed = 0;

    for ($page = 0; $page < $maxPages; $page++) {
        $searchUrl = "$baseUrl?text=$searchKeyword&per_page=$perPage&page=$page&area=$uzbekistanAreaId";

        $searchResults = makeApiRequest($searchUrl);

        if (!$searchResults || empty($searchResults['items'])) {
            echo "No more results found or error occurred on page $page\n";
            break;
        }

        if ($page === 0 && isset($searchResults['found'])) {
            $totalFound = $searchResults['found'];
            echo "Found {$totalFound} total vacancies in Uzbekistan, processing...\n";

            if ($totalFound == 0) {
                echo "No vacancies found in Uzbekistan regions. Exiting.\n";
                exit(0);
            }
        }

        foreach ($searchResults['items'] as $vacancy) {
            $totalProcessed++;

            if (!isset($vacancy['id'], $vacancy['area']['id'], $vacancy['url'])) {
                echo "Skipping vacancy with missing fields\n";
                continue;
            }

            $areaId = $vacancy['area']['id'];
            $areaName = $vacancy['area']['name'];

            echo "Processing vacancy ID: " . $vacancy['id'] . " with area ID: " . $areaId . " (" . $areaName . ")\n";

            $isUzbekistanArea = isAreaInUzbekistan($areaId, $uzbekistanAreas);

            if ($isUzbekistanArea) {
                echo "Found vacancy in Uzbekistan region: $areaName\n";

                $vacancyDetails = fetchVacancyDetails($vacancy['id'], $vacancy['url']);

                if ($vacancyDetails) {
                    $vacancyDetails['area'] = [
                        'id' => $areaId,
                        'name' => $areaName
                    ];

                    $filteredVacancies[] = $vacancyDetails;

                    $count = count($filteredVacancies);
                    $skillsCount = count($vacancyDetails['key_skills']);
                    echo "Added vacancy #{$count} (ID: {$vacancy['id']}) with $skillsCount key skills\n";
                }
            } else {
                echo "Skipping vacancy not in Uzbekistan: $areaName (ID: $areaId)\n";
            }
        }

        if ($page < $maxPages - 1 && $totalProcessed < $totalFound) {
            echo "Waiting before next request...\n";
            usleep(500000);
        } else {
            break;
        }
    }

    $executionTime = microtime(true) - $startTime;

    $resultCount = count($filteredVacancies);
    echo "\n==== RESULTS ====\n";
    echo "Processed $totalProcessed out of $totalFound vacancies in " . round($executionTime, 2) . " seconds\n";
    echo "Found $resultCount vacancies in Uzbekistan areas\n\n";

    $areaStats = [];
    foreach ($filteredVacancies as $vacancy) {
        $areaId = $vacancy['area']['id'];
        $areaName = $vacancy['area']['name'];
        if (!isset($areaStats[$areaId])) {
            $areaStats[$areaId] = [
                'name' => $areaName,
                'count' => 0
            ];
        }
        $areaStats[$areaId]['count']++;
    }

    echo "Vacancies by area:\n";
    foreach ($areaStats as $areaId => $stats) {
        echo "Area ID $areaId ({$stats['name']}): {$stats['count']} vacancies\n";
    }
    echo "\n";

    foreach ($filteredVacancies as $index => $vacancy) {
        $skillsList = implode(', ', $vacancy['key_skills']);

        echo "Vacancy #" . ($index + 1) . ":\n";
        echo "ID: {$vacancy['id']}\n";
        echo "URL: {$vacancy['url']}\n";
        echo "Area: {$vacancy['area']['name']} (ID: {$vacancy['area']['id']})\n";
        echo "Key Skills: $skillsList\n\n";
    }

    $skillsCount = countSkills($filteredVacancies);

    echo "\n==== SKILLS FREQUENCY ====\n";
    echo "Skills mentioned in all vacancies:\n";

    foreach ($skillsCount as $skill => $count) {
        echo "$skill keyskill $count times\n";
    }
    echo "\n";

    $outputFile = 'uz_vacancies_' . date('Y-m-d_His') . '.json';
    file_put_contents($outputFile, json_encode($filteredVacancies, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Results saved to $outputFile\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}