<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

class LogController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $level = $_GET['level'] ?? '';
        $where = '1=1';
        $params = [];

        if ($level && in_array($level, ['info', 'warning', 'error'])) {
            $where .= ' AND sl.level = ?';
            $params[] = $level;
        }

        $logs = $this->db->fetchAll("
            SELECT sl.*, a.name as agent_name
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            WHERE {$where}
            ORDER BY sl.created_at DESC
            LIMIT 100
        ", $params);

        $this->view('log/index', [
            'pageTitle' => 'Log',
            'logs' => $logs,
            'currentLevel' => $level,
        ]);
    }
}
