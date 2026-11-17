<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../services/InterviewBookingService.php';
require_once __DIR__ . '/../models/Document.php';

/**
 * Dashboard "Appointment" section: shows the interview MSWD staff scheduled; the applicant may cancel it with enough notice.
 * With interview date booking enabled (InterviewBookingService; off by default) the applicant can also book a staff-opened
 * date and, after cancelling, a replacement date.
 */
class AppointmentController extends Controller
{
    public function handle(array $user, array $applications): array
    {
        $service = new AppointmentService($this->pdo);
        $booking = new InterviewBookingService($this->pdo);
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
                    // The invitation reopens automatically while a reschedule is left (InterviewInvitationService); say so.
                    $options = $booking->options($user, $bookableApp);
                    flash($options !== null && $options['state'] === 'open'
                        ? 'Your appointment was cancelled. You can choose a new date below.'
                        : 'Your appointment was cancelled. Contact MSWDO for a new schedule.');
                }
            } elseif ($action === 'book_appointment') {
                $appointmentError = $booking->book($user, $bookableApp, is_string($_POST['interview_date'] ?? null) ? trim($_POST['interview_date']) : '') ?? '';
                if ($appointmentError === '') {
                    flash('Your interview date was booked.');
                }
            }
            if ($appointmentError === '') {
                $this->redirectTo('applicant/dashboard.php?section=appointment');
            }
        }

        $appointment = $bookableApp ? (new Appointment($this->pdo))->interviewFor((int) $bookableApp['id']) : null;
        $cancelWindow = $appointment && $appointment['status'] === 'BOOKED' ? $service->cancellationWindow($appointment) : null;
        return ['view' => 'appointments/index.php', 'data' => [
            'appointmentError' => $appointmentError,
            'bookableApp' => $bookableApp,
            'appointment' => $appointment,
            'interviewRequirements' => $bookableApp ? (new Document($this->pdo))->checklistForReview((int) $bookableApp['id'], $bookableApp['category_id'] !== null ? (int) $bookableApp['category_id'] : null, $bookableApp['type'] === 'renewal') : [],
            'canChange' => $cancelWindow !== null && $cancelWindow['allowed'],
            'cancelWindow' => $cancelWindow,
            'minNoticeHours' => $service->minNoticeHours(),
            'booking' => $bookableApp ? $booking->options($user, $bookableApp) : null,
        ]];
    }
}
