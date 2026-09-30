<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AppointmentService.php';

/** Dashboard "Appointment" section: pick (or reschedule to) an open slot. */
class AppointmentController extends Controller
{
    public function handle(array $user, array $applications): array
    {
        $service = new AppointmentService($this->pdo);
        $bookableApp = $service->findBookableApplication($applications);
        $appointmentError = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book_slot') {
            csrf_require();
            if (!$bookableApp) {
                $appointmentError = 'You do not have an application that currently needs an appointment.';
            } else {
                $appointmentError = $service->book($user, $bookableApp, (int) ($_POST['slot_id'] ?? 0)) ?? '';
                if ($appointmentError === '') {
                    $this->redirectTo('applicant/dashboard.php?section=appointment');
                }
            }
        }

        return ['view' => 'appointments/index.php', 'data' => [
            'appointmentError' => $appointmentError,
            'bookableApp' => $bookableApp,
            'currentAppointment' => $bookableApp && $bookableApp['interview_at'] ? $bookableApp : null,
            'openSlots' => $service->openSlots(),
        ]];
    }
}
