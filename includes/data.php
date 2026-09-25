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
    return [
        ['title' => 'Deceased spouse', 'desc' => 'The other parent has passed away and you are solely responsible for your child/children.'],
        ['title' => 'Abandoned or separated (6+ months)', 'desc' => 'Your spouse has been absent for at least six (6) months and you bear sole parental responsibility.'],
        ['title' => 'Spouse detained or imprisoned (3+ months)', 'desc' => 'Your spouse is serving a sentence and you are the sole caregiver of your child/children.'],
        ['title' => 'Spouse physically or mentally incapacitated', 'desc' => 'Your spouse is unable to fulfill parental responsibilities due to a certified medical condition.'],
        ['title' => 'Legal separation, annulment, or nullity of marriage', 'desc' => 'As decreed by a court or church, with you entrusted with the custody of the child/children.'],
        ['title' => 'Unwed parent', 'desc' => 'An unmarried mother or father who has chosen to keep and raise their child/children.'],
        ['title' => 'Legal guardian', 'desc' => 'Any person who solely provides parental care and support to a minor child or children.'],
        ['title' => 'Spouse is an OFW (RA 11861)', 'desc' => 'Spouse is a low-skilled or semi-skilled overseas Filipino worker who has been abroad for an uninterrupted period of 12 months or more.'],
        ['title' => 'Victim of rape or crimes against chastity', 'desc' => 'A woman who gives birth as a result of rape or similar crimes and keeps and raises the child.'],
    ];
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

const CIVIL_STATUS_OPTIONS = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];
