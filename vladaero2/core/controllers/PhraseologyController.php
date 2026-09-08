<?php
namespace VladAero\Controllers;

use VladAero\Core\{Controller, Session, Database};

/**
 * PhraseologyController — Справочник фразеологии радиообмена
 */
class PhraseologyController extends Controller
{
    public function index()
    {
        $prefix = $this->db->prefix();
        $phase = $this->query('phase') ?? '';

        $where = '';
        $params = [];
        if ($phase) {
            $where = 'WHERE p.phase = :phase';
            $params['phase'] = $phase;
        }

        $phrases = $this->db->fetchAll(
            "SELECT p.* FROM {$prefix}phraseology p {$where} ORDER BY p.phase, p.sort_order",
            $params
        );

        $phases = $this->db->fetchAll(
            "SELECT DISTINCT phase FROM {$prefix}phraseology ORDER BY phase"
        );

        $this->view('pages.phraseology.index', compact('phrases', 'phases', 'phase'));
    }
}
