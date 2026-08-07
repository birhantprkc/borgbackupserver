<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

/**
 * Team notes on entities (#334): clients, repositories, plugin configs,
 * and backup plans each carry an optional free-text note describing what
 * they're for. One endpoint saves all of them; access is scoped through
 * the owning agent so users can only annotate what they can see.
 */
class NotesController extends Controller
{
    /** entity type => [table, how to resolve the owning agent id] */
    private const ENTITIES = [
        'client' => ['table' => 'agents',         'agentCol' => 'id'],
        'repo'   => ['table' => 'repositories',   'agentCol' => 'agent_id'],
        'plugin' => ['table' => 'plugin_configs', 'agentCol' => 'agent_id'],
        'plan'   => ['table' => 'backup_plans',   'agentCol' => 'agent_id'],
    ];

    public function save(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $entity = $_POST['entity'] ?? '';
        $id = (int) ($_POST['id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        if (!isset(self::ENTITIES[$entity]) || $id <= 0) {
            $this->json(['status' => 'error', 'error' => 'Invalid entity.'], 400);
            return;
        }

        $meta = self::ENTITIES[$entity];
        $row = $this->db->fetchOne("SELECT id, {$meta['agentCol']} AS agent_id FROM {$meta['table']} WHERE id = ?", [$id]);
        if (!$row || !$this->canAccessAgent((int) $row['agent_id'])) {
            $this->json(['status' => 'error', 'error' => 'Not found.'], 404);
            return;
        }

        // Cap length — this is a note, not a wiki
        if (mb_strlen($notes) > 5000) {
            $this->json(['status' => 'error', 'error' => 'Note too long (max 5000 characters).'], 422);
            return;
        }

        $this->db->update($meta['table'], [
            'notes' => $notes !== '' ? $notes : null,
            'notes_updated_by' => $notes !== '' ? ($_SESSION['user_id'] ?? null) : null,
            'notes_updated_at' => $notes !== '' ? date('Y-m-d H:i:s') : null,
        ], 'id = ?', [$id]);

        $this->json(['status' => 'ok']);
    }
}
