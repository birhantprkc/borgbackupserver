<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

class LogController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $level = $_GET['level'] ?? '';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Filter logs by accessible agents
        [$agentWhere, $agentParams] = $this->getAgentWhereClause('a');

        $where = "(sl.agent_id IS NULL OR {$agentWhere})";
        $params = $agentParams;

        if ($level && in_array($level, ['info', 'warning', 'error'])) {
            $where .= ' AND sl.level = ?';
            $params[] = $level;
        }

        // Get total count for pagination
        $countRow = $this->db->fetchOne("
            SELECT COUNT(*) as cnt
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            WHERE {$where}
        ", $params);
        $total = (int) ($countRow['cnt'] ?? 0);
        $pages = max(1, (int) ceil($total / $perPage));

        $logs = $this->db->fetchAll("
            SELECT sl.*, a.name as agent_name
            FROM server_log sl
            LEFT JOIN agents a ON a.id = sl.agent_id
            WHERE {$where}
            ORDER BY sl.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ", $params);

        $this->view('log/index', [
            'pageTitle' => 'Log',
            'logs' => $logs,
            'currentLevel' => $level,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }
}
