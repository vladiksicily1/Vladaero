<?php
namespace VladAero\Controllers\Api;

use VladAero\Core\{Controller, Session, Database};

/**
 * AircraftApiController - REST API для самолётов
 */
class AircraftApiController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $manufacturer = $this->query('manufacturer') ?? '';
        $category = $this->query('category') ?? '';
        $limit = min(100, max(1, (int)($this->query('limit') ?? 50)));

        $where = [];
        $params = [];
        if ($manufacturer) { $where[] = "manufacturer = :mfr"; $params['mfr'] = $manufacturer; }
        if ($category) { $where[] = "category = :cat"; $params['cat'] = $category; }
        $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $aircraft = $this->db->fetchAll(
            "SELECT * FROM {$prefix}aircraft {$whereStr} ORDER BY name LIMIT {$limit}",
            $params
        );
        $this->corsJson($aircraft);
    }

    public function show(string $typeCode)
    {
        $prefix = $this->db->prefix();
        $aircraft = $this->db->fetchOne(
            "SELECT * FROM {$prefix}aircraft WHERE type_code = :code",
            ['code' => strtoupper($typeCode)]
        );
        if (!$aircraft) { $this->corsJson(['error' => 'Not found'], 404); return; }
        $this->corsJson($aircraft);
    }

    private function corsJson(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
