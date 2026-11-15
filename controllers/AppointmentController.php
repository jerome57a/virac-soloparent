<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../models/Document.php';

/** Dashboard "Appointment" section: shows the interview MSWD staff scheduled; the applicant may cancel it with enough notice. */
class AppointmentController extends Controller
{
    public function handle(array $user, array $applications): array
    {
        $service = new AppointmentService($this->pdo);
        $bookableApp = $service->findBookableApplication($applications);
        $appointmentError = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
            if (!$bookableApp) {
                $appointmentError = 'You do not have an application that currently needs an appointment.';
            } elseif ($action === 'cancel_appointment') {
                $appointmentError = $service->cancelByApplicant($user, $bookableApp) ?? '';
                if ($appointmentError === '') {
                    flash('Your appointment was cancelled. Contact MSWDO for a new schedule.');
                }
            }
            if ($appointmentError === '') {
                $this->redirectTo('applicant/dashboard.php?section=appointment');
            }
        }

        $appointment = $bookableApp ? (new Appointment($this->pdo))->interviewFor((int) $bookableApp['id']) : null;
        return ['view' => 'appointments/index.php', 'data' => [
            'appointmentError' => $appointmentError,
            'bookableApp' => $bookableApp,
            'appointment' => $appointment,
            'interviewRequirements' => $bookableApp ? (new Document($this->pdo))->checklistForReview((int) $bookableApp['id'], $bookableApp['category_id'] !== null ? (int) $bookableApp['category_id'] : null, $bookableApp['type'] === 'renewal') : [],
            'canChange' => $appointment && $appointment['status'] === 'BOOKED' && $service->changeableByApplicant($appointment['scheduled_at']),
            'minNoticeHours' => $service->minNoticeHours(),
        ]];
    }
}
