<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\ClientProfileService;

class ClientProfileController extends Controller
{
    private function service(): ClientProfileService
    {
        return new ClientProfileService();
    }

    /** Fields a profile form submits, with the shape each has to end up in. */
    private function readForm(): array
    {
        $times = trim($_POST['times'] ?? '02:00');
        $frequency = $_POST['frequency'] ?? 'daily';

        $data = [
            'name'        => trim($_POST['name'] ?? ''),
            'description' => substr(trim($_POST['description'] ?? ''), 0, 255),
            'template_id' => !empty($_POST['template_id']) ? (int) $_POST['template_id'] : null,
            'frequency'   => in_array($frequency, ['hourly', 'daily', 'weekly', 'monthly'], true) ? $frequency : 'daily',
            'times'       => $times !== '' ? substr($times, 0, 255) : '02:00',
            'day_of_week'  => ($frequency === 'weekly' && $_POST['day_of_week'] !== '') ? (int) $_POST['day_of_week'] : null,
            'day_of_month' => ($frequency === 'monthly' && $_POST['day_of_month'] !== '') ? substr((string) $_POST['day_of_month'], 0, 20) : null,
        ];

        foreach (['minutes', 'hours', 'days', 'weeks', 'months', 'years'] as $unit) {
            // -1 is borg's "keep everything at this interval", so the floor is
            // -1 rather than 0 (#386).
            $data['prune_' . $unit] = max(-1, (int) ($_POST['prune_' . $unit] ?? 0));
        }

        // Blank means "follow the server setting", which is different from zero.
        foreach (['auto_retry_max_attempts', 'job_offline_grace_minutes', 'auto_retry_backoff_minutes', 'backup_overdue_hours'] as $f) {
            $raw = $_POST[$f] ?? '';
            $data[$f] = ($raw === '' || $raw === null) ? null : max(0, (int) $raw);
        }

        return $data;
    }

    public function store(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $data = $this->readForm();
        if ($data['name'] === '') {
            $this->flash('danger', 'Profile name is required.');
            $this->redirect('/settings?tab=profiles');
        }

        try {
            $this->db->insert('client_profiles', $data);
        } catch (\Exception $e) {
            $this->flash('danger', "A profile named \"{$data['name']}\" already exists.");
            $this->redirect('/settings?tab=profiles');
        }

        $this->flash('success', "Profile \"{$data['name']}\" created. New clients can be assigned to it from the Add Client page.");
        $this->redirect('/settings?tab=profiles');
    }

    public function update(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $profile = $this->service()->find($id);
        if (!$profile) {
            $this->flash('danger', 'Profile not found.');
            $this->redirect('/settings?tab=profiles');
        }

        $data = $this->readForm();
        if ($data['name'] === '') {
            $this->flash('danger', 'Profile name is required.');
            $this->redirect('/settings?tab=profiles');
        }

        try {
            $this->db->update('client_profiles', $data, 'id = ?', [$id]);
        } catch (\Exception $e) {
            $this->flash('danger', "A profile named \"{$data['name']}\" already exists.");
            $this->redirect('/settings?tab=profiles');
        }

        $this->flash('success', "Profile \"{$data['name']}\" saved. Existing clients are unchanged — use Apply to Clients to push this down to them.");
        $this->redirect('/settings?tab=profiles');
    }

    public function delete(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $profile = $this->service()->find($id);
        if (!$profile) {
            $this->flash('danger', 'Profile not found.');
            $this->redirect('/settings?tab=profiles');
        }
        if (!empty($profile['is_default'])) {
            $this->flash('danger', 'The default profile cannot be deleted — it is where clients land when they have no profile of their own.');
            $this->redirect('/settings?tab=profiles');
        }

        // Clients move to the default rather than being left with none, so the
        // failure settings keep resolving to something.
        $defaultId = $this->service()->defaultProfileId();
        $this->db->query("UPDATE agents SET client_profile_id = ? WHERE client_profile_id = ?", [$defaultId, $id]);
        $this->db->delete('client_profiles', 'id = ?', [$id]);

        $this->flash('success', "Profile \"{$profile['name']}\" deleted. Its clients moved to the default profile.");
        $this->redirect('/settings?tab=profiles');
    }

    /**
     * POST /settings/profiles/{id}/apply — push the profile down onto every
     * client already in it. Confirmed in the browser first; this is the one
     * action that overwrites work someone may have tuned by hand.
     */
    public function apply(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = $this->service();
        $profile = $service->find($id);
        if (!$profile) {
            $this->flash('danger', 'Profile not found.');
            $this->redirect('/settings?tab=profiles');
        }

        $result = $service->applyToClients($id);

        if ($result['clients'] === 0) {
            $this->flash('warning', "No clients are assigned to \"{$profile['name']}\", so nothing changed.");
            $this->redirect('/settings?tab=profiles');
        }

        $this->db->insert('server_log', [
            'level' => 'warning',
            'message' => "Profile \"{$profile['name']}\" applied to {$result['clients']} client(s): "
                       . "{$result['plans']} backup plan(s) and {$result['schedules']} schedule(s) overwritten.",
        ]);

        $this->flash('success', sprintf(
            'Applied "%s" to %d client%s — %d plan%s and %d schedule%s updated.',
            $profile['name'],
            $result['clients'], $result['clients'] === 1 ? '' : 's',
            $result['plans'], $result['plans'] === 1 ? '' : 's',
            $result['schedules'], $result['schedules'] === 1 ? '' : 's'
        ));
        $this->redirect('/settings?tab=profiles');
    }

    /**
     * GET /api/client-profiles/{id} — used by the impact dialog.
     *
     * Not named json(): the base controller's json() is the response helper,
     * and an incompatible override of it fatals before routing gets a chance.
     */
    public function show(int $id): void
    {
        $this->requireAuth();
        $service = $this->service();
        $profile = $service->find($id);
        if (!$profile) {
            $this->json(['error' => 'Not found'], 404);
        }
        $profile['impact'] = $service->applyImpact($id);
        $this->json($profile);
    }
}
