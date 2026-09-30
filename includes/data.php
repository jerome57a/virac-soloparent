<?php
/**
 * Static reference content for the Overview tab (copy taken from the
 * design handoff). This is informational copy, not applicant data, so
 * it is kept in code rather than the database.
 */

function benefit_cards(): array
{
    return [
        ['icon' => '🕐', 'title' => 'Flexible Work Schedule', 'desc' => 'Employers are encouraged to provide flexible work arrangements to accommodate parental responsibilities.'],
        ['icon' => '🗓️', 'title' => 'Parental Leave (7 Days)', 'desc' => 'Seven (7) days of paid parental leave per year, available after at least one year of employment. Not convertible to cash.'],
        ['icon' => '🎓', 'title' => 'Educational Assistance', 'desc' => "Access to scholarships and educational grants for the solo parent's children through government programs."],
        ['icon' => '🛒', 'title' => 'Price Discounts', 'desc' => 'Discounts on medicines, groceries, and essential goods at drugstores, pharmacies, and grocery stores under RA 11861.'],
        ['icon' => '💵', 'title' => 'Monthly Cash Subsidy', 'desc' => 'Eligible indigent solo parents who earn minimum wage may receive an additional monthly cash subsidy of ₱1,000 under RA 11861.'],
        ['icon' => '🪪', 'title' => 'Valid Government ID', 'desc' => 'The Solo Parent ID is a valid government-issued identification card accepted in private and government transactions.'],
    ];
}

function eligibility_list(): array
{
    require_once __DIR__ . '/../services/EligibilityService.php';
    return EligibilityService::circumstances();
}

function required_doc_groups(): array
{
    return [
        ['title' => '1. Proof of Solo Parent Status', 'items' => [
            'If spouse is deceased: PSA Death Certificate of spouse',
            'If spouse is detained: Jail records or Certificate of Detention',
            'If spouse is incapacitated: Medical certificate from a public medical practitioner',
            'If legally separated / annulled: Court or church declaration of separation, annulment, or nullity',
            'If unwed: PSA CENOMAR (Certificate of No Marriage Record)',
        ]],
        ['title' => '2. Proof of Custody', 'items' => ['PSA Birth Certificate of child/children (original and photocopy)']],
        ['title' => '3. Proof of Residency', 'items' => ['Barangay Certificate stating your residency in Virac and your Solo Parent status (current year)']],
        ['title' => '4. Proof of Income', 'items' => ['Income Tax Return (ITR) or Certificate of Employment, if employed', 'Certificate of Indigency (if applicable)']],
        ['title' => '5. Other Requirements', 'items' => ['2 pcs. 2×2 ID photo (white background, recent)', 'Valid government-issued ID', 'Medical certificate of solo parent or child with special needs (if applicable)']],
    ];
}

function apply_steps_overview(): array
{
    return [
        ['n' => 1, 'title' => 'Check Eligibility', 'desc' => 'Use the Eligibility Checker to confirm you qualify'],
        ['n' => 2, 'title' => 'Fill Application', 'desc' => 'Complete the online form with your personal information'],
        ['n' => 3, 'title' => 'Upload Documents', 'desc' => 'Submit clear digital copies of all required documents'],
        ['n' => 4, 'title' => 'Track & Claim', 'desc' => 'Monitor your status and claim your ID when approved'],
    ];
}

function important_reminders(): array
{
    return [
        'The Solo Parent ID is valid for one year and must be renewed at the MSWDO upon expiration. The e-SoloParentID system will send you an SMS reminder 30 days before your ID expires.',
        'The Solo Parent ID is a valid government-issued ID accepted in private and government transactions.',
        'If you marry or re-marry, your Solo Parent ID may be revoked by the MSWDO.',
        'If your children are given up for adoption, the Solo Parent ID benefits will be terminated.',
        'The 7-day parental leave is available to holders who have rendered at least one year of service. Present your valid Solo Parent ID to your employer when filing for leave.',
        'The Solo Parent ID is free of charge. No fees are collected for application, issuance, or renewal.',
        'Application is subject to MSWDO review and approval. A Social Worker may conduct a home visit to verify your situation.',
    ];
}

/** News & Announcements shown on the portal's News & Announcements tab. */
function announcements_list(): array
{
    return [
        [
            'id' => 1,
            'title' => 'Solo Parent ID Renewal Schedule for Early 2027',
            'short_desc' => 'IDs expiring between January and June 2027 should be renewed at the MSWDO before the expiry date to avoid a lapse in benefits.',
            'category' => 'Renewal',
            'published_at' => '2026-09-22',
            'details' => "MSWDO Virac reminds all Solo Parent ID holders whose ID validity ends between January and June 2027 to begin their renewal at least 30 days before expiry.\n\nBring your current Solo Parent ID, a valid government ID, and any updated proof of solo parent status (if your circumstance has changed) to the MSWDO office, or start your renewal request online through the e-SoloParentID portal.\n\nIDs not renewed within 60 days after expiry will require a fresh application instead of a renewal.",
            'image' => null,
            'attachment' => null,
        ],
        [
            'id' => 2,
            'title' => 'e-SoloParentID Online Portal Now Accepting Applications',
            'short_desc' => 'Solo parents in Virac can now apply for their Solo Parent ID and track their application status entirely online.',
            'category' => 'Program Update',
            'published_at' => '2026-09-10',
            'details' => "MSWDO Virac has launched the e-SoloParentID online portal, allowing qualified solo parents to submit applications, upload requirements, and monitor their application status without visiting the office in person.\n\nWalk-in applications are still accepted for residents who prefer in-person assistance.",
            'image' => null,
            'attachment' => null,
        ],
        [
            'id' => 3,
            'title' => 'Reminder: Complete All Requirements Before Submitting',
            'short_desc' => 'Incomplete document uploads are the most common reason applications are returned for revision.',
            'category' => 'Application Reminder',
            'published_at' => '2026-09-05',
            'details' => "Before submitting your application, please double-check that you have uploaded all required documents for your solo parent category, including the Barangay Certificate, PSA Birth Certificate(s) of your children, a valid government ID, and a 2x2 ID photo, along with any category-specific requirement.\n\nApplications with missing documents will be marked \"For Revision\" and will need to be corrected and resubmitted, which can delay processing.",
            'image' => null,
            'attachment' => null,
        ],
        [
            'id' => 4,
            'title' => 'Monthly Cash Subsidy Disbursement Notice',
            'short_desc' => 'Eligible indigent solo parents may claim their monthly cash subsidy under RA 11861 starting next week.',
            'category' => 'Program Update',
            'published_at' => '2026-08-30',
            'details' => "Qualified indigent solo parents earning minimum wage may claim their monthly cash subsidy under RA 11861 at the MSWDO office. Please bring your valid Solo Parent ID and a valid government-issued ID.\n\nFor questions about your eligibility, contact the MSWDO office during regular business hours.",
            'image' => null,
            'attachment' => null,
        ],
        [
            'id' => 5,
            'title' => 'Scheduled System Maintenance',
            'short_desc' => 'The online portal will be briefly unavailable while we perform scheduled maintenance.',
            'category' => 'System Notice',
            'published_at' => '2026-08-20',
            'details' => "The e-SoloParentID portal will undergo scheduled maintenance to improve system performance. During this time, the online application and status-checking features may be temporarily unavailable.\n\nWe apologize for any inconvenience and encourage applicants to complete pending submissions beforehand.",
            'image' => null,
            'attachment' => null,
        ],
    ];
}

const CIVIL_STATUS_OPTIONS = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
